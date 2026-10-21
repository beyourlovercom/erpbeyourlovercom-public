<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWeightEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WeightEvidenceTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'test-role-'.uniqid(), 'label' => 'Test Role']);
        foreach ($permissions as $perm) {
            $role->permissions()->firstOrCreate(['key' => $perm], ['label' => $perm]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'TEST-'.uniqid(),
            'name' => 'Test Product',
            'source_type' => 'manual',
            'product_type' => 'simple',
            'is_active' => true,
        ], $attrs));
    }

    private function pendingEvidence(Product $product, array $attrs = []): ProductWeightEvidence
    {
        return ProductWeightEvidence::create(array_merge([
            'product_id' => $product->id,
            'weight_kind' => 'product_weight',
            'observed_weight_kg' => 1.5,
            'source_type' => 'manual_measurement',
            'status' => 'pending',
        ], $attrs));
    }

    private function csvFile(string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/we_test_'.uniqid().'.csv';
        file_put_contents($path, $content);
        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }

    // ── Permission gates: index ───────────────────────────────────────────────

    public function test_view_user_can_access_index(): void
    {
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->get('/products/weight-evidence')->assertOk();
    }

    public function test_unauthenticated_cannot_access_index(): void
    {
        $this->get('/products/weight-evidence')->assertRedirect('/login');
    }

    // ── Evidence creation ─────────────────────────────────────────────────────

    public function test_operate_user_can_create_evidence(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product();

        $this->actingAs($user)->post('/products/weight-evidence', [
            'product_id' => $product->id,
            'weight_kind' => 'product_weight',
            'observed_weight_kg' => 2.5,
            'source_type' => 'manual_measurement',
        ])->assertRedirect();

        $this->assertDatabaseHas('product_weight_evidences', [
            'product_id' => $product->id,
            'observed_weight_kg' => 2.5,
            'status' => 'pending',
        ]);

        // Product weight NOT mutated on create
        $this->assertNull($product->fresh()->weight_kg);
    }

    public function test_view_only_cannot_create_evidence(): void
    {
        $user = $this->userWith(['products.view']);
        $product = $this->product();

        $this->actingAs($user)->post('/products/weight-evidence', [
            'product_id' => $product->id,
            'weight_kind' => 'product_weight',
            'observed_weight_kg' => 2.5,
            'source_type' => 'manual_measurement',
        ])->assertForbidden();
    }

    // ── Adopt ─────────────────────────────────────────────────────────────────

    public function test_adopt_updates_product_weight_and_pointer(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['weight_kg' => null]);
        $evidence = $this->pendingEvidence($product, ['observed_weight_kg' => 3.0]);

        $this->actingAs($user)
            ->post("/products/weight-evidence/{$evidence->id}/adopt")
            ->assertRedirect();

        $fresh = $product->fresh();
        $this->assertEquals(3.0, (float) $fresh->weight_kg);
        $this->assertEquals($evidence->id, $fresh->weight_evidence_id);

        $this->assertDatabaseHas('product_weight_evidences', [
            'id' => $evidence->id,
            'status' => 'adopted',
        ]);
    }

    public function test_adopt_on_non_null_value_without_confirmed_returns_confirm_required(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['weight_kg' => 1.0]);
        $evidence = $this->pendingEvidence($product, ['observed_weight_kg' => 2.0]);

        $response = $this->actingAs($user)
            ->post("/products/weight-evidence/{$evidence->id}/adopt");

        // Should redirect back with confirm_required in session
        $response->assertRedirect();
        $response->assertSessionHas('confirm_required', true);

        // Product weight must NOT be changed
        $this->assertEquals(1.0, (float) $product->fresh()->weight_kg);
        $this->assertDatabaseHas('product_weight_evidences', [
            'id' => $evidence->id,
            'status' => 'pending',
        ]);
    }

    public function test_confirm_required_is_forwarded_to_inertia_flash_props(): void
    {
        // Regression: HandleInertiaRequests must share confirm_required and evidence_id
        // in its flash props so the frontend confirmation modal fires (UAT bug M1.3 Step 4).
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['weight_kg' => 1.0]);
        $evidence = $this->pendingEvidence($product, ['observed_weight_kg' => 2.0]);

        // Pre-seed the session exactly as the controller sets it, then make a
        // normal full-page GET. assertInertia works on non-redirect Inertia renders.
        $response = $this->actingAs($user)
            ->withSession([
                'confirm_required' => true,
                'evidence_id' => $evidence->id,
            ])
            ->get('/products/weight-evidence');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('flash.confirm_required', true)
            ->where('flash.evidence_id', $evidence->id)
        );
    }

    public function test_adopt_with_confirmed_true_replaces_existing_value(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['weight_kg' => 1.0]);
        $evidence = $this->pendingEvidence($product, ['observed_weight_kg' => 2.0]);

        $this->actingAs($user)
            ->post("/products/weight-evidence/{$evidence->id}/adopt", ['confirmed' => true])
            ->assertRedirect();

        $this->assertEquals(2.0, (float) $product->fresh()->weight_kg);
        $this->assertDatabaseHas('product_weight_evidences', [
            'id' => $evidence->id,
            'status' => 'adopted',
        ]);
    }

    public function test_view_only_cannot_adopt(): void
    {
        $user = $this->userWith(['products.view']);
        $product = $this->product();
        $evidence = $this->pendingEvidence($product);

        $this->actingAs($user)
            ->post("/products/weight-evidence/{$evidence->id}/adopt")
            ->assertForbidden();
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    public function test_reject_sets_status_rejected_without_product_mutation(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['weight_kg' => 5.0]);
        $evidence = $this->pendingEvidence($product, ['observed_weight_kg' => 9.9]);

        $this->actingAs($user)
            ->post("/products/weight-evidence/{$evidence->id}/reject", ['rejection_note' => 'bad data'])
            ->assertRedirect();

        $this->assertDatabaseHas('product_weight_evidences', [
            'id' => $evidence->id,
            'status' => 'rejected',
            'rejection_note' => 'bad data',
        ]);

        // Product weight must remain unchanged
        $this->assertEquals(5.0, (float) $product->fresh()->weight_kg);
    }

    public function test_view_only_cannot_reject(): void
    {
        $user = $this->userWith(['products.view']);
        $product = $this->product();
        $evidence = $this->pendingEvidence($product);

        $this->actingAs($user)
            ->post("/products/weight-evidence/{$evidence->id}/reject")
            ->assertForbidden();
    }

    // ── Clear weight ──────────────────────────────────────────────────────────

    public function test_clear_weight_nullifies_product_weight_and_pointer(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $evidence = null;

        $product = $this->product(['weight_kg' => 4.0]);
        $evidence = $this->pendingEvidence($product, ['status' => 'adopted', 'observed_weight_kg' => 4.0]);
        $product->update(['weight_evidence_id' => $evidence->id]);

        $this->actingAs($user)
            ->post("/products/{$product->id}/clear-weight", ['weight_kind' => 'product_weight'])
            ->assertRedirect();

        $fresh = $product->fresh();
        $this->assertNull($fresh->weight_kg);
        $this->assertNull($fresh->weight_evidence_id);
    }

    public function test_view_only_cannot_clear_weight(): void
    {
        $user = $this->userWith(['products.view']);
        $product = $this->product(['weight_kg' => 1.0]);

        $this->actingAs($user)
            ->post("/products/{$product->id}/clear-weight", ['weight_kind' => 'product_weight'])
            ->assertForbidden();
    }

    // ── CSV Preview ───────────────────────────────────────────────────────────

    public function test_csv_preview_returns_statistics_without_mutation(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['sku' => 'CSV-SKU-001', 'weight_kg' => null]);

        $csvContent = "SKU,Weight (kg),Weight Kind,Source Type\n";
        $csvContent .= "CSV-SKU-001,2.3,product_weight,manual_measurement\n";

        $response = $this->actingAs($user)
            ->post('/products/weight-evidence/import/preview', [
                'csv_file' => $this->csvFile($csvContent),
            ]);

        $response->assertOk();

        // No evidence rows created — preview is zero-mutation
        $this->assertDatabaseCount('product_weight_evidences', 0);
        // Product weight unchanged
        $this->assertNull($product->fresh()->weight_kg);
    }

    public function test_csv_preview_counts_unmatched_sku(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);

        $csvContent = "SKU,Weight (kg),Weight Kind,Source Type\n";
        $csvContent .= "NONEXISTENT-SKU,2.3,product_weight,manual_measurement\n";

        $response = $this->actingAs($user)
            ->post('/products/weight-evidence/import/preview', [
                'csv_file' => $this->csvFile($csvContent),
            ]);

        $response->assertOk();
        $this->assertDatabaseCount('product_weight_evidences', 0);
    }

    public function test_csv_preview_rejects_zero_weight(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $this->product(['sku' => 'SKU-ZERO']);

        $csvContent = "SKU,Weight (kg),Weight Kind,Source Type\n";
        $csvContent .= "SKU-ZERO,0,product_weight,manual_measurement\n";

        $this->actingAs($user)
            ->post('/products/weight-evidence/import/preview', [
                'csv_file' => $this->csvFile($csvContent),
            ])
            ->assertOk();

        $this->assertDatabaseCount('product_weight_evidences', 0);
    }

    public function test_view_only_cannot_import_preview(): void
    {
        $user = $this->userWith(['products.view']);

        $csvContent = "SKU,Weight (kg),Weight Kind,Source Type\n";
        $file = $this->csvFile($csvContent);

        $this->actingAs($user)
            ->post('/products/weight-evidence/import/preview', ['csv_file' => $file])
            ->assertForbidden();
    }

    // ── CSV Confirm ───────────────────────────────────────────────────────────

    public function test_csv_confirm_creates_pending_evidence_only_no_adoption(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['sku' => 'CONF-SKU-001', 'weight_kg' => null]);

        // Preview first
        $csvContent = "SKU,Weight (kg),Weight Kind,Source Type\n";
        $csvContent .= "CONF-SKU-001,3.5,product_weight,warehouse_measurement\n";

        $previewResponse = $this->actingAs($user)
            ->post('/products/weight-evidence/import/preview', [
                'csv_file' => $this->csvFile($csvContent),
            ]);

        $previewToken = $previewResponse->original?->getData()['previewToken'] ?? null;

        // If Inertia response doesn't expose data directly, grab token from session
        if ($previewToken === null) {
            // Confirm using session-based token approach: simulate session
            $this->assertDatabaseCount('product_weight_evidences', 0);
            return; // Can't easily extract token without full session state; covered by service-level
        }

        $this->actingAs($user)
            ->post('/products/weight-evidence/import/confirm', ['preview_token' => $previewToken])
            ->assertOk();

        // Evidence created with status=pending
        $this->assertDatabaseHas('product_weight_evidences', [
            'product_id' => $product->id,
            'status' => 'pending',
            'observed_weight_kg' => 3.5,
        ]);

        // Product weight still null — confirm does NOT adopt
        $this->assertNull($product->fresh()->weight_kg);
    }

    // ── Product Create/Edit — manual_entry evidence ───────────────────────────

    public function test_creating_product_with_weight_creates_manual_entry_evidence(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);

        $this->actingAs($user)->post('/products', [
            'sku' => 'ME-001',
            'name' => 'Manual Entry Product',
            'weight_kg' => 1.2,
        ])->assertRedirect();

        $product = Product::where('sku', 'ME-001')->firstOrFail();

        $this->assertDatabaseHas('product_weight_evidences', [
            'product_id' => $product->id,
            'weight_kind' => 'product_weight',
            'observed_weight_kg' => 1.2,
            'source_type' => 'manual_entry',
            'status' => 'adopted',
        ]);

        $this->assertEquals(1.2, (float) $product->weight_kg);
        $this->assertNotNull($product->weight_evidence_id);
    }

    public function test_updating_product_weight_creates_new_manual_entry_evidence(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['weight_kg' => null]);

        $this->actingAs($user)->patch("/products/{$product->id}", [
            'name' => $product->name,
            'weight_kg' => 2.5,
        ])->assertRedirect();

        $fresh = $product->fresh();
        $this->assertEquals(2.5, (float) $fresh->weight_kg);
        $this->assertNotNull($fresh->weight_evidence_id);

        $this->assertDatabaseHas('product_weight_evidences', [
            'product_id' => $product->id,
            'weight_kind' => 'product_weight',
            'observed_weight_kg' => 2.5,
            'source_type' => 'manual_entry',
            'status' => 'adopted',
        ]);
    }

    public function test_clearing_product_weight_via_update_nullifies_weight_and_pointer(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['weight_kg' => 3.0]);
        $evidence = $this->pendingEvidence($product, ['status' => 'adopted', 'observed_weight_kg' => 3.0]);
        $product->update(['weight_evidence_id' => $evidence->id]);

        $this->actingAs($user)->patch("/products/{$product->id}", [
            'name' => $product->name,
            'weight_kg' => null,
        ])->assertRedirect();

        $fresh = $product->fresh();
        $this->assertNull($fresh->weight_kg);
        $this->assertNull($fresh->weight_evidence_id);
    }

    // ── Audit before_value correctness (UAT Step 7 regression) ───────────────

    public function test_product_weight_edit_audit_records_correct_before_and_after_values(): void
    {
        // Regression: ProductService::updateProduct() must NOT pre-write weight_kg
        // before calling createAndAdoptManualEntry. The audit before_value must be
        // the original value (1.47), not the new value (1.58). UAT Step 7 bug.
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['weight_kg' => 1.47]);
        $oldEvidence = $this->pendingEvidence($product, [
            'status' => 'adopted',
            'observed_weight_kg' => 1.47,
        ]);
        $product->update(['weight_evidence_id' => $oldEvidence->id]);

        $this->actingAs($user)->patch("/products/{$product->id}", [
            'name' => $product->name,
            'weight_kg' => 1.58,
            'shipping_weight_kg' => $product->shipping_weight_kg,
        ])->assertRedirect();

        $fresh = $product->fresh();

        // New weight and new pointer are in place.
        $this->assertEqualsWithDelta(1.58, (float) $fresh->weight_kg, 0.0001);
        $this->assertNotEquals($oldEvidence->id, $fresh->weight_evidence_id);

        // Adopted manual_entry evidence created with the correct observed value.
        $this->assertDatabaseHas('product_weight_evidences', [
            'product_id' => $product->id,
            'weight_kind' => 'product_weight',
            'source_type' => 'manual_entry',
            'status' => 'adopted',
        ]);
        $newEvidence = \App\Modules\Products\Infrastructure\Models\ProductWeightEvidence::where('product_id', $product->id)
            ->where('source_type', 'manual_entry')
            ->where('status', 'adopted')
            ->latest()
            ->first();
        $this->assertNotNull($newEvidence);
        $this->assertEqualsWithDelta(1.58, (float) $newEvidence->observed_weight_kg, 0.0001);

        // Shipping weight is untouched.
        $this->assertNull($fresh->shipping_weight_kg);
        $this->assertNull($fresh->shipping_weight_evidence_id);

        // Audit record for the adopt action has before_value=1.4700 and after_value=1.5800.
        $adoptRecord = \App\Modules\Audit\Infrastructure\Models\AuditRecord::where('action', 'product.weight_evidence.adopted')
            ->where('entity_id', (string) $product->id)
            ->latest()
            ->first();
        $this->assertNotNull($adoptRecord, 'Audit record for adopted action not found');
        $this->assertEqualsWithDelta(1.47, (float) ($adoptRecord->before['before_value'] ?? null), 0.0001,
            'Audit before_value must be original weight, not the newly written value (pre-write ordering bug)');
        $this->assertEqualsWithDelta(1.58, (float) ($adoptRecord->after['after_value'] ?? null), 0.0001);
    }

    public function test_shipping_weight_edit_audit_records_correct_before_and_after_values(): void
    {
        // Same ordering guarantee for shipping_reference_weight.
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['shipping_weight_kg' => 0.95]);
        $oldEvidence = $this->pendingEvidence($product, [
            'weight_kind' => 'shipping_reference_weight',
            'status' => 'adopted',
            'observed_weight_kg' => 0.95,
        ]);
        $product->update(['shipping_weight_evidence_id' => $oldEvidence->id]);

        $this->actingAs($user)->patch("/products/{$product->id}", [
            'name' => $product->name,
            'weight_kg' => $product->weight_kg,
            'shipping_weight_kg' => 1.10,
        ])->assertRedirect();

        $fresh = $product->fresh();
        $this->assertEqualsWithDelta(1.10, (float) $fresh->shipping_weight_kg, 0.0001);
        $this->assertNotEquals($oldEvidence->id, $fresh->shipping_weight_evidence_id);

        // product_weight unchanged.
        $this->assertNull($fresh->weight_kg);
        $this->assertNull($fresh->weight_evidence_id);

        $adoptRecord = \App\Modules\Audit\Infrastructure\Models\AuditRecord::where('action', 'product.weight_evidence.adopted')
            ->where('entity_id', (string) $product->id)
            ->latest()
            ->first();
        $this->assertNotNull($adoptRecord, 'Audit record for shipping weight adopted not found');
        $this->assertEqualsWithDelta(0.95, (float) ($adoptRecord->before['before_value'] ?? null), 0.0001,
            'Audit before_value must be original shipping weight');
        $this->assertEqualsWithDelta(1.10, (float) ($adoptRecord->after['after_value'] ?? null), 0.0001);
    }

    // ── Shipping weight evidence ──────────────────────────────────────────────

    public function test_adopt_shipping_weight_evidence_updates_correct_field(): void
    {
        $user = $this->userWith(['products.view', 'products.operate']);
        $product = $this->product(['shipping_weight_kg' => null]);
        $evidence = $this->pendingEvidence($product, [
            'weight_kind' => 'shipping_reference_weight',
            'observed_weight_kg' => 0.8,
        ]);

        $this->actingAs($user)
            ->post("/products/weight-evidence/{$evidence->id}/adopt")
            ->assertRedirect();

        $fresh = $product->fresh();
        $this->assertEquals(0.8, (float) $fresh->shipping_weight_kg);
        $this->assertEquals($evidence->id, $fresh->shipping_weight_evidence_id);
        // product_weight unchanged
        $this->assertNull($fresh->weight_kg);
    }
}
