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
                $message = $e->getMessage() ?: '仓库模板字段校验失败';
                $missing = $this->missingFields($warehouseKey, $shipment);
                foreach ($missing as $field) {
                    if (! str_contains($message, $field)) {
                        $message .= '；缺少'.$field;
                    }
                }
                $issues[] = [
                    'order_id' => $order?->id,
                    'order_number' => (string) ($order?->erp_order_id ?: '未知订单'),
                    'reason' => $message,
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
     * Add known missing recipient fields to the provider's first failure.
     * The exporter remains the source of truth for provider-specific validity;
     * this only improves the human diagnostic text without changing validation.
     *
     * @return list<string>
     */
    private function missingFields(string $warehouseKey, Shipment $shipment): array
    {
        $address = $shipment->order?->address;
        if (! $address) {
            return [];
        }
        $required = [
            'country' => '国家',
            'recipient_name' => '收件人',
            'phone' => '电话',
            'city' => '城市',
            'postal_code' => '邮编',
        ];
        if ($warehouseKey === 'ca') {
            if (trim((string) $address->address_line_1) === '' && trim((string) $address->address_line_2) === '') {
                $required['address_line_1'] = '街道';
            }
        } else {
            $required['address_line_1'] = '地址1';
        }
        if (in_array(strtoupper(trim((string) $address->country)), ['US', 'CA'], true)) {
            $required['province_state'] = '州/省';
        }
        $missing = [];
        foreach ($required as $key => $field) {
            if ($key === 'address_line_1' && $warehouseKey === 'ca') {
                $missing[] = $field;
                continue;
            }
            if (trim((string) $address->{$key}) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Only provider-valid rows are placed in the warehouse XLSX. Preserve the
     * exact one-sheet provider template; export issue data separately in UI.
     *
     * @param list<Shipment> $valid
     * @return array{filename:string,bytes:string}
     */
    public function exportReviewed(string $warehouseKey, array $valid): array
    {
        return $this->export($warehouseKey, $valid);
    }

    /**
     * Full operator-review draft: all actual order fields and all SKU rows.
     * This is intentionally NOT a provider template. No data is invented,
     * truncated, approved, or sent to a warehouse by exporting it.
     *
     * @param iterable<Shipment> $shipments
     * @param list<array{order_id:int|null,order_number:string,reason:string}> $issues
     * @return array{filename:string,bytes:string}
     */
    public function exportReviewDraft(string $warehouseKey, iterable $shipments, array $issues): array
    {
        $reasonById = [];
        foreach ($issues as $issue) {
            $reasonById[(string) ($issue['order_id'] ?? '')] = $issue['reason'];
        }
        $rows = [];
        foreach ($shipments as $shipment) {
            $order = $shipment->order;
            $address = $order?->address;
            $reason = $reasonById[(string) ($order?->id ?? '')] ?? '';
            $items = $order?->items ?? collect();
            $items = $items->isEmpty() ? [null] : $items->all();
            foreach ($items as $item) {
                $rows[] = [
                    (string) ($order?->erp_order_id ?? ''),
                    (string) ($order?->id ?? ''),
                    $warehouseKey,
                    (string) ($address?->recipient_name ?? ''),
                    (string) ($address?->phone ?? ''),
                    (string) ($address?->country ?? ''),
                    (string) ($address?->province_state ?? ''),
                    (string) ($address?->city ?? ''),
                    (string) ($address?->postal_code ?? ''),
                    (string) ($address?->address_line_1 ?? ''),
                    (string) ($address?->address_line_2 ?? ''),
                    (string) ($item?->sku ?? ''),
                    $item?->quantity,
                    $reason,
                    $reason === '' ? '合规' : '存在问题-核对后再提交仓库',
                ];
            }
        }
        return [
            'filename' => sprintf('007-%s-review-draft-%s.xlsx', $warehouseKey, now()->format('ymd')),
            'bytes' => $this->xlsx->write('人工核对草稿-不可直接上传仓库', [
                'ERP订单号', '订单ID', '仓库', '收件人', '电话', '国家', '州省', '城市', '邮编', '地址1', '地址2',
                'SKU', '数量', '问题原因', '校验状态',
            ], $rows),
        ];
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
