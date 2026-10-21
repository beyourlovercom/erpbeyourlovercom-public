<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\Domestic\DomesticPendingBatchExporter;
use App\Modules\Logistics\Application\Domestic\DomesticTrackingReturnService;
use App\Modules\Logistics\Application\Domestic\SpeedaOutboundExporter;
use App\Modules\Logistics\Experience\Controllers\DomesticLogisticsController;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class DomesticLogisticsProductionClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_domestic_workspace_is_formal_and_obsolete_pending_workspace_is_retired(): void
    {
        $formal = collect(config('erp_shell_navigation'))->firstWhere('key', 'logistics');
        self::assertSame(
            ['待分仓', '国内发货', '海外仓发货', '已发待单号', '运输中', '异常订单', '承运商'],
            collect($formal['children'])->pluck('label')->all(),
        );
        self::assertSame(
            ['/logistics/wait-stock', '/logistics/china', '/logistics/oversea', '/logistics/awaiting-tracking', '/logistics/in-transit', '/logistics/exceptions', '/logistics/carriers'],
            collect($formal['children'])->pluck('path')->all(),
        );
        self::assertNotContains('/logistics/pending', collect($formal['children'])->pluck('path')->all());
        self::assertNotContains('/logistics/pending', collect(config('erp_navigation.logistics.children'))->pluck('path')->all());

        $domestic = app('router')->getRoutes()->getByName('logistics.china');
        self::assertSame(DomesticLogisticsController::class.'@index', $domestic?->getActionName());

        $user = $this->logisticsViewer();
        $this->actingAs($user)->get('/logistics')->assertRedirect('/logistics/wait-stock');
        $this->actingAs($user)->get('/logistics/pending')->assertRedirect('/logistics/wait-stock');
        $this->actingAs($user)->get('/logistics/wait-stock')->assertInertia(fn ($page) => $page->component('Logistics/FulfillmentAllocation'));
    }

    public function test_speeda_export_uses_real_24_column_contract_and_blocks_missing_phone(): void
    {
        $order = $this->domesticOrder('007-0923-c50001', '4PX', reviewed: true, phone: '13800138000', quantity: 2);
        $bytes = app(SpeedaOutboundExporter::class)->export(collect([$order->fresh()]));
        $path = tempnam(sys_get_temp_dir(), 'speeda-test-');
        file_put_contents($path, $bytes);

        try {
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('标准上传格式');
            self::assertNotNull($sheet);
            self::assertSame('客户单号', $sheet->getCell('A1')->getValue());
            self::assertSame('发件人税号', $sheet->getCell('X1')->getValue());
            self::assertSame('C50001-007', $sheet->getCell('A2')->getValue());
            self::assertSame('', (string) $sheet->getCell('C2')->getValue());
            self::assertSame('Germany', $sheet->getCell('D2')->getValue());
            self::assertSame('Closure Recipient', $sheet->getCell('E2')->getValue());
            self::assertSame('13800138000', $sheet->getCell('I2')->getValue());
            self::assertSame('buyer@example.test', $sheet->getCell('J2')->getValue());
            self::assertSame('01219', $sheet->getCell('K2')->getValue());
            self::assertSame('0.5', (string) $sheet->getCell('L2')->getValue(), 'non-green fixed template field must be preserved');
            self::assertSame('', (string) $sheet->getCell('M2')->getValue());
            self::assertSame('', (string) $sheet->getCell('N2')->getValue());
            self::assertSame('hand held massager', $sheet->getCell('O2')->getValue(), 'non-green fixed template field must be preserved');
            self::assertSame('', (string) $sheet->getCell('P2')->getValue());
            self::assertSame(2, (int) $sheet->getCell('Q2')->getValue());
            self::assertSame('', (string) $sheet->getCell('W2')->getValue());
            self::assertSame('', (string) $sheet->getCell('X2')->getValue());
            self::assertSame(24, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
            self::assertSame(2, $sheet->getHighestDataRow());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }

        $missingPhone = $this->domesticOrder('007-0923-c50002', '4PX', reviewed: true, phone: '', quantity: 1);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('收件人电话');
        app(SpeedaOutboundExporter::class)->export(collect([$missingPhone]));
    }

    public function test_weship_export_reuses_the_24_column_contract_but_remains_provider_isolated(): void
    {
        $speeda = $this->domesticOrder('007-0923-c50008', '4PX', reviewed: true, phone: '13800138008', quantity: 1);
        $weship = $this->domesticOrder('007-0923-c50009', 'Weship', reviewed: true, phone: '13800138009', quantity: 3);
        $user = $this->logisticsViewer();

        $response = $this->actingAs($user)->get('/logistics/china/export/weship');
        $response->assertOk();
        self::assertStringContainsString('007-weship-orders-', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('1', $response->headers->get('X-BYL-Exported-Orders'));
        self::assertSame('0', $response->headers->get('X-BYL-Blocked-Orders'));

        $path = tempnam(sys_get_temp_dir(), 'weship-shared-template-');
        file_put_contents($path, $response->getContent());
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('标准上传格式');
            self::assertNotNull($sheet);
            self::assertSame(24, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
            self::assertSame(2, $sheet->getHighestDataRow());
            self::assertSame('C50009-007', $sheet->getCell('A2')->getValue());
            self::assertSame('13800138009', $sheet->getCell('I2')->getValue());
            self::assertSame('buyer@example.test', $sheet->getCell('J2')->getValue());
            self::assertSame(3, (int) $sheet->getCell('Q2')->getValue());
            self::assertNotSame('C50008-007', $sheet->getCell('A2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }

        self::assertSame('pending', $speeda->fresh()->shipment->shipment_status);
        self::assertSame('pending', $weship->fresh()->shipment->shipment_status);
    }

    public function test_speeda_batch_export_keeps_valid_orders_moving_when_one_order_is_blocked(): void
    {
        $ready = $this->domesticOrder('007-0923-c50006', '4PX', reviewed: true, phone: '13800138001', quantity: 1);
        $blocked = $this->domesticOrder('007-0923-c50007', '4PX', reviewed: true, phone: '', quantity: 1);

        $result = app(SpeedaOutboundExporter::class)->exportReady(collect([$ready, $blocked]));

        self::assertSame(1, $result['exported_count']);
        self::assertCount(1, $result['blocked']);
        self::assertSame('007-0923-c50007', $result['blocked'][0]['erp_order_id']);
        self::assertContains('收件人电话', $result['blocked'][0]['reasons']);

        $path = tempnam(sys_get_temp_dir(), 'speeda-continuity-');
        file_put_contents($path, $result['bytes']);
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('标准上传格式');
            self::assertSame(2, $sheet->getHighestDataRow());
            self::assertSame('C50006-007', $sheet->getCell('A2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }

    public function test_pending_batch_export_and_mixed_carrier_tracking_upload_are_carrier_safe(): void
    {
        $speeda = $this->domesticOrder('007-0923-c50101', '4PX', reviewed: true, phone: '111', quantity: 1);
        $other = $this->domesticOrder('007-0923-c50102', 'DHL', reviewed: true, phone: '222', quantity: 2);

        $bytes = app(DomesticPendingBatchExporter::class)->export(collect([$speeda, $other]));
        $path = tempnam(sys_get_temp_dir(), 'domestic-pending-').'.xlsx';
        file_put_contents($path, $bytes);
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            self::assertSame('ERP订单号', $sheet->getCell('A1')->getValue());
            self::assertSame('承运商', $sheet->getCell('C1')->getValue());
            self::assertSame('Tracking', $sheet->getCell('E1')->getValue());
            self::assertSame('Email', $sheet->getCell('H1')->getValue());
            self::assertSame('007-0923-c50101', $sheet->getCell('A2')->getValue());
            self::assertSame('4PX', $sheet->getCell('C2')->getValue());
            self::assertSame('buyer@example.test', $sheet->getCell('H2')->getValue());
            self::assertSame('007-0923-c50102', $sheet->getCell('A3')->getValue());
            self::assertSame('DHL', $sheet->getCell('C3')->getValue());
            self::assertSame('', (string) $sheet->getCell('E2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }

        $sheet = new Spreadsheet;
        $ws = $sheet->getActiveSheet();
        $ws->fromArray([
            ['ERP订单号', '客户单号', '承运商', '服务', 'Tracking'],
            ['007-0923-c50101', '', '4PX', '', 'GENERIC-4PX-1'],
            ['007-0923-c50102', '', 'UPS', '', 'GENERIC-WRONG-1'],
        ]);
        $uploadPath = tempnam(sys_get_temp_dir(), 'domestic-generic-').'.xlsx';
        (new Xlsx($sheet))->save($uploadPath);
        $sheet->disconnectWorksheets();

        try {
            $preview = app(DomesticTrackingReturnService::class)->preview(
                'generic',
                new UploadedFile($uploadPath, 'ERP待发货批量登记.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            );
        } finally {
            @unlink($uploadPath);
        }

        self::assertSame(1, $preview['apply_count']);
        self::assertSame(1, $preview['blocked_count']);
        self::assertSame('apply', $preview['rows'][0]['status']);
        self::assertStringContainsString('承运商与回传文件不一致', $preview['rows'][1]['message']);

        $actor = User::factory()->create(['is_active' => true]);
        $result = app(DomesticTrackingReturnService::class)->apply('generic', $preview, $preview['token'], $actor);
        self::assertSame(1, $result['applied_count']);
        self::assertSame('shipped', $speeda->fresh()->shipment->shipment_status);
        self::assertSame('GENERIC-4PX-1', $speeda->fresh()->shipment->tracking_number);
        self::assertSame('pending', $other->fresh()->shipment->shipment_status);
    }

    public function test_speeda_legacy_xls_return_preview_and_partial_apply_isolated_by_row(): void
    {
        $safe = $this->domesticOrder('007-0923-c50003', '4PX', reviewed: true, phone: '111', quantity: 1);
        $blocked = $this->domesticOrder('007-0923-c50004', '4PX', reviewed: false, phone: '222', quantity: 1);
        $file = $this->speedaReturnXls([
            ['C50003-007', '4PX-TRACK-SAFE'],
            ['C50004-007', '4PX-TRACK-BLOCKED'],
        ]);

        $service = app(DomesticTrackingReturnService::class);
        $preview = $service->preview('4px', $file);

        self::assertSame(1, $preview['apply_count']);
        self::assertSame(0, $preview['noop_count']);
        self::assertSame(1, $preview['blocked_count']);
        self::assertSame('apply', $preview['rows'][0]['status']);
        self::assertSame('blocked', $preview['rows'][1]['status']);
        self::assertStringContainsString('尚未完成国内物流审核', $preview['rows'][1]['message']);

        $actor = User::factory()->create(['is_active' => true]);
        $result = $service->apply('4px', $preview, $preview['token'], $actor);

        self::assertSame(1, $result['applied_count']);
        self::assertSame(1, $result['blocked_count']);
        self::assertSame(0, $result['failed_count']);
        self::assertSame('shipped', $safe->fresh()->shipment->shipment_status);
        self::assertSame('4PX-TRACK-SAFE', $safe->fresh()->shipment->tracking_number);
        self::assertSame('pending', $blocked->fresh()->shipment->shipment_status);
        self::assertNull($blocked->fresh()->shipment->tracking_number);
    }

    public function test_weship_return_conflict_is_blocked_without_guessing(): void
    {
        $order = $this->domesticOrder('007-0923-c50005', 'Weship', reviewed: true, phone: '333', quantity: 1);
        $sheet = new Spreadsheet;
        $ws = $sheet->getActiveSheet();
        $ws->fromArray([
            ['客户单号', '运单号', '服务商单号', '操作状态'],
            ['C50005-007', 'WE-A', 'WE-B', '已签入'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'weship-return-').'.xlsx';
        (new Xlsx($sheet))->save($path);
        $sheet->disconnectWorksheets();

        try {
            $preview = app(DomesticTrackingReturnService::class)->preview(
                'weship',
                new UploadedFile($path, '纬狮数据.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            );
        } finally {
            @unlink($path);
        }

        self::assertSame(0, $preview['apply_count']);
        self::assertSame(1, $preview['blocked_count']);
        self::assertSame('blocked', $preview['rows'][0]['status']);
        self::assertStringContainsString('运单号与服务商单号不一致', $preview['rows'][0]['message']);
        self::assertSame('pending', $order->fresh()->shipment->shipment_status);
    }

    private function logisticsViewer(): User
    {
        $permission = Permission::query()->firstOrCreate(['key' => 'logistics.view'], ['label' => 'logistics.view']);
        $role = Role::query()->create(['key' => 'domestic-closure-viewer', 'label' => 'Domestic Closure Viewer']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    private function domesticOrder(string $erpOrderId, string $carrier, bool $reviewed, string $phone, int $quantity): Order
    {
        $order = Order::query()->create([
            'erp_order_id' => $erpOrderId,
            'internal_order_number' => 'INT-'.uniqid(),
            'source_type' => OrderSourceType::Manual,
            'order_kind' => OrderKind::Customer,
            'external_order_number' => 'EXT-'.uniqid(),
            'order_date' => '2026-09-23',
            'order_status' => OrderStatus::PendingShipment,
            'currency' => 'USD',
        ]);
        $order->address()->create([
            'recipient_name' => 'Closure Recipient',
            'phone' => $phone,
            'email' => 'buyer@example.test',
            'country' => 'DE',
            'province_state' => 'DE-SN',
            'city' => 'Dresden',
            'address_line_1' => 'Wiener Straße 96',
            'address_line_2' => '',
            'postal_code' => '01219',
        ]);
        $order->items()->create([
            'product_summary' => 'Test Product',
            'sku' => 'TEST-SKU',
            'quantity' => $quantity,
            'unit_price' => '10.00',
            'line_revenue' => number_format($quantity * 10, 2, '.', ''),
        ]);
        $factory = Warehouse::query()->where('code', 'FACTORY')->firstOrFail();
        $order->shipment()->create([
            'warehouse_id' => $factory->id,
            'warehouse_selection_source' => 'manual',
            'shipment_status' => 'pending',
            'carrier' => $carrier,
            'service' => $carrier === '4PX' ? 'SD经济挂号-带电' : null,
            'carrier_selection_source' => 'manual',
        ]);
        if ($reviewed) {
            $order->operationalMarkers()->create([
                'marker' => 'domestic_reviewed',
                'marked_at' => now(),
            ]);
        }

        return $order->fresh(['address', 'items', 'shipment', 'operationalMarkers', 'externalReferences']);
    }

    /** @param list<array{0:string,1:string}> $rows */
    private function speedaReturnXls(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['状态', '原单号', '转单号', '运输方式', '创建时间', '出货时间']]);
        foreach ($rows as $index => [$reference, $tracking]) {
            $sheet->fromArray([['已出货', $reference, $tracking, 'SD经济挂号-带电', '2026-09-23 10:00:00', '2026-09-23 11:00:00']], null, 'A'.($index + 2));
        }
        $path = tempnam(sys_get_temp_dir(), 'speeda-return-').'.xls';
        (new Xls($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, '速达-发货单号上传模板.xls', 'application/vnd.ms-excel', null, true);
    }
}
