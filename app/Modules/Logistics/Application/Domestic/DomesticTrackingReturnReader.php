<?php

namespace App\Modules\Logistics\Application\Domestic;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class DomesticTrackingReturnReader
{
    private const MAX_ROWS = 1000;

    /** @return list<array{row:int,order_reference:string,tracking_number:string,carrier:string,provider_status:?string}> */
    public function read(string $provider, UploadedFile $file): array
    {
        $ext = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (! in_array($ext, ['xls', 'xlsx'], true)) {
            throw new InvalidArgumentException('国内物流回传文件必须是 XLS 或 XLSX。');
        }

        try {
            $book = IOFactory::load($file->getRealPath());
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('无法读取国内物流回传表。', 0, $e);
        }

        try {
            $sheet = $book->getSheet(0);
            $highestColumn = $sheet->getHighestDataColumn();
            $headers = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, true, false)[0] ?? [];
            $index = [];
            foreach ($headers as $i => $header) {
                $key = trim((string) $header);
                if ($key !== '') {
                    $index[$key] = $i;
                }
            }

            $required = match ($provider) {
                '4px' => ['原单号', '转单号'],
                'weship' => ['客户单号'],
                'generic' => ['ERP订单号', '承运商', 'Tracking'],
                default => throw new InvalidArgumentException('不支持的国内物流回传格式。'),
            };
            foreach ($required as $header) {
                if (! array_key_exists($header, $index)) {
                    throw new InvalidArgumentException("回传表缺少必填列：{$header}");
                }
            }
            if ($provider === 'weship' && ! isset($index['运单号']) && ! isset($index['服务商单号'])) {
                throw new InvalidArgumentException('纬狮回传表缺少“运单号/服务商单号”。');
            }

            $rows = [];
            $max = min($sheet->getHighestDataRow(), self::MAX_ROWS + 1);
            for ($r = 2; $r <= $max; $r++) {
                $values = $sheet->rangeToArray("A{$r}:{$highestColumn}{$r}", null, true, true, false)[0] ?? [];
                if ($provider === '4px') {
                    $reference = trim((string) ($values[$index['原单号']] ?? ''));
                    $tracking = trim((string) ($values[$index['转单号']] ?? ''));
                    $carrier = '4PX';
                    $status = isset($index['状态']) ? trim((string) ($values[$index['状态']] ?? '')) : null;
                } elseif ($provider === 'weship') {
                    $reference = trim((string) ($values[$index['客户单号']] ?? ''));
                    $trackingA = isset($index['运单号']) ? trim((string) ($values[$index['运单号']] ?? '')) : '';
                    $trackingB = isset($index['服务商单号']) ? trim((string) ($values[$index['服务商单号']] ?? '')) : '';
                    $tracking = $trackingA !== '' ? $trackingA : $trackingB;
                    if ($trackingA !== '' && $trackingB !== '' && strcasecmp($trackingA, $trackingB) !== 0) {
                        $tracking = '__CONFLICT__:'.$trackingA.'|'.$trackingB;
                    }
                    $carrier = 'Weship';
                    $status = isset($index['操作状态']) ? trim((string) ($values[$index['操作状态']] ?? '')) : null;
                } else {
                    $reference = trim((string) ($values[$index['ERP订单号']] ?? ''));
                    $tracking = trim((string) ($values[$index['Tracking']] ?? ''));
                    $carrier = trim((string) ($values[$index['承运商']] ?? ''));
                    $status = 'ERP批量登记';
                }
                if ($reference === '') {
                    continue;
                }

                $rows[] = [
                    'row' => $r,
                    'order_reference' => $reference,
                    'tracking_number' => $tracking,
                    'carrier' => $carrier,
                    'provider_status' => $status ?: null,
                ];
            }
            if (count($rows) > self::MAX_ROWS) {
                throw new InvalidArgumentException('单次最多上传 1000 行。');
            }

            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
