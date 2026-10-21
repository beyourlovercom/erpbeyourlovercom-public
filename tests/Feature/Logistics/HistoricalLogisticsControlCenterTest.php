<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalLogisticsControlCenterService;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalRescueOrderProjectionService;
use App\Modules\Logistics\Application\HistoricalImport\HistoricalRescueShipmentAttemptProjectionService;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportRow;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalLogisticsControlCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_center_reconciles_rescue_order_and_r5_preview_without_business_writes(): void
    {
        Product::create(['source_type' => 'manual', 'sku' => 'TL278A', 'name' => 'Fixture TL278A', 'is_active' => true]);
        $batch = $this->batch();
        $this->rescueRow($batch, 200, 'KOL26010101-007', ['TRACK-A', 'TRACK-B']);
        $operator = $this->operator();

        $r4 = app(HistoricalRescueOrderProjectionService::class)->preview($batch, $operator);
        config()->set('logistics.historical_rescue_order_apply_enabled', true);
        $r4 = app(HistoricalRescueOrderProjectionService::class)->apply($r4, $operator);
        config()->set('logistics.historical_rescue_order_apply_enabled', false);

        $center = app(HistoricalLogisticsControlCenterService::class)->view($batch);
        self::assertSame(1, $center['summary']['rescue_orders']);
        self::assertSame(2, $center['summary']['rescue_tracking_legs']);
        self::assertSame(1, $center['summary']['historical_orders']);
        self::assertSame(0, $center['summary']['active_shipment_contamination']);
        self::assertSame('not_previewed', $center['r5']['status']);
        self::assertSame(2, $center['r5']['total']);
        self::assertSame(2, $center['r5']['ready']);
        self::assertSame(0, $center['r5']['review']);
        self::assertSame(2, $center['r5']['unique_tracking']);
        self::assertSame(0, $center['attempts']['total']);

        $this->actingAs($operator)->post('/logistics/historical-import/'.$batch->id.'/rescue-attempts/preview')->assertRedirect();
        $center = app(HistoricalLogisticsControlCenterService::class)->view($batch);
        self::assertSame('preview', $center['r5']['status']);
        self::assertTrue($center['r5']['can_apply']);
        self::assertTrue($center['r5']['fresh_sha_matches']);
        self::assertSame(2, $center['attempts']['total']);
        self::assertSame([1, 2], collect($center['attempts']['data'])->pluck('attempt_number')->all());
        self::assertSame(['TRACK-A', 'TRACK-B'], collect($center['attempts']['data'])->pluck('tracking_number')->all());
        self::assertSame(0, $center['summary']['historical_attempts_total']);
    }

    private function batch(): HistoricalImportBatch
    {
        return HistoricalImportBatch::create([
            'original_filename' => 'owner-uat.xlsx',
            'source_checksum' => hash('sha256', 'owner-uat'),
            'status' => 'committed',
            'total_rows' => 1077,
            'ready_count' => 788,
            'review_count' => 144,
            'skip_count' => 145,
        ]);
    }

    private function rescueRow(HistoricalImportBatch $batch, int $rowNumber, string $orderNo, array $trackings): void
    {
        $kol = Kol::create(['display_name' => 'Owner UAT KOL', 'status' => 'active', 'relationship_stage' => 'prospect']);
        $row = HistoricalImportRow::create([
            'batch_id' => $batch->id,
            'source_row_number' => $rowNumber,
            'source_order_no' => $orderNo,
            'kol_id' => $kol->id,
            'source_warehouse_raw' => 'Factory 国内仓',
            'source_carrier_raw' => 'Fixture Agent',
            'source_sku_raw' => 'TL278A',
            'source_quantity_raw' => '1',
            'source_input_time_raw' => '2026-08-01',
            'source_tracking_raw' => implode(' ', $trackings),
            'classification' => 'review',
            'classification_reason' => 'multiple_tracking_candidates',
            'evidence_hash' => hash('sha256', 'row|'.$rowNumber),
        ]);

        foreach (array_values($trackings) as $index => $tracking) {
            $hash = hash('sha256', $batch->id.'|'.$rowNumber.'|'.$tracking);
            HistoricalEvidence::create([
                'batch_id' => $batch->id,
                'historical_row_id' => $row->id,
                'source_row_number' => $rowNumber,
                'kol_id' => $kol->id,
                'source_checksum' => $batch->source_checksum,
                'normalized_tracking_candidate' => $tracking,
                'evidence_hash' => $hash,
                'evidence_snapshot' => [
                    'source_row_number' => $rowNumber,
                    'source_order_no' => $orderNo,
                    'kol_id' => $kol->id,
                    'source_warehouse_raw' => 'Factory 国内仓',
                    'source_carrier_raw' => 'Fixture Agent',
                    'source_sku_raw' => 'TL278A',
                    'source_quantity_raw' => '1',
                    'source_input_time_raw' => '2026-08-01',
                    'normalized_tracking_candidate' => $tracking,
                    'rescue' => [
                        'version' => 'historical-review-rescue-v1',
                        'original_classification' => 'review',
                        'original_reason' => 'multiple_tracking_candidates',
                        'leg_index' => $index + 1,
                        'leg_count' => count($trackings),
                        'all_tracking_candidates' => array_values($trackings),
                    ],
                ],
            ]);
        }
    }

    private function operator(): User
    {
        $role = Role::create(['key' => 'historical_owner_uat', 'label' => 'Historical Owner UAT']);
        foreach (['logistics.view', 'logistics.operate', 'orders.view'] as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
