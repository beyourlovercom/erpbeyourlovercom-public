<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class MorePlusTrackingReturnReader
{
    private const ROUNDTRIP_HEADERS = [
        'Order NO', 'SKU', 'SKU Qty', 'Tag', 'Request Ship Date', 'Recipient Name', 'Recipient Company', 'Recipient Address Line 1',
        'Recipient Address Line 2', 'Recipient State', 'Recipient City', 'Recipient ZipCode', 'Recipient Country', 'Recipient Phone',
        'Recipient Email', 'Request Shipping Service', 'Warehouse Customized Service Name', 'Signature Option', 'Tracking Number',
        'Shipping Carrier', 'Insurance', 'Cod', 'Notes',
    ];

    public function __construct(private readonly ProviderXlsxTableReader $xlsx) {}

    /** @return list<array<string,mixed>> */
    public function read(UploadedFile $file): array
    {
        $table = $this->xlsx->read($file);
        $map = $this->map($table['headers']);
        $standard = isset($map['order_reference'], $map['master_tracking'], $map['tracking_number']);
        $roundtrip = $table['headers'] === self::ROUNDTRIP_HEADERS
            && isset($map['roundtrip_order_reference'], $map['roundtrip_tracking_number']);
        if (! $standard && ! $roundtrip) {
            throw new InvalidArgumentException('MorePlus 回传文件必须是标准 Return 模板，或完整 23 列 Outbound round-trip 模板。');
        }

        $out = [];
        foreach ($table['rows'] as $source) {
            $get = fn (string $key): string => isset($map[$key]) ? trim((string) ($source['cells'][$map[$key]] ?? '')) : '';
            $reference = $standard ? $get('order_reference') : $get('roundtrip_order_reference');
            if ($reference === '') {
                continue;
            }
            $master = $standard ? $get('master_tracking') : '';
            $tracking = $standard ? $get('tracking_number') : $get('roundtrip_tracking_number');
            $error = null;
            if ($master !== '' && $tracking !== '' && strcasecmp($master, $tracking) !== 0) {
                $error = 'Master Tracking# 与 Tracking# 不一致';
            }
            $resolved = $tracking !== '' ? $tracking : $master;
            $status = $get('provider_status');
            $cancelStatus = $get('cancel_status');
            $out[] = [
                'row' => $source['row'],
                'order_reference' => $reference,
                'provider_order_reference' => $get('provider_order_reference') ?: null,
                'tracking_number' => $resolved,
                'carrier' => ($standard ? $get('carrier') : $get('roundtrip_carrier')) ?: null,
                'provider_service' => ($standard ? $get('provider_service') : $get('roundtrip_service')) ?: null,
                'provider_status' => $status ?: null,
                'provider_warehouse' => $get('provider_warehouse') ?: null,
                'tracking_status' => null,
                'package_tracking' => null,
                'return_tracking' => $get('return_tracking') ?: null,
                'cancelled' => $this->cancelled($status, $cancelStatus),
                'cancellation_reason' => $get('cancellation_reason') ?: null,
                'shipped_at' => $get('shipped_at') ?: null,
                'provider_evidence' => $this->evidence($table['headers'], (array) $source['cells']),
                'parse_error' => $error,
            ];
        }

        return $out;
    }

    /** @param list<string> $headers @param list<mixed> $cells @return array<string,string> */
    private function evidence(array $headers, array $cells): array
    {
        $out = [];
        foreach ($headers as $index => $header) {
            $key = trim((string) $header);
            if ($key !== '') {
                $out[$key] = trim((string) ($cells[$index] ?? ''));
            }
        }

        return $out;
    }

    private function cancelled(string $status, string $cancelStatus): bool
    {
        foreach ([$status, $cancelStatus] as $value) {
            $value = mb_strtolower(trim($value));
            if ($value === '') {
                continue;
            }
            if (in_array($value, ['cancel', 'cancelled', 'canceled', 'yes', 'true', '1', '取消', '已取消'], true)
                || str_contains($value, 'cancelled') || str_contains($value, 'canceled')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $headers @return array<string,int> */
    private function map(array $headers): array
    {
        $out = [];
        foreach ($headers as $index => $raw) {
            $h = mb_strtolower(trim((string) $raw));
            if ($h === 'order no') {
                $out['roundtrip_order_reference'] = $index;
            } elseif ($h === 'tracking number') {
                $out['roundtrip_tracking_number'] = $index;
            } elseif ($h === 'shipping carrier') {
                $out['roundtrip_carrier'] = $index;
            } elseif ($h === 'request shipping service') {
                $out['roundtrip_service'] = $index;
            } elseif ($h === 'order#') {
                $out['order_reference'] = $index;
            } elseif ($h === 'outbound order#') {
                $out['provider_order_reference'] = $index;
            } elseif ($h === 'master tracking#') {
                $out['master_tracking'] = $index;
            } elseif ($h === 'tracking#') {
                $out['tracking_number'] = $index;
            } elseif ($h === 'carrier') {
                $out['carrier'] = $index;
            } elseif ($h === 'warehouse') {
                $out['provider_warehouse'] = $index;
            } elseif ($h === 'status') {
                $out['provider_status'] = $index;
            } elseif ($h === 'cancel status') {
                $out['cancel_status'] = $index;
            } elseif ($h === 'reason for cancellation') {
                $out['cancellation_reason'] = $index;
            } elseif ($h === 'return order tracking#') {
                $out['return_tracking'] = $index;
            } elseif ($h === 'ship via') {
                $out['provider_service'] = $index;
            } elseif ($h === 'shipped time') {
                $out['shipped_at'] = $index;
            }
        }

        return $out;
    }
}
