<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class CanadaTrackingReturnReader
{
    private const HEADERS = [
        '订单号',
        '包裹序号',
        'com_lab_3015',
        '长(cm)',
        '宽(cm)',
        '高(cm)',
        '重量(kg)',
    ];

    public function __construct(private readonly ProviderXlsxTableReader $xlsx) {}

    /** @return list<array<string,mixed>> */
    public function read(UploadedFile $file): array
    {
        $table = $this->xlsx->read($file);
        if ($table['headers'] !== self::HEADERS) {
            throw new InvalidArgumentException('加拿大仓回传文件必须完整匹配真实 7 列模板。');
        }

        $out = [];
        foreach ($table['rows'] as $source) {
            $cells = array_pad((array) $source['cells'], count(self::HEADERS), '');
            $reference = trim((string) $cells[0]);
            $tracking = trim((string) $cells[2]);

            if (collect($cells)->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
                continue;
            }

            $out[] = [
                'row' => $source['row'],
                'order_reference' => $reference,
                'provider_order_reference' => null,
                'tracking_number' => $tracking,
                'carrier' => null,
                'provider_service' => null,
                'provider_status' => null,
                'provider_warehouse' => null,
                'tracking_status' => null,
                'package_tracking' => null,
                'return_tracking' => null,
                'cancelled' => false,
                'cancellation_reason' => null,
                'shipped_at' => null,
                'provider_evidence' => collect(self::HEADERS)
                    ->mapWithKeys(fn ($header, $index) => [$header => trim((string) ($cells[$index] ?? ''))])
                    ->all(),
                'parse_error' => null,
            ];
        }

        return $out;
    }
}
