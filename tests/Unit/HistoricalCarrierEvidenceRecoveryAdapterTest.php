<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\HistoricalCarrierEvidenceRecoveryAdapter;
use App\Modules\Logistics\Application\HistoricalFulfillmentReconciliationClassifier;
use PHPUnit\Framework\TestCase;

final class HistoricalCarrierEvidenceRecoveryAdapterTest extends TestCase
{
    public function test_speeda_plain_customer_number_recovers_exact_service_without_warehouse_inference(): void
    {
        $candidate = $this->adapter()->enrichSpeeda(
            $this->candidate('4PX3002122671486CN', null),
            $this->speedaRow('C29318', '4PX3002122671486CN', 'SD经济挂号-带电'),
        );

        self::assertSame('4PX', $candidate['evidence']['carrier']);
        self::assertSame('SD经济挂号-带电', $candidate['evidence']['service']);
        self::assertTrue($candidate['evidence']['carrier_service_approved']);
        self::assertNull($candidate['evidence']['warehouse_id']);
        self::assertFalse($candidate['evidence']['warehouse_approved']);
        self::assertStringContainsString('speeda_export', $candidate['evidence_source']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $candidate['evidence']['source_speeda']['row_sha256']);

        $result = $this->classifier()->classify($candidate);
        self::assertSame('CUSTOMER_REVIEW_MISSING_EVIDENCE', $result['classification']);
        self::assertSame(['missing_approved_warehouse'], $result['reason_codes']);
    }

    public function test_speeda_new_customer_number_also_normalizes_exactly(): void
    {
        $candidate = $this->adapter()->enrichSpeeda(
            $this->candidate('4PX3003137049178CN', '4PX', '007-0907-c40227'),
            $this->speedaRow('C40227-007', '4PX3003137049178CN', 'SD标准挂号-带电', '007-0907-c40227'),
        );

        self::assertSame('SD标准挂号-带电', $candidate['evidence']['service']);
        self::assertTrue($candidate['evidence']['carrier_service_approved']);
    }

    public function test_speeda_letter_suffix_or_tracking_mismatch_is_not_auto_normalized(): void
    {
        $base = $this->candidate('4PX3002172123355CN', '4PX', '007-0913-c28401');
        $suffix = $this->adapter()->enrichSpeeda($base, $this->speedaRow('C28401B', '4PX3002172123355CN', 'SD经济挂号-带电'));
        $wrongTracking = $this->adapter()->enrichSpeeda($base, $this->speedaRow('C28401', 'DIFFERENT', 'SD经济挂号-带电'));

        self::assertSame($base, $suffix);
        self::assertSame($base, $wrongTracking);
    }

    public function test_speeda_conflicting_existing_carrier_fails_closed_as_class_e(): void
    {
        $candidate = $this->adapter()->enrichSpeeda(
            $this->candidate('4PX3002122671486CN', 'USPS'),
            $this->speedaRow('C29318', '4PX3002122671486CN', 'SD经济挂号-带电'),
        );
        $result = $this->classifier()->classify($candidate);

        self::assertSame('CUSTOMER_REVIEW_CONFLICT', $result['classification']);
        self::assertContains('source_evidence_conflict', $result['reason_codes']);
        self::assertSame('USPS', $candidate['evidence']['carrier']);
    }

    public function test_weship_exact_waybill_recovers_carrier_but_service_remains_missing(): void
    {
        $candidate = $this->adapter()->enrichWeship(
            $this->candidate('WSHMX0216843230YQ', null, '007-0911-c120911'),
            $this->weshipRow('C120911', 'WSHMX0216843230YQ', 'JMX101606052877'),
        );
        $result = $this->classifier()->classify($candidate);

        self::assertSame('Weship', $candidate['evidence']['carrier']);
        self::assertNull($candidate['evidence']['service']);
        self::assertFalse($candidate['evidence']['carrier_service_approved']);
        self::assertNull($candidate['evidence']['warehouse_id']);
        self::assertSame('CUSTOMER_REVIEW_MISSING_EVIDENCE', $result['classification']);
        self::assertContains('missing_approved_carrier_service', $result['reason_codes']);
        self::assertContains('missing_approved_warehouse', $result['reason_codes']);
    }

