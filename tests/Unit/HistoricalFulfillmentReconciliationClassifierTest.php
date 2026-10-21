<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\HistoricalFulfillmentReconciliationClassifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HistoricalFulfillmentReconciliationClassifierTest extends TestCase
{
    private HistoricalFulfillmentReconciliationClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new HistoricalFulfillmentReconciliationClassifier;
    }

    public function test_complete_exact_customer_evidence_is_ready(): void
    {
        $result = $this->classifier->classify($this->candidate());

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::READY, $result['classification']);
        $this->assertSame('ready', $result['decision']);
        $this->assertSame(['all_required_exact_evidence_present'], $result['reason_codes']);
        $this->assertArrayNotHasKey('confidence', $result);
    }

    public function test_existing_identical_shipment_is_already_canonical(): void
    {
        $candidate = $this->candidate();
        $candidate['existing_shipment'] = $candidate['evidence'];
        $result = $this->classifier->classify($candidate);

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::ALREADY_CANONICAL, $result['classification']);
        $this->assertSame('skip', $result['decision']);
    }

    public function test_historical_non_customer_is_isolated(): void
    {
        $candidate = $this->candidate();
        $candidate['historical_non_customer'] = true;
        $result = $this->classifier->classify($candidate);

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::HISTORICAL_NON_CUSTOMER, $result['classification']);
        $this->assertSame('skip', $result['decision']);
    }

    public function test_missing_required_facts_is_review(): void
    {
        $candidate = $this->candidate();
        $candidate['evidence']['warehouse_id'] = null;
        $candidate['evidence']['service'] = null;
        $result = $this->classifier->classify($candidate);

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::MISSING_EVIDENCE, $result['classification']);
        $this->assertSame('review', $result['decision']);
        $this->assertSame(['missing_approved_warehouse', 'missing_approved_carrier_service'], $result['reason_codes']);
    }

    public function test_existing_shipment_disagreement_is_conflict(): void
    {
        $candidate = $this->candidate();
        $candidate['existing_shipment'] = $candidate['evidence'];
        $candidate['existing_shipment']['tracking_number'] = 'DIFFERENT';
        $result = $this->classifier->classify($candidate);

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::CONFLICT, $result['classification']);
        $this->assertContains('existing_shipment_conflict', $result['reason_codes']);
    }

    public function test_tracking_collision_or_attempt_contradiction_is_conflict(): void
    {
        $candidate = $this->candidate();
        $candidate['conflicts']['tracking_collision'] = true;
        $candidate['attempts']['contradictory'] = true;
        $result = $this->classifier->classify($candidate);

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::CONFLICT, $result['classification']);
        $this->assertSame(['tracking_collision', 'contradictory_shipment_attempt'], $result['reason_codes']);
    }

    public function test_missing_order_is_orphan_evidence(): void
    {
        $candidate = $this->candidate();
        $candidate['order'] = null;
        $candidate['identity'] = ['exact' => false, 'candidate_order_ids' => []];
        $result = $this->classifier->classify($candidate);

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::ORPHAN, $result['classification']);
        $this->assertSame('review', $result['decision']);
    }

    public function test_duplicate_or_ambiguous_mapping_fails_closed(): void
    {
        $candidate = $this->candidate();
        $candidate['conflicts']['duplicate_tracking'] = true;
        $candidate['identity']['candidate_order_ids'] = [1001, 1002];
        $result = $this->classifier->classify($candidate);

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::AMBIGUOUS, $result['classification']);
        $this->assertSame('review', $result['decision']);
    }

    public function test_non_customer_order_is_skipped(): void
    {
        $candidate = $this->candidate();
        $candidate['order']['order_kind'] = 'kol_fulfillment';
        $result = $this->classifier->classify($candidate);

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::HISTORICAL_NON_CUSTOMER, $result['classification']);
    }

    public function test_missing_exact_identity_is_missing_evidence_not_conflict(): void
    {
        $candidate = $this->candidate();
        $candidate['identity'] = ['exact' => false, 'candidate_order_ids' => []];
        $result = $this->classifier->classify($candidate);

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::MISSING_EVIDENCE, $result['classification']);
        $this->assertContains('missing_exact_order_identity', $result['reason_codes']);
    }

    public function test_unapproved_hint_does_not_create_existing_shipment_conflict(): void
    {
        $candidate = $this->candidate();
        $candidate['existing_shipment'] = $candidate['evidence'];
        $candidate['evidence']['warehouse_id'] = 999;
        $candidate['evidence']['warehouse_approved'] = false;
        $result = $this->classifier->classify($candidate);

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::ALREADY_CANONICAL, $result['classification']);
    }

    public function test_existing_shipment_date_difference_does_not_override_canonical_tracking_agreement(): void
    {
        $candidate = $this->candidate();
        $candidate['existing_shipment'] = $candidate['evidence'];
        $candidate['existing_shipment']['shipped_at'] = '2026-08-31 18:00:00';
        $result = $this->classifier->classify($candidate);

        $this->assertSame(HistoricalFulfillmentReconciliationClassifier::ALREADY_CANONICAL, $result['classification']);
        $this->assertSame('skip', $result['decision']);
    }

    public function test_candidate_key_and_source_are_required(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $candidate = $this->candidate();
        $candidate['candidate_key'] = '';
        $this->classifier->classify($candidate);
    }

    /** @return array<string,mixed> */
    private function candidate(): array
    {
        return [
            'candidate_key' => 'woo:40415:TRACK-1',
            'evidence_source' => 'woocommerce_tracking_meta',
            'historical_non_customer' => false,
            'order' => [
                'id' => 1001,
                'erp_order_id' => '007-0915-c40415',
                'order_kind' => 'customer',
                'order_status' => 'shipped',
            ],
            'identity' => [
                'exact' => true,
                'candidate_order_ids' => [1001],
            ],
            'existing_shipment' => null,
            'attempts' => ['contradictory' => false],
            'conflicts' => [
                'tracking_collision' => false,
                'duplicate_tracking' => false,
                'ambiguous_order' => false,
                'ambiguous_shipment' => false,
            ],
            'evidence' => [
                'tracking_number' => 'TRACK-1',
                'tracking_exact' => true,
                'warehouse_id' => 2,
                'warehouse_approved' => true,
                'carrier' => 'USPS',
                'service' => 'Priority Mail',
                'carrier_service_approved' => true,
                'shipped_at' => '2026-09-01 12:00:00',
                'shipped_at_source_backed' => true,
            ],
        ];
    }
}
