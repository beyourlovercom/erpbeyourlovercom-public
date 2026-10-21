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

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('ERP批量发货登记');
        $sheet->fromArray([[
            'ERP订单号', '客户单号', '承运商', '服务', 'Tracking',
            '收件人', '电话', 'Email', '国家/地区', '州/省', '城市', '邮编', '地址',
            'SKU/数量', '物流备注',
        ]]);

        foreach ($orders->values() as $index => $order) {
            $row = $index + 2;
            $order->loadMissing(['address', 'items', 'shipment', 'externalReferences']);
            $shipment = $order->shipment;
            if (! $shipment || $shipment->shipment_status !== 'pending') {
                continue;
            }

            try {
                $reference = $this->references->warehouse($order);
            } catch (DomainException) {
                $reference = '';
            }

            $address = $order->address;
            $items = $order->items
                ->map(fn ($item): string => ($item->sku ?: '无SKU').' × '.(int) $item->quantity)
                ->implode(' / ');

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
                $items,
                (string) $shipment->logistics_note,
            ];
            foreach ($values as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $row], $value, DataType::TYPE_STRING);
            }
        }

        $sheet->freezePane('A2');
        foreach (range('A', 'O') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
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
