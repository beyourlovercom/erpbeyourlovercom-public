<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use App\Modules\Logistics\Infrastructure\Models\Shipment;

final class MorePlusOutboundExporter
{
    private const HEADERS = [
        'Order NO', 'SKU', 'SKU Qty', 'Tag', 'Request Ship Date', 'Recipient Name', 'Recipient Company', 'Recipient Address Line 1',
        'Recipient Address Line 2', 'Recipient State', 'Recipient City', 'Recipient ZipCode', 'Recipient Country', 'Recipient Phone',
        'Recipient Email', 'Request Shipping Service', 'Warehouse Customized Service Name', 'Signature Option', 'Tracking Number',
        'Shipping Carrier', 'Insurance', 'Cod', 'Notes',
    ];

    public function __construct(
        private readonly ProviderXlsxWriter $xlsx,
        private readonly WarehouseOrderReference $references,
    ) {}

    /** @param iterable<Shipment> $shipments */
    public function export(iterable $shipments): string
    {
        $rows = [];
        foreach ($shipments as $shipment) {
            foreach ($this->rows($shipment) as $row) {
                $rows[] = $row;
            }
        }

        return $this->xlsx->write('Template', self::HEADERS, $rows);
    }

    /** @return list<list<mixed>> */
    private function rows(Shipment $shipment): array
    {
        $order = $shipment->order;
        abort_unless($order && $order->address, 422, 'MorePlus 订单缺少收件地址。');
        $items = $order->items->values();
        abort_if($items->isEmpty(), 422, "订单 {$order->erp_order_id} 没有可导出的 SKU。");

        $a = $order->address;
        $rows = [];
        foreach ($items as $index => $item) {
            $sku = trim((string) $item->sku);
            abort_if($sku === '', 422, "订单 {$order->erp_order_id} 存在空 SKU。");
            $row = array_fill(0, count(self::HEADERS), null);
            if ($index === 0) {
                $row[0] = $this->references->warehouse($order);
                $country = $this->required(strtoupper((string) $a->country), '国家', $order->erp_order_id);
                $row[5] = $this->required($a->recipient_name, '收件人', $order->erp_order_id);
                $row[6] = $this->optional($a->company);
                $row[7] = $this->requiredMax($a->address_line_1, '地址1', $order->erp_order_id, 35);
                $row[8] = $this->optional($a->address_line_2);
                $row[9] = $this->state($a->province_state, $country, $order->erp_order_id);
                $row[10] = $this->required($a->city, '城市', $order->erp_order_id);
                $row[11] = $this->required($a->postal_code, '邮编', $order->erp_order_id);
                $row[12] = $country;
                $row[13] = $this->required($a->phone, '电话', $order->erp_order_id);
                $row[14] = $this->optional($a->email);
                $row[15] = 'Selected by Warehouse';
                $row[22] = $order->order_note ?: $shipment->logistics_note;
            }
            $row[1] = $sku;
            $row[2] = (int) $item->quantity;
            $rows[] = $row;
        }

        return $rows;
    }

    private function requiredMax(mixed $value, string $label, string $order, int $max): string
    {
        $value = $this->required($value, $label, $order);
        abort_if(mb_strlen($value) > $max, 422, "订单 {$order} {$label} 超过 MorePlus {$max} 字符上限，禁止静默截断。");

        return $value;
    }

    private function state(mixed $value, string $country, string $order): string
    {
        $value = trim((string) ($value ?? ''));
        if (in_array($country, ['US', 'CA'], true)) {
            abort_if($value === '', 422, "订单 {$order} 缺少州/省，不能导出 MorePlus 文件。");
        }

        return $value;
    }

    private function optional(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function required(mixed $value, string $label, string $order): string
    {
        $value = trim((string) ($value ?? ''));
        abort_if($value === '', 422, "订单 {$order} 缺少{$label}，不能导出 MorePlus 文件。");

        return $value;
    }
}
