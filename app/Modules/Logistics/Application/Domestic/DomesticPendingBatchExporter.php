<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Orders\Infrastructure\Models\Order;
use DomainException;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class DomesticPendingBatchExporter
{
    public function __construct(private readonly WarehouseOrderReference $references) {}

    /** @param Collection<int,Order> $orders */
    public function export(Collection $orders): string
    {
        if ($orders->isEmpty()) {
            throw new DomainException('当前没有可导出的国内待发货订单。');
        }

        // Keep the return-upload identifier and Tracking columns fixed (A-E).
        $eligible = $orders->filter(function (Order $order): bool {
            $order->loadMissing(['address', 'items', 'shipment', 'externalReferences', 'financials']);
            return $order->shipment?->shipment_status === 'pending';
        })->values();
        if ($eligible->isEmpty()) {
            throw new DomainException('当前没有可导出的国内待发货订单。');
        }

        $maxItems = max(1, (int) $eligible->max(fn (Order $order) => $order->items->count()));
        $headers = [
            'ERP订单号', '客户单号', '承运商', '服务', 'Tracking',
            '收件人', '电话', 'Email', '国家/地区', '州/省', '城市', '邮编', '地址',
        ];
        for ($i = 1; $i <= $maxItems; $i++) {
            $headers[] = $i === 1 ? 'SKU' : "SKU{$i}";
            $headers[] = $i === 1 ? '数量' : "数量{$i}";
        }
        $headers[] = '金额';
        $headers[] = '物流备注';

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('ERP批量发货登记');
        $sheet->fromArray([$headers]);

        foreach ($eligible as $index => $order) {
            $row = $index + 2;
            $shipment = $order->shipment;

            try {
                $reference = $this->references->warehouse($order);
            } catch (DomainException) {
                $reference = '';
            }

            $address = $order->address;
            $values = [
                (string) $order->erp_order_id,
                $reference,
                (string) $shipment->carrier,
                (string) $shipment->service,
                '',
                (string) $address?->recipient_name,
                (string) $address?->phone,
                (string) $address?->email,
                (string) $address?->country,
                (string) $address?->province_state,
                (string) $address?->city,
                (string) $address?->postal_code,
                trim(implode(' ', array_filter([(string) $address?->address_line_1, (string) $address?->address_line_2]))),
            ];
            foreach ($order->items as $item) {
                $values[] = (string) ($item->sku ?: '无SKU');
                $values[] = (string) (int) $item->quantity;
            }
            for ($i = $order->items->count(); $i < $maxItems; $i++) {
                $values[] = '';
                $values[] = '';
            }
            // Preserve the provider's original order total and currency; never invent 0 for missing financial data.
            $amount = $order->financials?->provider_order_total ?? $order->financials?->amount_paid;
            $currency = $order->financials?->currency ?: $order->currency;
            $values[] = $amount === null ? '' : number_format((float) $amount, 2, '.', '').($currency ? ' '.strtoupper((string) $currency) : '');
            $values[] = (string) $shipment->logistics_note;

            foreach ($values as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $row], $value, DataType::TYPE_STRING);
            }
        }

        $sheet->freezePane('A2');
        for ($column = 1; $column <= count($headers); $column++) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'domestic-pending-batch-');
        if ($tmp === false) {
            throw new RuntimeException('无法创建国内待发货批量表临时文件。');
        }

        try {
            (new Xlsx($book))->save($tmp);
            $bytes = file_get_contents($tmp);
        } finally {
            $book->disconnectWorksheets();
            @unlink($tmp);
        }

        if (! is_string($bytes)) {
            throw new RuntimeException('无法读取国内待发货批量表。');
        }

        return $bytes;
    }
}
