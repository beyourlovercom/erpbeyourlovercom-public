<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\OverseaFiles\ProviderXlsxWriter;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

final class OverseaWarehouseWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_page_is_isolated_and_uses_frozen_carrier_mapping(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $ny = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-NY');
        $this->orderAt($warehouses['DE'], '007-OVERSEA-DE', 'DE');

        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=review')->assertInertia(fn (Assert $page) => $page
            ->component('Logistics/Oversea/Show')
            ->where('warehouse.label', '美东纽约仓')
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $ny->id)
            ->where('rows.data.0.line', 1)
            ->where('rows.data.0.carrier', 'USPS')
            ->where('rows.data.0.review_status', 'pending'));
    }

    public function test_three_sm_uses_canonical_us_3sm_url_and_legacy_keys_still_resolve(): void
    {
        $user = $this->userWith(['logistics.view']);
        $warehouses = $this->warehouses();
        $this->orderAt($warehouses['US-SM'], '007-OVERSEA-SJ');

        $this->actingAs($user)->get('/logistics/oversea/us-3sm')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('warehouseKey', 'us-3sm')
            ->where('warehouse.code', 'US-SM')
            ->where('warehouse.label', '美国3世迈仓'));
        $this->actingAs($user)->get('/logistics/oversea/us-sm')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('warehouseKey', 'us-3sm')
            ->where('warehouse.code', 'US-SM'));
    }

    public function test_legacy_lowercase_us_la_code_is_normalized_across_page_approval_and_reopen(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $warehouses['US-LA']->forceFill(['code' => 'us-la'])->save();
        $order = $this->orderAt($warehouses['US-LA']->fresh(), '007-OVERSEA-USLA-LEGACY');

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=review')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('warehouse.code', 'US-LA')
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $order->id)
            ->where('rows.data.0.carrier', 'USPS'));

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouses['US-LA']->id,
            'carrier' => 'USPS',
            'shipment_status' => 'pending',
        ]);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'approved',
            'reviewed_warehouse_id' => $warehouses['US-LA']->id,
        ]);

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/reopen-review")
            ->assertRedirect('/logistics/oversea/us-moreplus?view=review');
    }

    public function test_approval_moves_order_from_review_to_pending_and_persists_default_carrier(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['DE'], '007-OVERSEA-APPROVE', 'DE');

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'approved',
            'reviewed_warehouse_id' => $warehouses['DE']->id,
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouses['DE']->id,
            'carrier' => 'DHL',
            'shipment_status' => 'pending',
        ]);

        $this->actingAs($user)->get('/logistics/oversea/de-tianyun?view=pending')->assertInertia(
            fn (Assert $page) => $page->where('rows.total', 1)->where('rows.data.0.order_id', $order->id)
        );
        $this->actingAs($user)->get('/logistics/oversea/de-tianyun?view=review')->assertInertia(
            fn (Assert $page) => $page->where('rows.total', 0)
        );
    }

    public function test_approved_order_can_be_reopened_for_review_and_reassignment(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-REOPEN');

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve", ['note' => '审核通过'])->assertRedirect();
        $response = $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/reopen-review");
        $response->assertRedirect('/logistics/oversea/us-moreplus?view=review');

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id, 'status' => 'pending', 'progress' => 'pending_review',
            'reviewed_warehouse_id' => null, 'reviewed_by' => null, 'reviewed_at' => null,
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'warehouse_id' => $warehouses['US-LA']->id, 'carrier' => 'USPS',
        ]);
    }

    public function test_dede_can_change_warehouse_and_carrier_follows_the_new_warehouse(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-CHANGE');

        $this->actingAs($user)->patch("/logistics/oversea/orders/{$order->id}/draft", [
            'warehouse_id' => $warehouses['CA']->id,
            'note' => 'Vita 仓库选择错误',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouses['CA']->id,
            'carrier' => 'Canada Post',
        ]);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'submitted_warehouse_id' => $warehouses['US-LA']->id,
            'status' => 'pending',
            'note' => 'Vita 仓库选择错误',
        ]);
    }

    public function test_manual_carrier_override_survives_approval(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-MANUAL-CARRIER');

        $this->actingAs($user)->patch("/logistics/oversea/orders/{$order->id}/draft", [
            'carrier' => 'FedEx',
        ])->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'carrier' => 'FedEx', 'carrier_selection_source' => 'manual',
        ]);

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'carrier' => 'FedEx', 'carrier_selection_source' => 'manual',
        ]);
    }

    public function test_tracking_number_can_be_entered_before_shipping(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-TRACKING');

        $this->actingAs($user)->patch("/logistics/oversea/orders/{$order->id}/draft", [
            'tracking_number' => '9400111899223856928499',
        ])->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'tracking_number' => '9400111899223856928499', 'shipment_status' => 'pending',
        ]);
    }

    public function test_rejection_requires_reason_clears_assignment_and_notifies_all_operations_users(): void
    {
        $dede = $this->userWith(['logistics.view', 'logistics.operate']);
        $opsA = $this->operationsUser('vita-a@example.test');
        $opsB = $this->operationsUser('vita-b@example.test');
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-REJECT');

        $this->actingAs($dede)->post("/logistics/oversea/orders/{$order->id}/reject", [])->assertSessionHasErrors('note');
        $this->actingAs($dede)->post("/logistics/oversea/orders/{$order->id}/reject", ['note' => '太贵，退回重新分仓'])->assertRedirect();

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'rejected',
            'note' => '太贵，退回重新分仓',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => null,
            'warehouse_selection_source' => null,
            'carrier' => null,
            'carrier_selection_source' => null,
        ]);

        foreach ([$opsA, $opsB] as $ops) {
            $notification = $ops->notifications()->first();
            $this->assertNotNull($notification);
            $this->assertSame('海外仓分配被驳回', $notification->data['title']);
            $this->assertSame('/logistics/wait-stock', $notification->data['action_url']);
            $this->assertStringContainsString('太贵，退回重新分仓', $notification->data['message']);
        }
    }

    public function test_export_fails_closed_with_422_for_noncanonical_order_reference(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], 'LEGACY-NONCANONICAL-ID');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus/export')->assertStatus(422);
    }

    public function test_moreplus_export_fails_closed_when_recipient_phone_is_missing(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40760');
        $order->address()->update(['phone' => '']);
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus/export')->assertStatus(422);
    }

    public function test_pending_moreplus_orders_download_real_xlsx_block_rows(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40761');
        $order->items()->create([
            'product_summary' => 'Second Product', 'sku' => 'SECOND-SKU', 'quantity' => 3,
            'unit_price' => '10.00', 'line_revenue' => '30.00',
        ]);
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $response = $this->actingAs($user)->get('/logistics/oversea/us-moreplus/export');
        $response->assertOk();
        $this->assertStringContainsString('attachment; filename="007-us-moreplus-orders-', (string) $response->headers->get('content-disposition'));
        $this->assertStringEndsWith('.xlsx"', (string) $response->headers->get('content-disposition'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('content-type'));

        $rows = $this->xlsxRows($response->getContent());
        $this->assertSame('Order NO', $rows[0][0]);
        $this->assertSame('C40761-007', $rows[1][0]);
        $this->assertSame('REVIEW-SKU', $rows[1][1]);
        $this->assertSame('Review Company', $rows[1][6]);
        $this->assertSame('+1 555 0100', $rows[1][13]);
        $this->assertSame('review.recipient@example.test', $rows[1][14]);
        $this->assertSame('Selected by Warehouse', $rows[1][15]);
        $this->assertSame('', $rows[2][0]);
        $this->assertSame('SECOND-SKU', $rows[2][1]);
        $this->assertSame('3', $rows[2][2]);
    }

    public function test_wms_family_exports_real_usny_us3sm_and_de_profiles(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $cases = [
            ['US-NY', '007erp-0920-c40801', 'US', '/logistics/oversea/us-ny/export', ['US01', 'C40801-007', 'USPS-NJ', '']],
            ['US-SM', '007erp-0920-c40802', 'US', '/logistics/oversea/us-3sm/export', ['Yangfan', 'C40802-007', 'Service with the lowest price', 'USPS']],
            ['DE', '007erp-0920-c40803', 'NL', '/logistics/oversea/de-tianyun/export', ['Hannover', 'C40803-007', 'DHL PAKET International N', '']],
        ];

        foreach ($cases as [$code, $erp, $country, $url, $expected]) {
            $order = $this->orderAt($warehouses[$code], $erp, $country);
            $this->markAsPaidWooOrder($order, 'WC-'.$code);
            $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
            $response = $this->actingAs($user)->get($url)->assertOk();
            $rows = $this->xlsxRows($response->getContent());
            $this->assertSame('Warehouse Code/仓库代码', $rows[0][0]);
            $this->assertSame($expected[0], $rows[1][0]);
            $this->assertSame('Woocommerce', $rows[1][1]);
            $this->assertSame($expected[1], $rows[1][3]);
            $this->assertSame('WC-'.$code, $rows[1][4]);
            $this->assertSame($expected[2], $rows[1][5]);
            $this->assertSame($expected[3], $rows[1][6] ?? '');
            $this->assertSame('+1 555 0100', $rows[1][11]);
            $this->assertSame('review.recipient@example.test', $rows[1][12]);
            $this->assertSame('Review Company', $rows[1][14]);
            $this->assertSame('REVIEW-SKU', $rows[1][24]);
            $this->assertSame('2', $rows[1][25]);
        }
    }

    public function test_canada_export_matches_real_reference_and_provider_profile(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['CA'], '007erp-0920-k26011301', 'CA');
        $this->markAsPaidWooOrder($order, 'WC-CA-26011301');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $response = $this->actingAs($user)->get('/logistics/oversea/ca/export')->assertOk();
        $rows = $this->xlsxRows($response->getContent());
        $this->assertSame('客户参考号', $rows[0][0]);
        $this->assertSame('KOL26011301', $rows[1][0]);
        $this->assertSame('CA02_多伦多仓', $rows[1][1]);
        $this->assertSame('CAPOST[加拿大邮政多伦多]', $rows[1][2]);
        $this->assertSame('Woocommerce', $rows[1][13]);
        $this->assertSame('WC-CA-26011301', $rows[1][14]);
        $this->assertSame('CA', $rows[1][16]);
        $this->assertSame('+1 555 0100', $rows[1][23]);
        $this->assertSame('review.recipient@example.test', $rows[1][24]);
        $this->assertSame('Review Company', $rows[1][25]);
        $this->assertSame('REVIEW-SKU', $rows[1][39]);
        $this->assertSame('2', $rows[1][40]);
        $this->assertSame('G  良好', $rows[1][42]);
    }

    public function test_moreplus_export_fails_closed_when_address_line_1_exceeds_provider_limit(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40762');
        $order->address()->update(['address_line_1' => str_repeat('A', 36)]);
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus/export')->assertStatus(422);
    }

    public function test_wms_export_fails_closed_above_confirmed_ten_sku_limit(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007erp-0920-c40811');
        $productId = Product::query()->where('sku', 'REVIEW-SKU')->value('id');
        for ($i = 2; $i <= 11; $i++) {
            $order->items()->create([
                'product_id' => $productId, 'product_summary' => "Product {$i}", 'sku' => "SKU-{$i}", 'quantity' => 1,
                'unit_price' => '10.00', 'line_revenue' => '10.00',
            ]);
        }
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->actingAs($user)->get('/logistics/oversea/us-ny/export')->assertStatus(422);
    }

    public function test_wms_and_canada_exports_fail_closed_when_recipient_phone_is_missing(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        foreach ([
            [$warehouses['US-NY'], '007erp-0920-c40812', 'US', '/logistics/oversea/us-ny/export'],
            [$warehouses['CA'], '007erp-0920-k26011302', 'CA', '/logistics/oversea/ca/export'],
        ] as [$warehouse, $erp, $country, $url]) {
            $order = $this->orderAt($warehouse, $erp, $country);
            $order->address()->update(['phone' => '']);
            $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
            $this->actingAs($user)->get($url)->assertStatus(422);
        }
    }

    public function test_moreplus_outbound_roundtrip_return_previews_real_tracking_without_apply(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40762');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('moreplus-roundtrip-return.xlsx', 'Template',
            ['Order NO', 'SKU', 'SKU Qty', 'Tag', 'Request Ship Date', 'Recipient Name', 'Recipient Company', 'Recipient Address Line 1', 'Recipient Address Line 2', 'Recipient State', 'Recipient City', 'Recipient ZipCode', 'Recipient Country', 'Recipient Phone', 'Recipient Email', 'Request Shipping Service', 'Warehouse Customized Service Name', 'Signature Option', 'Tracking Number', 'Shipping Carrier', 'Insurance', 'Cod', 'Notes'],
            [['C40762-007', 'REVIEW-SKU', 1, '', '', 'Review Recipient', '', '1 Main St', '', 'CA', 'City', '90001', 'US', '+1 555 0100', 'review@example.test', 'Selected by Warehouse', '', '', '9434650105794034942869', '', '', '', '']]);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', ['file' => $file])
            ->assertRedirect('/logistics/oversea/us-moreplus?view=pending');
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertSame(1, $stored['apply_count']);
        $this->assertSame(0, $stored['blocked_count']);
        $this->assertSame('9434650105794034942869', $stored['rows'][0]['tracking_number']);
        $this->assertSame('Selected by Warehouse', $stored['rows'][0]['provider_service']);
        $this->assertSame('9434650105794034942869', $stored['rows'][0]['provider_evidence']['Tracking Number']);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
    }

    public function test_moreplus_real_xlsx_return_matches_provider_reference_and_is_idempotent_without_shipped_time(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40761');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('moreplus-return.xlsx', '0',
            ['Outbound Order#', 'Order#', 'Shipped Time', 'Master Tracking#', 'Tracking#', 'Requested via', 'Ship Via', 'Carrier'],
            [['MP-1', 'C40761-007', '', '92144904245461009999', '92144904245461009999', 'Selected by warehouse', 'USPS Ground Advantage', 'usps']]);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', ['file' => $file])
            ->assertRedirect('/logistics/oversea/us-moreplus?view=pending');
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertSame(1, $stored['apply_count']);
        $this->assertSame(0, $stored['blocked_count']);
        $this->assertSame('USPS Ground Advantage', $stored['rows'][0]['provider_service']);
        $this->assertSame('USPS', $stored['rows'][0]['carrier']);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/apply', ['token' => $stored['token']])
            ->assertRedirect('/logistics/oversea/us-moreplus?view=shipped');
        $shipment = $order->fresh('shipment')->shipment;
        $this->assertSame('shipped', $shipment->shipment_status);
        $this->assertSame('92144904245461009999', $shipment->tracking_number);
        $this->assertNotNull($shipment->shipped_at);

        $fileAgain = $this->providerXlsx('moreplus-return.xlsx', '0',
            ['Outbound Order#', 'Order#', 'Shipped Time', 'Master Tracking#', 'Tracking#', 'Requested via', 'Ship Via', 'Carrier'],
            [['MP-1', 'C40761-007', '', '92144904245461009999', '92144904245461009999', 'Selected by warehouse', 'USPS Ground Advantage', 'usps']]);
        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', ['file' => $fileAgain])->assertRedirect();
        $again = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertSame(0, $again['apply_count']);
        $this->assertSame(1, $again['noop_count']);
        $this->assertSame(0, $again['blocked_count']);
    }

    public function test_moreplus_real_xlsx_return_blocks_master_tracking_conflict(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40755');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('moreplus-return.xlsx', '0',
            ['Outbound Order#', 'Order#', 'Shipped Time', 'Master Tracking#', 'Tracking#', 'Ship Via', 'Carrier'],
            [['MP-2', 'C40755-007', '', '92144904245461009344', '92144904245461009343', 'USPS Ground Advantage', 'usps']]);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertStringContainsString('Master Tracking# 与 Tracking# 不一致', $stored['rows'][0]['message']);
    }

    public function test_usny_real_xlsx_return_uses_reference_tracking_and_ignores_provider_pending_status(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007erp-0920-c40801');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('usny-return.xlsx', 'Sheet1',
            ['Outbound Order No./出库单号', 'Reference order No./参考单号', 'Status/状态', 'Shipping service/物流渠道', 'Tracking No./物流跟踪号', 'Tracking Status/物流状态', 'OutboundTime/出库时间', 'Carrier/承运商', 'Email/邮箱'],
            [['OB-40801', 'C40801-007', '仓库处理中', 'USPS-NJ(USPS-NJ)', '9214490416421805721875', 'Pending', '', 'usps', 'provider.echo@example.test']]);

        $this->actingAs($user)->post('/logistics/oversea/us-ny/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-ny');
        $this->assertSame(1, $stored['apply_count']);
        $this->assertSame(0, $stored['blocked_count']);
        $this->assertSame('仓库处理中', $stored['rows'][0]['provider_status']);
        $this->assertSame('Pending', $stored['rows'][0]['tracking_status']);
        $this->assertSame('USPS-NJ(USPS-NJ)', $stored['rows'][0]['provider_service']);
        $this->assertSame('provider.echo@example.test', $stored['rows'][0]['provider_evidence']['Email/邮箱']);
    }

    public function test_wms_real_full_headers_keep_primary_tracking_and_outbound_reference(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007erp-0920-c40801');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('usny-real-full-header-return.xlsx', '出库单',
            [
                'Outbound Order No./出库单号',
                'Reference order No./参考单号',
                'Tracking No./物流跟踪号',
                'Return Tracking No./回邮单号',
                'Related Outbound Order No./关联出库单号',
                "Package 1\nTracking No./子物流跟踪号",
                'Status/状态',
                'Shipping service/物流渠道',
                'OutboundTime/出库时间',
                'Carrier/承运商',
            ],
            [[
                'OB-PRIMARY',
                'C40801-007',
                '9214490416421805721875',
                'RETURN-TRACKING-DECOY',
                'RELATED-OUTBOUND-DECOY',
                'PACKAGE-TRACKING-DECOY',
                '仓库处理中',
                'USPS-NJ(USPS-NJ)',
                '',
                'usps',
            ]]);

        $this->actingAs($user)->post('/logistics/oversea/us-ny/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-ny');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertSame('9214490416421805721875', $stored['rows'][0]['tracking_number']);
        $this->assertSame('OB-PRIMARY', $stored['rows'][0]['provider_order_reference']);
        $this->assertStringContainsString('主 Tracking No. 与 Package Tracking No. 不一致', $stored['rows'][0]['message']);
    }

    public function test_wms_return_blocks_explicit_cancellation_even_with_valid_tracking(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007erp-0920-c40821');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('usny-cancelled-return.xlsx', 'Sheet1',
            ['Reference order No./参考单号', 'Status/状态', 'Tracking No./物流跟踪号', 'Cancel time/取消时间', 'Carrier/承运商'],
            [['C40821-007', 'Cancelled', '9214490416421805721801', '2026-09-23 08:00:00', 'usps']]);

        $this->actingAs($user)->post('/logistics/oversea/us-ny/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-ny');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertStringContainsString('Provider 回传显示订单已取消', $stored['rows'][0]['message']);
    }

    public function test_wms_return_blocks_clear_known_provider_warehouse_mismatch(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007erp-0920-c40822');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('usny-wrong-warehouse-return.xlsx', 'Sheet1',
            ['Reference order No./参考单号', 'Warehouse/仓库', 'Tracking No./物流跟踪号', 'Carrier/承运商'],
            [['C40822-007', '德国仓(Hannover)', '9214490416421805721802', 'usps']]);

        $this->actingAs($user)->post('/logistics/oversea/us-ny/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-ny');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertStringContainsString('Provider 回传仓库与当前 ERP 仓库明确不一致', $stored['rows'][0]['message']);
    }

    public function test_moreplus_return_blocks_explicit_cancelled_status(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007erp-0920-c40771');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('moreplus-cancelled-return.xlsx', '0',
            ['Order#', 'Master Tracking#', 'Tracking#', 'Status', 'Reason for Cancellation', 'Carrier'],
            [['C40771-007', '92144904245461009771', '92144904245461009771', 'Cancelled', 'Customer requested cancellation', 'usps']]);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', ['file' => $file])->assertRedirect();
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertSame('Customer requested cancellation', $stored['rows'][0]['cancellation_reason']);
        $this->assertStringContainsString('Provider 回传显示订单已取消', $stored['rows'][0]['message']);
    }

    public function test_canada_real_seven_column_return_previews_tracking_by_customer_reference(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['CA'], '007erp-0920-k26011301', 'CA');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('ca-real-return.xlsx', 'SHEET',
            ['订单号', '包裹序号', 'com_lab_3015', '长(cm)', '宽(cm)', '高(cm)', '重量(kg)'],
            [['KOL26011301', '1', '1030896460840281', '0', '0', '0', '1.2']]);

        $this->actingAs($user)->post('/logistics/oversea/ca/tracking-import/preview', ['file' => $file])
            ->assertRedirect('/logistics/oversea/ca?view=pending');
        $stored = session('logistics.oversea_tracking_return.ca');
        $this->assertSame(1, $stored['apply_count']);
        $this->assertSame(0, $stored['noop_count']);
        $this->assertSame(0, $stored['blocked_count']);
        $this->assertSame('KOL26011301', $stored['rows'][0]['order_number']);
        $this->assertSame('1030896460840281', $stored['rows'][0]['tracking_number']);
        $this->assertSame('1', $stored['rows'][0]['provider_evidence']['包裹序号']);
        $this->assertCount(7, $stored['rows'][0]['provider_evidence']);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
    }

    public function test_canada_real_provider_sample_shape_blocks_when_customer_reference_is_unknown(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $file = $this->providerXlsx('ca-real-unknown-reference.xlsx', 'SHEET',
            ['订单号', '包裹序号', 'com_lab_3015', '长(cm)', '宽(cm)', '高(cm)', '重量(kg)'],
            [['99632-260915-0002', '1', '1030896460840281', '0', '0', '0', '1.2']]);

        $this->actingAs($user)->post('/logistics/oversea/ca/tracking-import/preview', ['file' => $file])
            ->assertRedirect('/logistics/oversea/ca?view=pending');
        $stored = session('logistics.oversea_tracking_return.ca');
        $this->assertSame(0, $stored['apply_count']);
        $this->assertSame(1, $stored['blocked_count']);
        $this->assertSame('99632-260915-0002', $stored['rows'][0]['order_number']);
        $this->assertSame('1030896460840281', $stored['rows'][0]['tracking_number']);
        $this->assertStringContainsString('ERP 找不到该订单号', $stored['rows'][0]['message']);
    }

    public function test_canada_order_only_export_file_is_fail_closed_as_tracking_return(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['CA'], '007erp-0920-k26011301', 'CA');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('ca-order-only-export.xlsx', 'SHEET',
            ['订单号'],
            [['KOL26011301']]);

        $this->actingAs($user)->post('/logistics/oversea/ca/tracking-import/preview', ['file' => $file])
            ->assertStatus(422);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
    }

    public function test_three_sm_xlsx_return_is_fail_closed_until_real_template_exists(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-SM'], '007erp-0920-c40802');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $file = $this->providerXlsx('3sm-return.xlsx', 'Sheet1', ['Order', 'Tracking'], [['C40802-007', '9400111']]);

        $this->actingAs($user)->post('/logistics/oversea/us-3sm/tracking-import/preview', ['file' => $file])
            ->assertStatus(422);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'shipment_status' => 'pending', 'tracking_number' => null]);
    }

    public function test_tracking_return_preview_is_read_only_and_does_not_require_provider_shipped_time(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-RETURN-PREVIEW');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $csv = "订单号,Carrier,Tracking No.,发货时间(ISO8601含时区)\n007-OVERSEA-RETURN-PREVIEW,USPS,9400111899223856928499,2026-09-15 10:30:00\n";

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', [
            'file' => UploadedFile::fake()->createWithContent('return.csv', $csv),
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=pending');

        $this->actingAs($user)->get('/logistics/oversea/us-moreplus?view=pending')->assertInertia(fn (Assert $page) => $page
            ->where('trackingImportPreview.apply_count', 1)
            ->where('trackingImportPreview.blocked_count', 0)
            ->where('trackingImportPreview.rows.0.status', 'apply'));
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'shipment_status' => 'pending', 'tracking_number' => null]);
    }

    public function test_tracking_return_apply_marks_shipped_with_exact_evidence_and_updates_review_progress(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-RETURN-APPLY');
        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $csv = "订单号,Carrier,Tracking No.,发货时间(ISO8601含时区)\n007-OVERSEA-RETURN-APPLY,USPS,9400111899223856928500,2026-09-15T10:30:00-07:00\n";

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', [
            'file' => UploadedFile::fake()->createWithContent('return.csv', $csv),
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=pending');
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->assertIsArray($stored);
        $this->assertSame(1, $stored['apply_count']);
        $this->assertSame(0, $stored['blocked_count']);

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/apply', [
            'token' => $stored['token'],
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=shipped');

        $shipment = $order->fresh('shipment')->shipment;
        $this->assertSame('shipped', $shipment->shipment_status);
        $this->assertSame('USPS', $shipment->carrier);
        $this->assertSame('9400111899223856928500', $shipment->tracking_number);
        $this->assertSame('2026-09-15T17:30:00+00:00', $shipment->shipped_at->utc()->toIso8601String());
        $this->assertDatabaseHas('oversea_warehouse_reviews', ['order_id' => $order->id, 'status' => 'approved', 'progress' => 'shipped']);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'pending']);
    }

    public function test_tracking_return_apply_immediately_pushes_woo_tracking_for_derived_package(): void
    {
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
            'services.woocommerce.connection_key' => 'shop-a',
        ]);
        $gateway = new TrackingReturnFakeWooGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();

        $root = Order::query()->create([
            'internal_order_number' => 'ROOT-RETURN-WOO',
            'erp_order_id' => '007-OVERSEA-RETURN-WOO',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-99001',
            'order_date' => '2026-09-15',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $root->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '99001',
        ]);
        $child = Order::query()->create([
            'fulfillment_parent_id' => $root->id,
            'fulfillment_suffix' => 'B',
            'internal_order_number' => 'CHILD-RETURN-WOO-B',
            'erp_order_id' => '007-OVERSEA-RETURN-WOOB',
            'source_type' => 'manual_other',
            'order_kind' => 'fulfillment_derived',
            'order_date' => '2026-09-15',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $child->items()->create([
            'woocommerce_line_item_id' => 990011,
            'product_summary' => 'Woo package B',
            'sku' => 'REVIEW-SKU',
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);
        $child->shipment()->create([
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouses['US-LA']->id,
            'warehouse_selection_source' => 'manual',
        ]);
        $this->actingAs($user)->post("/logistics/oversea/orders/{$child->id}/approve")->assertRedirect();
        $csv = "订单号,Carrier,Tracking No.,发货时间(ISO8601含时区)\n007-OVERSEA-RETURN-WOOB,USPS,9400111899223856928511,2026-09-15T10:30:00-07:00\n";

        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/preview', [
            'file' => UploadedFile::fake()->createWithContent('woo-return.csv', $csv),
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=pending');
        $stored = session('logistics.oversea_tracking_return.us-moreplus');
        $this->actingAs($user)->post('/logistics/oversea/us-moreplus/tracking-import/apply', [
            'token' => $stored['token'],
        ])->assertRedirect('/logistics/oversea/us-moreplus?view=shipped');

        $this->assertSame(1, $gateway->trackingCalls);
        $this->assertSame(99001, $gateway->providerOrderId);
        $this->assertSame('9400111899223856928511', $gateway->trackingNumber);
        $this->assertSame([['id' => 990011, 'quantity' => 1]], $gateway->lineItems);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $child->id, 'website_sync_status' => 'completed']);
        $this->assertDatabaseHas('sync_outbox', ['operation' => 'woocommerce.tracking_outbound', 'status' => 'completed']);
    }

    public function test_us_sm_manual_confirm_marks_shipped_and_immediately_pushes_woo_tracking(): void
    {
        config([
            'services.woocommerce.url' => 'https://shop.test',
            'services.woocommerce.consumer_key' => 'ck_test',
            'services.woocommerce.consumer_secret' => 'cs_test',
            'services.woocommerce.connection_key' => 'shop-a',
        ]);
        $gateway = new TrackingReturnFakeWooGateway;
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();

        $root = Order::query()->create([
            'internal_order_number' => 'ROOT-USSM-MANUAL',
            'erp_order_id' => '007-OVERSEA-USSM-MANUAL',
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'WC-99101',
            'order_date' => '2026-09-24',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $root->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'shop-a', 'reference' => '99101',
        ]);

        $child = Order::query()->create([
            'fulfillment_parent_id' => $root->id,
            'fulfillment_suffix' => 'B',
            'internal_order_number' => 'CHILD-USSM-MANUAL-B',
            'erp_order_id' => '007-OVERSEA-USSM-MANUALB',
            'source_type' => 'manual_other',
            'order_kind' => 'fulfillment_derived',
            'order_date' => '2026-09-24',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $child->items()->create([
            'woocommerce_line_item_id' => 991011,
            'product_summary' => 'US-SM package B',
            'sku' => 'REVIEW-SKU',
            'quantity' => 1,
            'unit_price' => '10.00',
            'line_revenue' => '10.00',
        ]);
        $child->shipment()->create([
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouses['US-SM']->id,
            'warehouse_selection_source' => 'manual',
        ]);

        $this->actingAs($user)->post("/logistics/oversea/orders/{$child->id}/approve")->assertRedirect();
        $this->actingAs($user)->post("/logistics/oversea/us-3sm/orders/{$child->id}/manual-ship", [
            'tracking_number' => '9400111899223856928599',
        ])->assertRedirect('/logistics/oversea/us-3sm?view=shipped');

        $shipment = $child->fresh('shipment')->shipment;
        $this->assertSame('shipped', $shipment->shipment_status);
        $this->assertSame('USPS', $shipment->carrier);
        $this->assertSame('9400111899223856928599', $shipment->tracking_number);
        $this->assertNotNull($shipment->shipped_at);
        $this->assertSame($user->id, $shipment->handled_by);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $child->id,
            'status' => 'approved',
            'progress' => 'shipped',
        ]);

        $this->assertSame(1, $gateway->trackingCalls);
        $this->assertSame(99101, $gateway->providerOrderId);
        $this->assertSame('9400111899223856928599', $gateway->trackingNumber);
        $this->assertSame([['id' => 991011, 'quantity' => 1]], $gateway->lineItems);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $child->id, 'website_sync_status' => 'completed']);
        $this->assertDatabaseHas('sync_outbox', ['operation' => 'woocommerce.tracking_outbound', 'status' => 'completed']);
    }

    public function test_us_sm_manual_confirm_is_fail_closed_for_other_warehouses(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-NOT-USSM');

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();
        $this->actingAs($user)->post("/logistics/oversea/us-3sm/orders/{$order->id}/manual-ship", [
            'tracking_number' => '9400111899223856928501',
        ])->assertStatus(422);

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'tracking_number' => null,
        ]);
    }

    public function test_super_admin_can_review_without_frontline_fulfillment_edit_access(): void
    {
        $role = Role::query()->create(['key' => 'super_admin', 'label' => 'Super Admin']);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        $this->assertTrue($user->hasPermission('logistics.operate'));
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-OWNER-REVIEW');

        $this->actingAs($user)->get('/logistics/oversea?view=review')
            ->assertInertia(fn (Assert $page) => $page
                ->where('warehouseKey', 'all')
                ->where('canReview', true)
                ->where('canOperate', false)
            );

        $this->actingAs($user)
            ->patch("/logistics/oversea/orders/{$order->id}/draft", ['tracking_number' => 'OWNER-MUST-NOT-EDIT'])
            ->assertForbidden();

        $this->actingAs($user)
            ->patch("/logistics/oversea/orders/{$order->id}/draft", ['note' => 'Owner review note'])
            ->assertRedirect();

        $this->actingAs($user)
            ->post("/logistics/oversea/orders/{$order->id}/approve", ['note' => 'Owner review note'])
            ->assertRedirect();

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'tracking_number' => null,
        ]);
    }

    public function test_all_warehouse_review_allows_operations_and_uses_canonical_pagination(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $this->orderAt($warehouses['US-LA'], '007-OVERSEA-ALL-REVIEW');

        $this->actingAs($user)->get('/logistics/oversea?view=review')
            ->assertInertia(fn (Assert $page) => $page
                ->where('warehouseKey', 'all')
                ->where('canReview', true)
                ->where('canOperate', false)
                ->where('rows.per_page', 50)
                ->where('rows.current_page', 1)
            );

        $this->actingAs($user)->get('/logistics/oversea?view=review&per_page=20')
            ->assertInertia(fn (Assert $page) => $page->where('rows.per_page', 20));

        $this->actingAs($user)->get('/logistics/oversea?view=review&per_page=999')
            ->assertInertia(fn (Assert $page) => $page->where('rows.per_page', 50));
    }

    public function test_view_only_user_sees_page_but_not_operate_flag(): void
    {
        $user = $this->userWith(['logistics.view']);
        $warehouses = $this->warehouses();
        $this->orderAt($warehouses['US-NY'], '007-OVERSEA-VIEW');
        $this->actingAs($user)->get('/logistics/oversea/us-ny')->assertInertia(fn (Assert $page) => $page->where('canOperate', false));
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    private function providerXlsx(string $filename, string $sheet, array $headers, array $rows): UploadedFile
    {
        $bytes = app(ProviderXlsxWriter::class)->write($sheet, $headers, $rows);

        return UploadedFile::fake()->createWithContent($filename, $bytes);
    }

    /** @return list<list<string>> */
    private function xlsxRows(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'oversea-export-test-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);
        $this->assertIsString($xml);

        $doc = new DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];
        foreach ($xp->query('//m:sheetData/m:row') as $rowNode) {
            $row = [];
            foreach ($xp->query('./m:c', $rowNode) as $cell) {
                preg_match('/^([A-Z]+)/', $cell->getAttribute('r'), $match);
                $index = $this->columnIndex($match[1] ?? 'A');
                $value = $cell->getAttribute('t') === 'inlineStr'
                    ? ($xp->query('./m:is/m:t', $cell)->item(0)?->textContent ?? '')
                    : ($xp->query('./m:v', $cell)->item(0)?->textContent ?? '');
                $row[$index] = $value;
            }
            if ($row !== []) {
                ksort($row);
                $max = max(array_keys($row));
                $rows[] = array_map(fn ($i) => (string) ($row[$i] ?? ''), range(0, $max));
            }
        }

        return $rows;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /** @return array<string,Warehouse> */
    private function warehouses(): array
    {
        $rows = [
            'US-NY' => ['us-ny', '美东纽约仓'],
            'US-LA' => ['us-la', '美西 MorePlus 仓'],
            'US-SM' => ['us-3sm', '美国3世迈仓'],
            'DE' => ['de', '德国天运仓'],
            'CA' => ['ca', '加拿大仓'],
        ];
        $result = [];
        foreach ($rows as $code => [$slug, $name]) {
            $result[$code] = Warehouse::query()->updateOrCreate(['code' => $code], [
                'slug' => $slug,
                'name' => $name,
                'type' => 'warehouse',
                'is_active' => true,
                'priority' => 10,
            ]);
        }
        Warehouse::query()->updateOrCreate(['code' => '007'], ['slug' => '007', 'name' => '007 本地仓库', 'type' => 'warehouse', 'is_active' => true, 'priority' => 99]);
        Warehouse::query()->updateOrCreate(['code' => 'FACTORY'], ['slug' => 'factory', 'name' => '中国工厂', 'type' => 'warehouse', 'is_active' => true, 'priority' => 98]);
        $product = Product::query()->firstOrCreate(['sku' => 'REVIEW-SKU'], ['name' => 'Review Product', 'product_type' => 'simple', 'is_active' => true]);
        foreach ($result as $warehouse) {
            InventorySyncBatch::query()->create(['warehouse_id' => $warehouse->id, 'source_type' => 'api', 'sync_mode' => 'full', 'status' => 'confirmed', 'confirmed_at' => now()]);
            WarehouseInventory::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'product_id' => $product->id], ['quantity_on_hand' => 100, 'quantity_reserved' => 0]);
        }

        return $result;
    }

    private function markAsPaidWooOrder(Order $order, string $externalOrderNumber): void
    {
        $order->update([
            'source_type' => 'woocommerce',
            'external_order_number' => $externalOrderNumber,
        ]);
        $order->financials()->create([
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => $externalOrderNumber,
            'provider_order_status' => 'processing',
            'provider_modified_at' => now(),
            'payment_method' => 'woocommerce_payments',
            'payment_transaction_id' => 'TEST-'.strtoupper(str_replace(['-', ' '], '', $externalOrderNumber)).'-'.uniqid(),
            'paid_at' => now(),
            'provider_order_total' => '20.00',
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);
    }

    private function orderAt(Warehouse $warehouse, string $erpOrderId, string $country = 'US'): Order
    {
        $order = Order::query()->create([
            'internal_order_number' => 'INT-'.uniqid(),
            'erp_order_id' => $erpOrderId,
            'source_type' => 'manual_other',
            'order_kind' => 'customer',
            'external_order_number' => 'SOURCE-'.uniqid(),
            'order_date' => '2026-09-10',
            'order_status' => 'pending_shipment',
        ]);
        $order->address()->create([
            'recipient_name' => 'Review Recipient', 'phone' => '+1 555 0100',
            'email' => 'review.recipient@example.test', 'company' => 'Review Company', 'country' => $country,
            'province_state' => 'CA', 'city' => 'City', 'address_line_1' => '1 Main St', 'postal_code' => '90001',
        ]);
        $order->items()->create([
            'product_id' => Product::query()->where('sku', 'REVIEW-SKU')->value('id'),
            'product_summary' => 'Review Product', 'sku' => 'REVIEW-SKU', 'quantity' => 2,
            'unit_price' => '10.00', 'line_revenue' => '20.00',
        ]);
        $order->shipment()->create([
            'shipment_status' => 'pending', 'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'manual',
        ]);

        return $order->fresh(['address', 'items', 'shipment.warehouse']);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::query()->create([
            'key' => 'oversea-test-'.uniqid(),
            'label' => 'Oversea Test',
        ]);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(
                ['key' => $permission],
                ['label' => $permission],
            );
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function operationsUser(string $email): User
    {
        $role = Role::query()->firstOrCreate([
            'key' => 'role_preset_operations',
        ], ['label' => '运营']);
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}

final class TrackingReturnFakeWooGateway implements WooCommerceTrackingGateway
{
    public int $trackingCalls = 0;

    public int $providerOrderId = 0;

    public string $trackingNumber = '';

    public array $lineItems = [];

    public function descriptor(): array
    {
        return ['configured' => true, 'url' => 'https://shop.test', 'connection_key' => 'shop-a'];
    }

    public function inspectTracking(int $providerOrderId): array
    {
        return ['tracking_number' => $this->trackingNumber, 'provider' => 'usps', 'ship_date' => '2026-09-15', 'has_tracking' => $this->trackingNumber !== ''];
    }

    public function syncTracking(int $providerOrderId, string $providerSlug, string $trackingNumber, string $shipDate, array $lineItems = []): array
    {
        $this->trackingCalls++;
        $this->providerOrderId = $providerOrderId;
        $this->trackingNumber = $trackingNumber;
        $this->lineItems = $lineItems;

        return ['written' => true, 'verified' => true];
    }

    public function syncOrderStatus(int $providerOrderId, string $status): array
    {
        return ['written' => true, 'verified' => true, 'status' => $status];
    }
}
