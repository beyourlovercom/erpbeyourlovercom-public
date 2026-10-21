<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\HistoricalWarehouseEvidenceRecoveryAdapter;
use PHPUnit\Framework\TestCase;

final class HistoricalWarehouseEvidenceRecoveryAdapterTest extends TestCase
{
    private function adapter(): HistoricalWarehouseEvidenceRecoveryAdapter
    {
        return new HistoricalWarehouseEvidenceRecoveryAdapter([
            'US-LA' => 1,
            'US-NY' => 2,
            'DE' => 3,
            'CA' => 4,
            'FACTORY' => 5,
        ]);
    }

    /** @return array<string,mixed> */
    private function candidate(string $erp = '007-0918-c28518', string $tracking = 'TRACK-1'): array
    {
        return [
            'candidate_key' => 'woo:28518:TRACK-1',
            'evidence_source' => 'woocommerce_tracking_meta',
            'identity' => ['exact' => true, 'candidate_order_ids' => [1574]],
            'order' => ['id' => 1574, 'erp_order_id' => $erp, 'order_kind' => 'customer', 'order_status' => 'shipped'],
            'conflicts' => [],
            'evidence' => ['tracking_number' => $tracking, 'tracking_exact' => true, 'warehouse_id' => null, 'warehouse_approved' => false],
        ];
    }

    /** @return array<string,string> */
    private function row(string $order, string $tracking, string $warehouse): array
    {
        return [
            'Status 进度' => '2.1 Shipping 运输中',
            'Stock 仓库' => $warehouse,
            '代理 Carrier' => 'Agent',
            '追踪单号 Tracking No.' => $tracking,
            'Input Time' => '2025-10-01',
            '单号 Order No.' => $order,
        ];
    }

    public function test_exact_order_and_tracking_recovers_frozen_warehouse_mapping(): void
    {
        $result = $this->adapter()->enrichTencentFulfillment(
            $this->candidate(),
            $this->row('C28518-007', 'TRACK-1', 'USA 美西仓'),
        );

        self::assertSame(1, $result['evidence']['warehouse_id']);
        self::assertTrue($result['evidence']['warehouse_approved']);
        self::assertSame('US-LA', $result['evidence']['source_tencent_fulfillment']['canonical_warehouse_code']);
        self::assertStringContainsString('tencent_fulfillment_export', $result['evidence_source']);
    }

    public function test_plain_c_number_is_exact_but_suffix_is_not(): void
    {
        $plain = $this->adapter()->enrichTencentFulfillment($this->candidate(), $this->row('C28518', 'TRACK-1', '美东仓'));
        $suffix = $this->adapter()->enrichTencentFulfillment($this->candidate(), $this->row('C28518B', 'TRACK-1', '美东仓'));
        self::assertSame(2, $plain['evidence']['warehouse_id']);
        self::assertSame($this->candidate(), $suffix);
    }

    public function test_tracking_mismatch_fails_closed(): void
    {
        $result = $this->adapter()->enrichTencentFulfillment(
            $this->candidate(),
            $this->row('C28518-007', 'OTHER-TRACK', 'USA 美西仓'),
        );

        self::assertSame($this->candidate(), $result);
    }

    public function test_all_four_frozen_historical_mappings_are_supported(): void
    {
        $cases = [
            'USA 美西仓' => 1,
            '美东仓' => 2,
            'Canada 加拿大仓' => 4,
            'Factory 国内仓' => 5,
        ];
        foreach ($cases as $raw => $id) {
            $result = $this->adapter()->enrichTencentFulfillment($this->candidate(), $this->row('C28518', 'TRACK-1', $raw));
            self::assertSame($id, $result['evidence']['warehouse_id']);
            self::assertTrue($result['evidence']['warehouse_approved']);
        }
    }

    public function test_europe_and_unknown_warehouses_remain_unresolved(): void
    {
        foreach (['Europe 欧洲仓', 'Tingon外采仓'] as $raw) {
            $result = $this->adapter()->enrichTencentFulfillment($this->candidate(), $this->row('C28518', 'TRACK-1', $raw));
            self::assertFalse($result['evidence']['warehouse_approved']);
            self::assertNull($result['evidence']['warehouse_id']);
            self::assertSame($raw, $result['evidence']['source_tencent_fulfillment']['raw_warehouse']);
        }
    }

    public function test_existing_approved_different_warehouse_becomes_source_conflict(): void
    {
        $candidate = $this->candidate();
        $candidate['evidence']['warehouse_id'] = 2;
        $candidate['evidence']['warehouse_approved'] = true;
        $result = $this->adapter()->enrichTencentFulfillment(
            $candidate,
            $this->row('C28518', 'TRACK-1', 'USA 美西仓'),
        );

        self::assertSame(2, $result['evidence']['warehouse_id']);
        self::assertTrue($result['conflicts']['source_evidence_conflict']);
    }

    public function test_same_warehouse_is_idempotent(): void
    {
        $row = $this->row('C28518', 'TRACK-1', 'Factory 国内仓');
        $once = $this->adapter()->enrichTencentFulfillment($this->candidate(), $row);
        $twice = $this->adapter()->enrichTencentFulfillment($once, $row);

        self::assertSame($once, $twice);
        self::assertSame(5, $twice['evidence']['warehouse_id']);
        self::assertTrue($twice['evidence']['warehouse_approved']);
    }
}
