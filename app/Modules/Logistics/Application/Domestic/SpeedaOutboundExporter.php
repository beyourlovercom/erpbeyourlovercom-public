<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Orders\Infrastructure\Models\Order;
use DomainException;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class SpeedaOutboundExporter
{
    private const TEMPLATE = 'resources/logistics/templates/speeda-outbound-v1.xlsx';

    public function __construct(private readonly WarehouseOrderReference $references) {}

    /** @return list<string> */
    public function blockers(Order $order): array
    {
        $order->loadMissing(['address', 'items', 'externalReferences']);
        $address = $order->address;
        $missing = [];

        try {
            $reference = trim($this->references->warehouse($order));
        } catch (DomainException) {
            $reference = '';
        }

        $checks = [
            '客户单号' => $reference,
            '目的国家' => trim((string) $address?->country),
            '收件人姓名' => trim((string) $address?->recipient_name),
            '城市' => trim((string) $address?->city),
            '联系地址' => trim(implode(' ', array_filter([
                trim((string) $address?->address_line_1),
                trim((string) $address?->address_line_2),
            ]))),
            '收件人电话' => trim((string) $address?->phone),
            '收件人邮编' => trim((string) $address?->postal_code),
        ];

        foreach ($checks as $label => $value) {
            if ($value === '') {
                $missing[] = $label;
            }
        }
        if ((int) $order->items->sum('quantity') <= 0) {
            $missing[] = '申报品数量1';
        }

        return $missing;
    }

    /**
     * Business-continuity export: invalid orders are isolated instead of blocking
     * the valid daily batch.
     *
     * @param  Collection<int,Order>  $orders
     * @return array{bytes:string,exported_count:int,blocked:list<array{order_id:int,erp_order_id:?string,reasons:list<string>}>}
     */
    public function exportReady(Collection $orders, string $providerLabel = '速达'): array
    {
        $ready = collect();
        $blocked = [];

        foreach ($orders as $order) {
            $reasons = $this->blockers($order);
            if ($reasons === []) {
                $ready->push($order);

                continue;
            }

            $blocked[] = [
                'order_id' => (int) $order->getKey(),
                'erp_order_id' => $order->erp_order_id,
                'reasons' => $reasons,
            ];
        }

        if ($ready->isEmpty()) {
            $summary = collect($blocked)
                ->take(5)
                ->map(fn (array $row): string => ($row['erp_order_id'] ?: '#'.$row['order_id']).'：'.implode('、', $row['reasons']))
                ->implode('；');
            throw new DomainException('没有可安全导出的'.$providerLabel.'订单。'.$summary);
        }

        return [
            'bytes' => $this->export($ready, $providerLabel),
            'exported_count' => $ready->count(),
            'blocked' => $blocked,
        ];
    }

    /** @param Collection<int,Order> $orders */
    public function export(Collection $orders, string $providerLabel = '速达'): string
    {
        if ($orders->isEmpty()) {
            throw new DomainException('当前没有可导出的'.$providerLabel.'待发货订单。');
        }

        $path = base_path(self::TEMPLATE);
        if (! is_file($path)) {
            throw new RuntimeException('4PX / 纬狮共享 24 列下单模板未安装。');
        }

        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('标准上传格式');
        if (! $sheet) {
            throw new RuntimeException('4PX / 纬狮共享模板缺少“标准上传格式”工作表。');
        }

        $countryMap = $this->countryMap($book);
        $highest = $sheet->getHighestDataRow();
        if ($highest > 2) {
            $sheet->removeRow(3, $highest - 2);
        }

        $orders = $orders->values();
        foreach ($orders as $index => $order) {
            $row = $index + 2;
            if ($row > 2) {
                $sheet->insertNewRowBefore($row, 1);
                $sheet->duplicateStyle($sheet->getStyle('A2:X2'), "A{$row}:X{$row}");
                foreach (range('A', 'X') as $column) {
                    $sheet->setCellValue("{$column}{$row}", $sheet->getCell("{$column}2")->getValue());
                }
            }

            $order->loadMissing(['address', 'items.product', 'externalReferences']);
            $address = $order->address;
            $countryCode = strtoupper(trim((string) $address?->country));
            $country = $countryMap[$countryCode] ?? $countryCode;
            $quantity = max(1, (int) $order->items->sum('quantity'));
            $taxId = $this->taxId($order);

            // Owner-frozen provider contract: ERP fills only the green fields below.
            $sheet->setCellValueExplicit("A{$row}", $this->references->warehouse($order), DataType::TYPE_STRING);
            $sheet->setCellValue("B{$row}", '');
            $sheet->setCellValue("C{$row}", '');
            $sheet->setCellValueExplicit("D{$row}", $country, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$row}", trim((string) $address?->recipient_name), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("F{$row}", trim((string) $address?->province_state), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("G{$row}", trim((string) $address?->city), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("H{$row}", trim(implode(' ', array_filter([
                trim((string) $address?->address_line_1),
                trim((string) $address?->address_line_2),
            ]))), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("I{$row}", trim((string) $address?->phone), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("J{$row}", trim((string) $address?->email), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("K{$row}", trim((string) $address?->postal_code), DataType::TYPE_STRING);

            // Explicit owner freeze: these fields must be blank rather than guessed.
            $sheet->setCellValue("M{$row}", '');
            $sheet->setCellValue("N{$row}", '');
            $sheet->setCellValue("P{$row}", '');
            $sheet->setCellValue("Q{$row}", $quantity);

            if ($taxId !== null) {
                $sheet->setCellValueExplicit("W{$row}", 'IOSS', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("X{$row}", $taxId, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue("W{$row}", '');
                $sheet->setCellValue("X{$row}", '');
            }

            $this->assertRequired($order, $row, [
                'A' => '客户单号',
                'D' => '目的国家',
                'E' => '收件人姓名',
                'G' => '城市',
                'H' => '联系地址',
                'I' => '收件人电话',
                'K' => '收件人邮编',
                'Q' => '申报品数量1',
            ], $sheet, $providerLabel);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'domestic-provider-outbound-');
        if ($tmp === false) {
            throw new RuntimeException('无法创建国内承运商导出临时文件。');
        }
        try {
            (new Xlsx($book))->save($tmp);
            $bytes = file_get_contents($tmp);
        } finally {
            $book->disconnectWorksheets();
            @unlink($tmp);
        }
        if (! is_string($bytes)) {
            throw new RuntimeException('无法读取国内承运商导出文件。');
        }

        return $bytes;
    }

    /** @return array<string,string> */
    private function countryMap(Spreadsheet $book): array
    {
        $sheet = $book->getSheetByName('国家对照表');
        if (! $sheet) {
            return [];
        }
        $map = [];
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $code = strtoupper(trim((string) $sheet->getCell("A{$row}")->getFormattedValue()));
            $english = trim((string) $sheet->getCell("C{$row}")->getFormattedValue());
            if ($code !== '' && $english !== '') {
                $map[$code] = $english;
            }
        }

        return $map;
    }

    private function taxId(Order $order): ?string
    {
        // No tax-id evidence is currently persisted by the Woo inbound contract.
        // Support an explicit future evidence reference without guessing from country/order text.
        $reference = $order->externalReferences
            ->first(fn ($row) => in_array(strtolower((string) $row->provider), ['ioss', 'woocommerce_ioss'], true))
            ?->reference;
        $value = strtoupper(trim((string) $reference));

        return preg_match('/\AIM[A-Z0-9]{6,32}\z/', $value) === 1 ? $value : null;
    }

    /** @param array<string,string> $required */
    private function assertRequired(Order $order, int $row, array $required, $sheet, string $providerLabel): void
    {
        $missing = [];
        foreach ($required as $column => $label) {
            if (trim((string) $sheet->getCell("{$column}{$row}")->getFormattedValue()) === '') {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            throw new DomainException("订单 {$order->erp_order_id} 缺少{$providerLabel}必填字段：".implode('、', $missing));
        }
    }
}
