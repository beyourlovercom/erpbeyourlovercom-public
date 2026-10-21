<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\HistoricalFulfillmentReconciliationClassifier;
use App\Modules\Logistics\Application\WooHistoricalFulfillmentEvidenceAdapter;
use PHPUnit\Framework\TestCase;

final class WooHistoricalFulfillmentEvidenceAdapterTest extends TestCase
{
    public function test_single_exact_package_stays_review_without_historical_warehouse_and_service_authority(): void
    {
        $candidate = $this->adaptOne($this->order(), $this->woo([
            $this->package('ABC123', '4px', '2026-04-02', [['id' => 91, 'quantity' => 2]]),
        ]));
        $result = $this->classifier()->classify($candidate);

        self::assertSame('CUSTOMER_REVIEW_MISSING_EVIDENCE', $result['classification']);
        self::assertSame('review', $result['decision']);
        self::assertSame('4PX', $result['proposed_shipment']['carrier']);
        self::assertNull($result['proposed_shipment']['warehouse_id']);
        self::assertNull($result['proposed_shipment']['service']);
        self::assertSame('2026-04-02', $result['proposed_shipment']['shipped_at']);
        self::assertSame([['id' => 91, 'quantity' => 2]], $candidate['evidence']['source_line_items']);
    }

    public function test_no_tracking_is_missing_evidence_and_never_invented(): void
    {
        $candidate = $this->adaptOne($this->order(), $this->woo([]));
        $result = $this->classifier()->classify($candidate);

        self::assertSame('CUSTOMER_REVIEW_MISSING_EVIDENCE', $result['classification']);
        self::assertContains('missing_exact_tracking', $result['reason_codes']);
        self::assertContains('missing_source_backed_shipped_at', $result['reason_codes']);
        self::assertNull($result['proposed_shipment']['tracking_number']);
    }

    public function test_woocommerce_identity_mismatch_is_orphan_evidence(): void
    {
        $order = $this->order();
        $order['woocommerce_reference'] = '99999';
        $candidate = $this->adaptOne($order, $this->woo([$this->package('ABC123')]));
        $result = $this->classifier()->classify($candidate);

        self::assertSame('ORPHAN_EVIDENCE', $result['classification']);
        self::assertSame('review', $result['decision']);
        self::assertNull($result['order_identity']);
    }

    public function test_duplicate_tracking_fails_closed_as_ambiguous(): void
    {
        $candidate = $this->adaptOne(
            $this->order(),
            $this->woo([$this->package('DUP123')]),
            ['duplicate_tracking_numbers' => ['dup123']],
        );
        $result = $this->classifier()->classify($candidate);

        self::assertSame('DUPLICATE_OR_AMBIGUOUS', $result['classification']);
        self::assertSame(['duplicate_or_ambiguous_mapping'], $result['reason_codes']);
    }

    public function test_tracking_collision_is_conflict(): void
    {
        $candidate = $this->adaptOne(
            $this->order(),
            $this->woo([$this->package('COLLIDE1')]),
            ['tracking_collision_numbers' => ['COLLIDE1']],
        );
        $result = $this->classifier()->classify($candidate);

        self::assertSame('CUSTOMER_REVIEW_CONFLICT', $result['classification']);
        self::assertContains('tracking_collision', $result['reason_codes']);
    }

    public function test_multi_package_without_exact_package_targets_is_missing_evidence(): void
    {
        $candidates = $this->adapter()->adapt($this->order(), $this->woo([
            $this->package('PKG-A', '4px', '2026-04-02', [['id' => 11, 'quantity' => 1]]),
            $this->package('PKG-B', 'usps', '2026-04-02', [['id' => 22, 'quantity' => 1]]),
        ]));

        self::assertCount(2, $candidates);
        foreach ($candidates as $candidate) {
            $result = $this->classifier()->classify($candidate);
            self::assertSame('CUSTOMER_REVIEW_MISSING_EVIDENCE', $result['classification']);
            self::assertContains('missing_exact_order_identity', $result['reason_codes']);
        }
    }

    public function test_multi_package_exact_targets_remain_missing_evidence_not_ambiguous(): void
    {
        $candidates = $this->adapter()->adapt($this->order(), $this->woo([
            $this->package('PKG-A', '4px', '2026-04-02', [['id' => 11, 'quantity' => 1]]),
            $this->package('PKG-B', 'usps', '2026-04-02', [['id' => 22, 'quantity' => 1]]),
        ]), ['package_targets_by_tracking' => [
            'PKG-A' => $this->order(701, '007-0402-c40415A'),
            'PKG-B' => $this->order(702, '007-0402-c40415B'),
        ]]);
        self::assertSame(701, $candidates[0]['order']['id']);
        self::assertSame(702, $candidates[1]['order']['id']);
        foreach ($candidates as $candidate) {
            $result = $this->classifier()->classify($candidate);
            self::assertSame('CUSTOMER_REVIEW_MISSING_EVIDENCE', $result['classification']);
            self::assertNotContains('duplicate_or_ambiguous_mapping', $result['reason_codes']);
        }
    }

