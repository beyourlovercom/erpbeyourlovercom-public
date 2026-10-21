<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Application\ProductShippingWeightStandardReader;
use App\Modules\Products\Application\ShippingWeightStandardCandidateService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductShippingWeightStandardCandidate;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class ShippingWeightStandardCandidateTest extends TestCase
{
    use RefreshDatabase;

    private ShippingWeightStandardCandidateService $service;
    private ProductShippingWeightStandardReader $reader;
    private int $reviewerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ShippingWeightStandardCandidateService();
        $this->reader = new ProductShippingWeightStandardReader();
        $this->reviewerId = User::factory()->create()->id;
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Test Product',
            'source_type' => 'manual',
            'product_type' => 'simple',
            'is_active' => true,
        ]);
    }

    private function shippingEvidence(Product $product, float $weight, string $status = 'pending'): ProductWeightEvidence
    {
        return ProductWeightEvidence::create([
            'product_id' => $product->id,
            'weight_kind' => 'shipping_reference_weight',
            'observed_weight_kg' => $weight,
            'source_type' => 'logistics_scan',
            'status' => $status,
        ]);
    }

    private function create(Product $product, array $weights, string $status = 'pending'): ProductShippingWeightStandardCandidate
    {
        $ids = array_map(fn ($w) => $this->shippingEvidence($product, $w, $status)->id, $weights);
        return $this->service->createFromEvidenceIds($product->id, $ids);
    }

    // ── Calculation / outliers ────────────────────────────────────────────────

    public function test_median_calculation_odd_count(): void
    {
        $p = $this->product();
        $c = $this->create($p, [1.8, 2.0, 2.2]);
        $this->assertEquals('2.0000', $c->suggested_weight_kg);
        $this->assertEquals(3, $c->raw_sample_count);
        $this->assertEquals(3, $c->eligible_sample_count);
        $this->assertEquals(0, $c->gross_outlier_count);
    }

    public function test_median_calculation_even_count(): void
    {
        $p = $this->product();
        $c = $this->create($p, [2.0, 2.1, 2.2, 2.3]);
        $this->assertEquals('2.1500', $c->suggested_weight_kg);
    }

    public function test_gross_outlier_excluded_from_eligible(): void
    {
        $p = $this->product();
        // 1.0, 1.0, 1.1 are close; 5.0 is a gross outlier (deviation > 15% from raw median ~1.0)
        $c = $this->create($p, [1.0, 1.0, 1.1, 5.0]);
        $this->assertEquals(4, $c->raw_sample_count);
        $this->assertEquals(1, $c->gross_outlier_count);
        $this->assertEquals(3, $c->eligible_sample_count);
        // Eligible median of [1.0, 1.0, 1.1] = 1.0
        $this->assertEquals('1.0000', $c->suggested_weight_kg);
    }

    public function test_spread_ratio_uses_suggested_weight_as_denominator(): void
    {
        $p = $this->product();
        // All eligible: suggested = 1.0, spread = (1.1-0.9)/1.0 = 0.2.
        // Using eligible_min as the denominator would incorrectly produce ~0.2222.
        $c = $this->create($p, [0.9, 1.0, 1.1]);
        $this->assertEquals('0.200000', $c->eligible_spread_ratio);
    }

    // ── Confidence ────────────────────────────────────────────────────────────

    public function test_high_confidence_requires_five_eligible_and_tight_spread(): void
    {
        $p = $this->product();
        // spread = (1.03-1.00)/1.01 ≈ 0.0297 <= 10%, eligible = 5
        $c = $this->create($p, [1.00, 1.02, 1.01, 1.03, 1.00]);
        $this->assertEquals('high_confidence', $c->confidence_status);
    }

    public function test_review_required_when_fewer_than_five_eligible(): void
    {
        $p = $this->product();
        $c = $this->create($p, [1.0, 1.1, 1.2, 1.3]);
        $this->assertEquals('review_required', $c->confidence_status);
    }

    public function test_review_required_when_spread_exceeds_threshold(): void
    {
        $p = $this->product();
        // All 5 are within the 15% outlier gate; spread = (1.10-0.90)/1.00 = 20% > 10%.
        $c = $this->create($p, [0.90, 0.95, 1.00, 1.05, 1.10]);
        $this->assertEquals('review_required', $c->confidence_status);
    }

    // ── Deduplication ─────────────────────────────────────────────────────────

    public function test_duplicate_evidence_ids_are_deduped(): void
    {
        $p = $this->product();
        $e1 = $this->shippingEvidence($p, 1.0);
        $e2 = $this->shippingEvidence($p, 1.1);
        $c = $this->service->createFromEvidenceIds($p->id, [$e1->id, $e2->id, $e1->id, $e2->id]);
        $this->assertEquals(2, $c->raw_sample_count);
        $this->assertEquals(2, $c->evidenceLinks()->count());
    }

    // ── Fingerprint idempotency ────────────────────────────────────────────────

    public function test_same_input_returns_existing_candidate(): void
    {
        $p = $this->product();
        $e1 = $this->shippingEvidence($p, 1.0);
        $e2 = $this->shippingEvidence($p, 1.1);
        $c1 = $this->service->createFromEvidenceIds($p->id, [$e1->id, $e2->id]);
        $c2 = $this->service->createFromEvidenceIds($p->id, [$e2->id, $e1->id]); // different order
        $this->assertEquals($c1->id, $c2->id);
        $this->assertEquals(1, ProductShippingWeightStandardCandidate::count());
    }

    // ── Fail-closed validation ─────────────────────────────────────────────────

    public function test_fails_on_empty_ids(): void
    {
        $p = $this->product();
        $this->expectException(RuntimeException::class);
        $this->service->createFromEvidenceIds($p->id, []);
    }

    public function test_fails_on_nonexistent_evidence_id(): void
    {
        $p = $this->product();
        $this->expectException(RuntimeException::class);
        $this->service->createFromEvidenceIds($p->id, [99999]);
    }

    public function test_fails_when_evidence_belongs_to_different_product(): void
    {
        $p1 = $this->product();
        $p2 = $this->product();
        $e = $this->shippingEvidence($p2, 1.0);
        $this->expectException(RuntimeException::class);
        $this->service->createFromEvidenceIds($p1->id, [$e->id]);
    }

    public function test_fails_on_wrong_weight_kind(): void
    {
        $p = $this->product();
        $e = ProductWeightEvidence::create([
            'product_id' => $p->id,
            'weight_kind' => 'product_weight',
            'observed_weight_kg' => 1.0,
            'source_type' => 'manual',
            'status' => 'pending',
        ]);
        $this->expectException(RuntimeException::class);
        $this->service->createFromEvidenceIds($p->id, [$e->id]);
    }

    public function test_fails_on_rejected_evidence(): void
    {
        $p = $this->product();
        $e = $this->shippingEvidence($p, 1.0, 'rejected');
        $this->expectException(RuntimeException::class);
        $this->service->createFromEvidenceIds($p->id, [$e->id]);
    }

    // ── Adoption / supersede / raw-status-unchanged ───────────────────────────

    public function test_adoption_returns_null_on_success(): void
    {
        $p = $this->product();
        $c = $this->create($p, [1.9, 2.0, 2.1]);
        $result = $this->service->adopt($c->id, $this->reviewerId, confirmed: true);
        $this->assertNull($result);
    }

    public function test_adoption_sets_product_shipping_weight_and_clears_evidence_id(): void
    {
        $p = $this->product();
        $c = $this->create($p, [1.9, 2.0, 2.1]);
        $this->service->adopt($c->id, $this->reviewerId, confirmed: true);
        $p->refresh();
        $this->assertEquals($c->id, $p->shipping_weight_standard_candidate_id);
        $this->assertNull($p->shipping_weight_evidence_id);
        $this->assertEquals('2.0000', $p->shipping_weight_kg);
    }

    public function test_adoption_returns_confirm_required_string_when_replacing_existing_weight(): void
    {
        $p = $this->product();
        $p->update(['shipping_weight_kg' => 1.5]);
        $c = $this->create($p, [1.9, 2.0, 2.1]);
        $result = $this->service->adopt($c->id, $this->reviewerId, confirmed: false);
        $this->assertSame('confirm_required', $result);
    }

    public function test_previous_pointed_adopted_candidate_superseded_on_new_adoption(): void
    {
        $p = $this->product();
        $e1 = $this->shippingEvidence($p, 1.0);
        $c1 = $this->service->createFromEvidenceIds($p->id, [$e1->id]);
        $this->service->adopt($c1->id, $this->reviewerId, confirmed: true);

        $e2 = $this->shippingEvidence($p, 1.1);
        $e3 = $this->shippingEvidence($p, 1.2);
        $c2 = $this->service->createFromEvidenceIds($p->id, [$e2->id, $e3->id]);
        $this->service->adopt($c2->id, $this->reviewerId, confirmed: true);

        $c1->refresh();
        $this->assertEquals('superseded', $c1->workflow_status);

        $c2->refresh();
        $this->assertEquals('adopted', $c2->workflow_status);
    }

    public function test_only_product_pointed_candidate_is_superseded_not_others(): void
    {
        $p = $this->product();
        $e1 = $this->shippingEvidence($p, 1.0);
        $e2 = $this->shippingEvidence($p, 2.0);
        $e3 = $this->shippingEvidence($p, 3.0);

        // Manually create a second candidate that is adopted but NOT pointed to by the product
        $c_orphan = $this->service->createFromEvidenceIds($p->id, [$e1->id]);
        $this->service->adopt($c_orphan->id, $this->reviewerId, confirmed: true);

        // Now adopt a second one — first adopt pointed by product
        $c_pointed = $this->service->createFromEvidenceIds($p->id, [$e2->id]);
        $this->service->adopt($c_pointed->id, $this->reviewerId, confirmed: true);

        // Now adopt a third — only c_pointed (the one currently pointed) should be superseded
        $c_new = $this->service->createFromEvidenceIds($p->id, [$e3->id]);
        $this->service->adopt($c_new->id, $this->reviewerId, confirmed: true);

        $c_orphan->refresh();
        $c_pointed->refresh();
        $c_new->refresh();

        // c_orphan was adopted then immediately superseded when c_pointed was adopted (it was pointed then)
        // c_pointed was pointed when c_new was adopted, so it gets superseded
        $this->assertEquals('superseded', $c_pointed->workflow_status);
        $this->assertEquals('adopted', $c_new->workflow_status);
    }

    public function test_raw_evidence_status_unchanged_after_adoption(): void
    {
        $p = $this->product();
        $e = $this->shippingEvidence($p, 1.0, 'adopted');
        $c = $this->service->createFromEvidenceIds($p->id, [$e->id]);
        $this->service->adopt($c->id, $this->reviewerId, confirmed: true);
        $e->refresh();
        $this->assertEquals('adopted', $e->status);
    }

    public function test_rejection_sets_metadata(): void
    {
        $p = $this->product();
        $c = $this->create($p, [1.0]);
        $result = $this->service->reject($c->id, $this->reviewerId, 'Bad data');
        $this->assertEquals('rejected', $result->workflow_status);
        $this->assertEquals('Bad data', $result->rejection_note);
        $this->assertNotNull($result->reviewed_at);
    }

    public function test_rejection_note_may_be_null(): void
    {
        $p = $this->product();
        $c = $this->create($p, [1.0]);
        $result = $this->service->reject($c->id, $this->reviewerId, null);
        $this->assertEquals('rejected', $result->workflow_status);
        $this->assertNull($result->rejection_note);
    }

    // ── Reader: aggregate priority / legacy fallback / invalid candidate blocked ─

    public function test_reader_returns_aggregated_source_when_adopted_candidate(): void
    {
        $p = $this->product();
        $c = $this->create($p, [1.9, 2.0, 2.1]);
        $this->service->adopt($c->id, $this->reviewerId, confirmed: true);

        $result = $this->reader->forProductIds([$p->id]);
        $this->assertArrayHasKey($p->id, $result);
        $this->assertEquals('aggregated_logistics_standard', $result[$p->id]['source_type']);
        $this->assertEquals($c->id, $result[$p->id]['standard_candidate_id']);
    }

    public function test_reader_falls_back_to_legacy_evidence_when_no_candidate(): void
    {
        $p = $this->product();
        $e = $this->shippingEvidence($p, 1.5, 'adopted');
        $p->update(['shipping_weight_kg' => 1.5, 'shipping_weight_evidence_id' => $e->id]);

        $result = $this->reader->forProductIds([$p->id]);
        $this->assertArrayHasKey($p->id, $result);
        $this->assertEquals('logistics_scan', $result[$p->id]['source_type']);
    }

    public function test_reader_excludes_pending_candidate(): void
    {
        $p = $this->product();
        $this->create($p, [1.0]); // pending, product has no weight
        $result = $this->reader->forProductIds([$p->id]);
        $this->assertArrayNotHasKey($p->id, $result);
    }

    public function test_reader_blocks_candidate_pointing_to_wrong_product(): void
    {
        $p1 = $this->product();
        $p2 = $this->product();
        $c = $this->create($p1, [1.0]);
        $this->service->adopt($c->id, $this->reviewerId, confirmed: true);

        // Manually point p2 to p1's adopted candidate (simulates corrupt/invalid state)
        $p2->update(['shipping_weight_kg' => 1.0, 'shipping_weight_standard_candidate_id' => $c->id]);

        // Reader join checks c.product_id = p.id, so p2 should not resolve via aggregated path
        $result = $this->reader->forProductIds([$p2->id]);
        $this->assertArrayNotHasKey($p2->id, $result);
    }

    public function test_mysql_schema_identifiers_are_explicit_and_within_limit(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_09_100000_create_product_shipping_weight_standard_candidates_table.php'));
        $this->assertIsString($migration);
        $this->assertStringNotContainsString('->constrained(', $migration);

        $identifiers = [
            'pswsc_fingerprint_uq',
            'pswsc_product_workflow_idx',
            'pswsc_confidence_idx',
            'pswsc_product_fk',
            'pswsc_created_by_fk',
            'pswsc_reviewed_by_fk',
            'pswsce_candidate_evidence_uq',
            'pswsce_candidate_fk',
            'pswsce_weight_evidence_fk',
            'products_sw_candidate_fk',
        ];

        foreach ($identifiers as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), $identifier);
            $this->assertStringContainsString("'{$identifier}'", $migration);
        }
    }

}