    public function test_weship_exact_provider_reference_is_supported_without_using_signin_as_ship_time(): void
    {
        $candidate = $this->candidate('JMX101606052877', null, '007-0911-c120911');
        $candidate['evidence']['shipped_at'] = null;
        $candidate['evidence']['shipped_at_source_backed'] = false;
        $candidate = $this->adapter()->enrichWeship(
            $candidate,
            $this->weshipRow('C120911-007', 'WSHMX0216843230YQ', 'JMX101606052877'),
        );

        self::assertNull($candidate['evidence']['shipped_at']);
        self::assertSame('2026-09-11 10:00:00', $candidate['evidence']['source_weship']['signed_in_at']);
        self::assertContains('missing_source_backed_shipped_at', $this->classifier()->classify($candidate)['reason_codes']);
    }

    public function test_weship_compound_tracking_is_not_split_and_conflicting_carrier_is_class_e(): void
    {
        $compound = $this->candidate('WSHBR1625245520YQND625564891BR', null, '007-0610-c22239');
        $row = $this->weshipRow('C22239', 'WSHBR1625245520YQ', 'ND625564891BR');
        self::assertSame($compound, $this->adapter()->enrichWeship($compound, $row));

        $conflicting = $this->adapter()->enrichWeship(
            $this->candidate('WSHMX0216843230YQ', '4PX', '007-0911-c120911'),
            $this->weshipRow('C120911', 'WSHMX0216843230YQ', 'JMX101606052877'),
        );
        $result = $this->classifier()->classify($conflicting);
        self::assertSame('CUSTOMER_REVIEW_CONFLICT', $result['classification']);
        self::assertContains('source_evidence_conflict', $result['reason_codes']);
    }

    public function test_speeda_enrichment_is_idempotent(): void
    {
        $row = $this->speedaRow('C29318', '4PX3002122671486CN', 'SD经济挂号-带电');
        $once = $this->adapter()->enrichSpeeda($this->candidate('4PX3002122671486CN', null), $row);
        $twice = $this->adapter()->enrichSpeeda($once, $row);

        self::assertSame($once, $twice);
    }

    private function adapter(): HistoricalCarrierEvidenceRecoveryAdapter
    {
        return new HistoricalCarrierEvidenceRecoveryAdapter;
    }

    private function classifier(): HistoricalFulfillmentReconciliationClassifier
    {
        return new HistoricalFulfillmentReconciliationClassifier;
    }

    /** @return array<string,mixed> */
    private function candidate(string $tracking, ?string $carrier, string $erpOrderId = '007-1007-c29318'): array
    {
        preg_match('/-c(\d+)$/i', $erpOrderId, $m);

        return [
            'candidate_key' => 'woo:'.($m[1] ?? '0').':'.$tracking,
            'evidence_source' => 'woocommerce_tracking_meta',
            'historical_non_customer' => false,
            'order' => ['id' => 1001, 'erp_order_id' => $erpOrderId, 'order_kind' => 'customer', 'order_status' => 'shipped'],
            'identity' => ['exact' => true, 'candidate_order_ids' => [1001]],
            'existing_shipment' => null,
            'attempts' => ['contradictory' => false],
            'conflicts' => [
                'tracking_collision' => false,
                'duplicate_tracking' => false,
                'ambiguous_order' => false,
                'ambiguous_shipment' => false,
            ],
            'evidence' => [
                'tracking_number' => $tracking,
                'tracking_exact' => true,
                'warehouse_id' => null,
                'warehouse_approved' => false,
                'carrier' => $carrier,
                'service' => null,
                'carrier_service_approved' => false,
                'shipped_at' => '2026-09-01',
                'shipped_at_source_backed' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function speedaRow(string $order, string $tracking, string $service): array
    {
        return [
            '状态' => '已收货订单',
            '原单号' => $order,
            '转单号' => $tracking,
            '运输方式' => $service,
            '创建时间' => '2026-09-01 10:00:00',
            '出货时间' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function weshipRow(string $order, string $waybill, string $providerReference): array
    {
        return [
            '客户单号' => $order,
            '运单号' => $waybill,
            '服务商单号' => $providerReference,
            '操作状态' => '交航',
            '签入时间' => '2026-09-11 10:00:00',
        ];
    }
}
