<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use App\Modules\Logistics\Infrastructure\Models\Shipment;

final class OverseaOutboundFileService
{
    public function __construct(
        private readonly Wms64OutboundExporter $wms,
        private readonly MorePlusOutboundExporter $morePlus,
        private readonly CanadaOutboundExporter $canada,
    ) {}

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
