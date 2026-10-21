<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class WmsTrackingReturnReader
{
    public function __construct(private readonly ProviderXlsxTableReader $xlsx) {}

    /** @return list<array<string,mixed>> */
    public function read(UploadedFile $file): array
    {
        $table = $this->xlsx->read($file);
        $map = $this->map($table['headers']);
        foreach (['order_reference', 'tracking_number'] as $required) {
            if (! isset($map[$required])) {
                throw new InvalidArgumentException('WMS 回传文件缺少 Reference order No. 或 Tracking No.。');
            }
        }

        $out = [];
        foreach ($table['rows'] as $source) {
            $get = fn (string $key): string => isset($map[$key]) ? trim((string) ($source['cells'][$map[$key]] ?? '')) : '';
            $reference = $get('order_reference');
            $tracking = $get('tracking_number');
            $packageTracking = $get('package_tracking');
            $error = null;
            if ($tracking !== '' && $packageTracking !== '' && strcasecmp($tracking, $packageTracking) !== 0) {
                $error = '主 Tracking No. 与 Package Tracking No. 不一致';
            }
            if ($reference === '' && $tracking === '') {
                continue;
            }
            $out[] = [
                'row' => $source['row'],
                'order_reference' => $reference,
                'provider_order_reference' => $get('provider_order_reference') ?: null,
                'tracking_number' => $tracking,
                'carrier' => $get('carrier') ?: null,
                'provider_service' => $get('provider_service') ?: null,
                'provider_status' => $get('provider_status') ?: null,
                'provider_warehouse' => $get('provider_warehouse') ?: null,
                'tracking_status' => $get('tracking_status') ?: null,
                'package_tracking' => $packageTracking ?: null,
                'return_tracking' => $get('return_tracking') ?: null,
                'cancelled' => $this->cancelled($get('provider_status'), $get('cancel_time')),
                'cancellation_reason' => null,
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

    private function cancelled(string $status, string $cancelTime): bool
    {
        if (trim($cancelTime) !== '') {
            return true;
        }
        $status = mb_strtolower(trim($status));

        return in_array($status, ['cancel', 'cancelled', 'canceled', '取消', '已取消'], true)
            || str_contains($status, 'cancelled') || str_contains($status, 'canceled');
    }

    /** @param list<string> $headers @return array<string,int> */
    private function map(array $headers): array
    {
        $out = [];
        foreach ($headers as $index => $raw) {
            $normalized = preg_replace('/\s+/u', ' ', trim((string) $raw));
            $h = mb_strtolower($normalized ?? trim((string) $raw));
            if (in_array($h, ['reference order no./参考单号', 'reference order no.', '参考单号'], true)) {
                $out['order_reference'] = $index;
            } elseif (in_array($h, ['tracking no./物流跟踪号', 'tracking no.', '物流跟踪号'], true)) {
                $out['tracking_number'] = $index;
            } elseif (in_array($h, ['outbound order no./出库单号', 'outbound order no.', '出库单号'], true)) {
                $out['provider_order_reference'] = $index;
            } elseif ($h === 'warehouse/仓库' || $h === 'warehouse' || $h === '仓库') {
                $out['provider_warehouse'] = $index;
            } elseif ($h === 'tracking status/物流状态' || $h === 'tracking status' || $h === '物流状态') {
                $out['tracking_status'] = $index;
            } elseif ($h === 'return tracking no./回邮单号' || $h === 'return tracking no.' || $h === '回邮单号') {
                $out['return_tracking'] = $index;
            } elseif (str_starts_with($h, 'package 1 tracking no./') || $h === '子物流跟踪号') {
                $out['package_tracking'] = $index;
            } elseif ($h === 'cancel time/取消时间' || $h === 'cancel time' || $h === '取消时间') {
                $out['cancel_time'] = $index;
            } elseif ($h === 'carrier/承运商' || $h === 'carrier') {
                $out['carrier'] = $index;
            } elseif ($h === 'shipping service/物流渠道' || $h === 'shipping service' || $h === '物流渠道') {
                $out['provider_service'] = $index;
            } elseif ($h === 'status/状态' || $h === 'status') {
                $out['provider_status'] = $index;
            } elseif ($h === 'outboundtime/出库时间' || $h === 'outboundtime' || $h === '出库时间') {
                $out['shipped_at'] = $index;
            }
        }

        return $out;
    }
}
