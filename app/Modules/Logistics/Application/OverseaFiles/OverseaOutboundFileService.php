<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use App\Modules\Logistics\Infrastructure\Models\Shipment;
use DomainException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OverseaOutboundFileService
{
    public function __construct(
        private readonly Wms64OutboundExporter $wms,
        private readonly MorePlusOutboundExporter $morePlus,
        private readonly CanadaOutboundExporter $canada,
        private readonly ProviderXlsxWriter $xlsx,
    ) {}

    /**
     * Run the exact provider row validation without writing or changing any Shipment.
     * Invalid rows are excluded from the provider-facing sheet, not silently altered.
     *
     * @param iterable<Shipment> $shipments
     * @return array{valid:list<Shipment>,issues:list<array{order_id:int|null,order_number:string,reason:string}>,fingerprint:string}
     */
    public function inspect(string $warehouseKey, iterable $shipments): array
    {
        $valid = [];
        $issues = [];
        $snapshots = [];
        foreach ($shipments as $shipment) {
            $order = $shipment->order;
            $snapshots[] = [
                $shipment->getAttributes(),
                $order?->getAttributes(),
                $order?->address?->getAttributes(),
                $order?->items?->map(fn ($item) => $item->getAttributes())->all(),
            ];
            try {
                match ($warehouseKey) {
                    'us-ny', 'us-3sm', 'de-tianyun' => $this->wms->validate($warehouseKey, $shipment),
                    'us-moreplus' => $this->morePlus->validate($shipment),
                    'ca' => $this->canada->validate($shipment),
                    default => abort(404, '未知海外仓。'),
                };
                $valid[] = $shipment;
            } catch (DomainException|InvalidArgumentException|HttpExceptionInterface $e) {
                if ($e instanceof HttpExceptionInterface && $e->getStatusCode() !== 422) {
                    throw $e;
                }
                $issues[] = [
                    'order_id' => $order?->id,
                    'order_number' => (string) ($order?->erp_order_id ?: '未知订单'),
                    'reason' => $e->getMessage() ?: '仓库模板字段校验失败',
                ];
            }
        }

        return [
            'valid' => $valid,
            'issues' => $issues,
            'fingerprint' => hash('sha256', json_encode($snapshots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Keep provider-compliant orders in the provider sheet. Put every skipped
     * order and its actual validation reason into a separate non-provider sheet.
     *
     * @param list<Shipment> $valid
     * @param list<array{order_id:int|null,order_number:string,reason:string}> $issues
     * @return array{filename:string,bytes:string}
     */
    public function exportReviewed(string $warehouseKey, array $valid, array $issues): array
    {
        $file = $this->export($warehouseKey, $valid);
        if ($issues !== []) {
            $file['bytes'] = $this->xlsx->appendIssuesSheet($file['bytes'], $issues);
        }

        return $file;
    }

    /** @param iterable<Shipment> $shipments @return array{filename:string,bytes:string} */
    public function export(string $warehouseKey, iterable $shipments): array
    {
        $bytes = match ($warehouseKey) {
            'us-ny', 'us-3sm', 'de-tianyun' => $this->wms->export($warehouseKey, $shipments),
            'us-moreplus' => $this->morePlus->export($shipments),
            'ca' => $this->canada->export($shipments),
            default => abort(404, '未知海外仓。'),
        };

        return [
            'filename' => sprintf('007-%s-orders-%s.xlsx', $warehouseKey, now()->format('ymd')),
            'bytes' => $bytes,
        ];
    }
}
