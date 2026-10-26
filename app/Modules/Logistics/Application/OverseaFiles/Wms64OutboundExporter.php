<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use App\Modules\Logistics\Infrastructure\Models\Shipment;

final class Wms64OutboundExporter
{
    private const HEADERS = [
        'Warehouse Code/仓库代码', 'Sales Platform/销售平台', 'Store/店铺', 'Reference Number/参考单号', 'Platform Number/平台单号',
        'Shipping Service/物流渠道', 'Carrier/承运商', 'Tracking No./物流跟踪号', 'Signature Type/签名服务类型', 'Insurance/保险金额',
        'RecipientName/收件人', 'Recipient Phone/电话', 'Recipient Email/邮箱', 'Recipient Tax ID/收件人税号', 'Recipient Company/公司名称',
        'Recipient Country/国家/地区简码', 'Recipient State/省/州', 'District/County / 区/县', 'Recipient City/城市', 'Recipient Zip Code/邮编',
        'Recipient House Number/门牌号', 'Recipient AddressLine1/地址1', 'Recipient AddressLine2/地址2', 'Remark/备注',
        'SKU1/SKU1', 'Quantity1/出库数量1', 'SKU2/SKU2', 'Quantity2/出库数量2', 'SKU3/SKU3', 'Quantity3/出库数量3',
        'SKU4/SKU4', 'Quantity4/出库数量4', 'SKU5/SKU5', 'Quantity5/出库数量5', 'SKU6/SKU6', 'Quantity6/出库数量6',
        'SKU7/SKU7', 'Quantity7/出库数量7', 'SKU8/SKU8', 'Quantity8/出库数量8', 'SKU9/SKU9', 'Quantity9/出库数量9',
        'SKU10/SKU10', 'Quantity10/出库数量10', 'SKU11/SKU11', 'Quantity11/出库数量11', 'SKU12/SKU12', 'Quantity12/出库数量12',
        'SKU13/SKU13', 'Quantity13/出库数量13', 'SKU14/SKU14', 'Quantity14/出库数量14', 'SKU15/SKU15', 'Quantity15/出库数量15',
        'SKU16/SKU16', 'Quantity16/出库数量16', 'SKU17/SKU17', 'Quantity17/出库数量17', 'SKU18/SKU18', 'Quantity18/出库数量18',
        'SKU19/SKU19', 'Quantity19/出库数量19', 'SKU20/SKU20', 'Quantity20/出库数量20',
    ];

    public function __construct(
        private readonly ProviderXlsxWriter $xlsx,
        private readonly WarehouseOrderReference $references,
    ) {}

    public function validate(string $warehouseKey, Shipment $shipment): void
    {
        $this->row($warehouseKey, $shipment);
    }

    /** @param iterable<Shipment> $shipments */
    public function export(string $warehouseKey, iterable $shipments): string
    {
        $rows = [];
        foreach ($shipments as $shipment) {
            $rows[] = $this->row($warehouseKey, $shipment);
        }

        return $this->xlsx->write('小包出库单', self::HEADERS, $rows);
    }

    /** @return list<mixed> */
    private function row(string $warehouseKey, Shipment $shipment): array
    {
        $order = $shipment->order;
        abort_unless($order && $order->address, 422, '海外仓订单缺少收件地址。');
        $items = $order->items->values();
        abort_if($items->isEmpty(), 422, "订单 {$order->erp_order_id} 没有可导出的 SKU。");
        abort_if($items->count() > 10, 422, "订单 {$order->erp_order_id} 超过 Provider 已确认的 10 个 SKU 上限。");

        [$warehouseCode, $shippingService, $carrier] = $this->profile($warehouseKey, strtoupper((string) $order->address->country));
        $a = $order->address;
        $row = array_fill(0, count(self::HEADERS), null);
        $country = $this->required(strtoupper((string) $a->country), '国家', $order->erp_order_id);
        $row[0] = $warehouseCode;
        $row[1] = $this->salesPlatform($order);
        $row[3] = $this->references->warehouse($order);
        $row[4] = $this->platformOrderNumber($order);
        $row[5] = $shippingService;
        $row[6] = $carrier;
        $row[10] = $this->required($a->recipient_name, '收件人', $order->erp_order_id);
        $row[11] = $this->required($a->phone, '电话', $order->erp_order_id);
        $row[12] = $this->optional($a->email);
        $row[14] = $this->optional($a->company);
        $row[15] = $country;
        $row[16] = $this->state($a->province_state, $country, $order->erp_order_id);
        $row[18] = $this->required($a->city, '城市', $order->erp_order_id);
        $row[19] = $this->required($a->postal_code, '邮编', $order->erp_order_id);
        $row[21] = $this->required($a->address_line_1, '地址1', $order->erp_order_id);
        $row[22] = $a->address_line_2;
        $row[23] = $order->order_note ?: $shipment->logistics_note;

        foreach ($items as $index => $item) {
            $sku = trim((string) $item->sku);
            abort_if($sku === '', 422, "订单 {$order->erp_order_id} 存在空 SKU。");
            $row[24 + ($index * 2)] = $sku;
            $row[25 + ($index * 2)] = (int) $item->quantity;
        }

        return $row;
    }

    /** @return array{0:string,1:string,2:?string} */
    private function profile(string $warehouseKey, string $country): array
    {
        return match ($warehouseKey) {
            'us-ny' => ['US01', 'USPS-NJ', null],
            'us-3sm' => ['Yangfan', 'Service with the lowest price', 'USPS'],
            'de-tianyun' => ['Hannover', $country === 'DE' ? 'DHL PAKET DOMESTIC' : 'DHL PAKET International N', null],
            default => abort(422, '该仓库不属于 WMS-64 导出家族。'),
        };
    }

    private function salesPlatform(mixed $order): ?string
    {
        $source = $order->source_type instanceof \BackedEnum ? $order->source_type->value : (string) $order->source_type;

        return $source === 'woocommerce' ? 'Woocommerce' : null;
    }

    private function platformOrderNumber(mixed $order): ?string
    {
        return $this->salesPlatform($order) === 'Woocommerce' ? $this->optional($order->external_order_number) : null;
    }

    private function state(mixed $value, string $country, string $order): string
    {
        $value = trim((string) ($value ?? ''));
        if (in_array($country, ['US', 'CA'], true)) {
            abort_if($value === '', 422, "订单 {$order} 缺少州/省，不能导出仓库文件。");
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
        abort_if($value === '', 422, "订单 {$order} 缺少{$label}，不能导出仓库文件。");

        return $value;
    }
}
