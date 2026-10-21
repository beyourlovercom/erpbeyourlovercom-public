<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class OverseaTrackingReturnFileReader
{
    public function __construct(
        private readonly WmsTrackingReturnReader $wms,
        private readonly MorePlusTrackingReturnReader $morePlus,
        private readonly CanadaTrackingReturnReader $canada,
    ) {}

    /** @return list<array<string,mixed>> */
    public function read(string $warehouseKey, UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'xlsx') {
            return $this->legacyCsv($file);
        }

        return match ($warehouseKey) {
            'us-ny', 'de-tianyun' => $this->wms->read($file),
            'us-moreplus' => $this->morePlus->read($file),
            'ca' => $this->canada->read($file),
            'us-3sm' => throw new InvalidArgumentException('世迈目前没有真实物流单号回传模板，请使用逐单 Tracking 录入。'),
            default => throw new InvalidArgumentException('未知海外仓回传格式。'),
        };
    }

    /** @return list<array<string,mixed>> */
    private function legacyCsv(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $handle = is_string($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new InvalidArgumentException('无法读取单号表。');
        }
        try {
            $headers = fgetcsv($handle) ?: [];
            if (isset($headers[0])) {
                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            }
            $map = [];
            foreach ($headers as $i => $header) {
                $n = mb_strtolower(trim((string) $header));
                if (in_array($n, ['订单号', 'erp订单号', 'order number', 'order no.', 'order no'], true)) {
                    $map['order_reference'] = $i;
                } elseif (in_array($n, ['tracking no.', 'tracking no', 'tracking number', 'tracking', '追踪单号', '物流单号'], true)) {
                    $map['tracking_number'] = $i;
                } elseif (str_contains($n, '发货时间') || in_array($n, ['shipped_at', 'shipped at'], true)) {
                    $map['shipped_at'] = $i;
                } elseif (in_array($n, ['carrier', '承运商', '物流商'], true)) {
                    $map['carrier'] = $i;
                }
            }
            if (! isset($map['order_reference'], $map['tracking_number'])) {
                throw new InvalidArgumentException('兼容 CSV 缺少订单号或 Tracking No.。');
            }

            $out = [];
            $rowNumber = 2;
            while (($cells = fgetcsv($handle)) !== false) {
                $get = fn (string $key): string => isset($map[$key]) ? trim((string) ($cells[$map[$key]] ?? '')) : '';
                if (collect($cells)->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
                    $rowNumber++;

                    continue;
                }
                $out[] = [
                    'row' => $rowNumber,
                    'order_reference' => $get('order_reference'),
                    'provider_order_reference' => null,
                    'tracking_number' => $get('tracking_number'),
                    'carrier' => $get('carrier') ?: null,
                    'provider_service' => null,
                    'provider_status' => null,
                    'provider_warehouse' => null,
                    'tracking_status' => null,
                    'package_tracking' => null,
                    'return_tracking' => null,
                    'cancelled' => false,
                    'cancellation_reason' => null,
                    'shipped_at' => $get('shipped_at') ?: null,
                    'provider_evidence' => collect($headers)->mapWithKeys(fn ($header, $index) => [trim((string) $header) => trim((string) ($cells[$index] ?? ''))])->all(),
                    'parse_error' => null,
                ];
                $rowNumber++;
            }

            return $out;
        } finally {
            fclose($handle);
        }
    }
}