    public function test_existing_shipment_agreement_is_already_canonical_and_mismatch_is_conflict(): void
    {
        $woo = $this->woo([$this->package('MATCH123', '4px', '2026-04-02')]);
        $matching = $this->adaptOne($this->order(), $woo, [
            'existing_shipment' => ['tracking_number' => 'MATCH123', 'shipped_at' => '2026-04-02'],
        ]);
        $conflicting = $this->adaptOne($this->order(), $woo, [
            'existing_shipment' => ['tracking_number' => 'OTHER999', 'shipped_at' => '2026-04-02'],
        ]);

        self::assertSame('ALREADY_CANONICAL', $this->classifier()->classify($matching)['classification']);
        self::assertSame('CUSTOMER_REVIEW_CONFLICT', $this->classifier()->classify($conflicting)['classification']);
    }

    public function test_non_customer_order_is_isolated(): void
    {
        $order = $this->order();
        $order['order_kind'] = 'kol_fulfillment';
        $candidate = $this->adaptOne($order, $this->woo([$this->package('KOL123')]));

        self::assertSame('HISTORICAL_NON_CUSTOMER', $this->classifier()->classify($candidate)['classification']);
    }

    public function test_legacy_aftership_fields_are_supported_without_inventing_ship_date(): void
    {
        $woo = ['id' => 40415, 'meta_data' => [
            ['key' => '_aftership_tracking_number', 'value' => 'legacy-123'],
            ['key' => '_aftership_tracking_provider_name', 'value' => 'usps'],
        ]];
        $candidate = $this->adaptOne($this->order(), $woo);
        $result = $this->classifier()->classify($candidate);

        self::assertSame('LEGACY-123', $candidate['evidence']['tracking_number']);
        self::assertSame('USPS', $candidate['evidence']['carrier']);
        self::assertNull($candidate['evidence']['shipped_at']);
        self::assertContains('missing_source_backed_shipped_at', $result['reason_codes']);
    }

    public function test_existing_single_shipment_on_multi_package_order_only_covers_matching_package(): void
    {
        $candidates = $this->adapter()->adapt($this->order(), $this->woo([
            $this->package('MATCH123', '4px', '2026-04-02', [['id' => 11, 'quantity' => 1]]),
            $this->package('EXTRA456', 'usps', '2026-04-02', [['id' => 22, 'quantity' => 1]]),
        ]), ['existing_shipment' => ['tracking_number' => 'MATCH123', 'shipped_at' => '2026-04-02']]);

        $results = array_map(fn (array $candidate): array => $this->classifier()->classify($candidate), $candidates);
        self::assertSame('ALREADY_CANONICAL', $results[0]['classification']);
        self::assertSame('CUSTOMER_REVIEW_MISSING_EVIDENCE', $results[1]['classification']);
        self::assertNotContains('existing_shipment_conflict', $results[1]['reason_codes']);
    }

    /** @param array<string,mixed> $context */
    private function adaptOne(array $order, array $woo, array $context = []): array
    {
        $candidates = $this->adapter()->adapt($order, $woo, $context);
        self::assertCount(1, $candidates);

        return $candidates[0];
    }

    private function adapter(): WooHistoricalFulfillmentEvidenceAdapter
    {
        return new WooHistoricalFulfillmentEvidenceAdapter([
            ['value' => '4PX', 'aftership_slug' => '4px', 'aliases' => ['4px express']],
            ['value' => 'USPS', 'aftership_slug' => 'usps', 'aliases' => ['united states postal service']],
        ]);
    }

    private function classifier(): HistoricalFulfillmentReconciliationClassifier
    {
        return new HistoricalFulfillmentReconciliationClassifier;
    }

    /** @return array<string,mixed> */
    private function order(int $id = 77, string $erpOrderId = '007-0402-c40415'): array
    {
        return [
            'id' => $id, 'erp_order_id' => $erpOrderId, 'order_kind' => 'customer',
            'order_status' => 'shipped', 'woocommerce_reference' => '40415',
        ];
    }

    /** @param list<array<string,mixed>> $packages @return array<string,mixed> */
    private function woo(array $packages): array
    {
        return ['id' => 40415, 'meta_data' => [
            ['key' => '_aftership_tracking_items', 'value' => $packages],
        ]];
    }

    /** @param list<array<string,mixed>> $lineItems @return array<string,mixed> */
    private function package(string $tracking, string $slug = '4px', ?string $shipDate = '2026-04-02', array $lineItems = []): array
    {
        return [
            'tracking_number' => $tracking,
            'slug' => $slug,
            'additional_fields' => $shipDate === null ? [] : ['ship_date' => $shipDate],
            'line_items' => $lineItems,
        ];
    }
}
