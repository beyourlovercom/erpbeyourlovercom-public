<?php

namespace Tests\Feature\Products;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Application\SkuAliasService;
use App\Modules\Products\Application\SkuIdentityResolver;
use App\Modules\Products\Application\SkuResolveResult;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use App\Modules\Products\Infrastructure\Models\ProductSkuReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SkuAliasTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeProduct(string $sku): Product
    {
        return Product::create([
            'source_type' => 'manual',
            'woocommerce_product_id' => null,
            'sku' => $sku,
            'name' => "Product {$sku}",
            'product_type' => 'simple',
        ]);
    }

    // ── Gate 3: duplicate alias match keys rejected ──────────────────────────

    public function test_duplicate_alias_match_key_rejected(): void
    {
        $product = $this->makeProduct('VM467A');
        $service = app(SkuAliasService::class);

        $service->createAlias($product->id, 'VM467A DARK GREEN');

        $this->expectException(\RuntimeException::class);
        $service->createAlias($product->id, 'vm467a dark green'); // same match key
    }

    // ── Gate 4: alias cannot collide with canonical Product SKU ─────────────

    public function test_alias_cannot_collide_with_canonical_sku(): void
    {
        $p1 = $this->makeProduct('VM467A');
        $p2 = $this->makeProduct('DARK-SKU');
        $service = app(SkuAliasService::class);

        $this->expectException(\RuntimeException::class);
        $service->createAlias($p1->id, 'dark-sku'); // matches p2 canonical
    }

    // ── Gate 5: canonical SKU same match key as alias → redundant, rejected ─

    public function test_alias_equal_to_own_canonical_sku_rejected(): void
    {
        $product = $this->makeProduct('VM467A');
        $service = app(SkuAliasService::class);

        $this->expectException(\RuntimeException::class);
        $service->createAlias($product->id, 'vm467a'); // matches canonical SKU
    }

    // ── Gate 6: resolver precedence: canonical → alias → unmatched ──────────

    public function test_resolver_returns_canonical_for_exact_sku(): void
    {
        $this->makeProduct('VM467A');
        $resolver = app(SkuIdentityResolver::class);

        $result = $resolver->resolve('VM467A');
        $this->assertSame(SkuResolveResult::CANONICAL, $result->type);
        $this->assertSame('VM467A', $result->canonicalSku);
    }

    public function test_resolver_returns_alias_for_alias_sku(): void
    {
        $product = $this->makeProduct('VM467A');
        $service = app(SkuAliasService::class);
        $service->createAlias($product->id, 'VM467A DARK GREEN');

        $resolver = app(SkuIdentityResolver::class);
        $result = $resolver->resolve('VM467A DARK GREEN');
        $this->assertSame(SkuResolveResult::ALIAS, $result->type);
        $this->assertSame('VM467A', $result->canonicalSku);
    }

    public function test_resolver_returns_alias_case_insensitive(): void
    {
        $product = $this->makeProduct('VM467A');
        $service = app(SkuAliasService::class);
        $service->createAlias($product->id, 'VM467A DARK GREEN');

        $resolver = app(SkuIdentityResolver::class);
        $result = $resolver->resolve('vm467a dark green');
        $this->assertSame(SkuResolveResult::ALIAS, $result->type);
    }

    public function test_resolver_returns_unmatched_for_unknown_sku(): void
    {
        $resolver = app(SkuIdentityResolver::class);
        $result = $resolver->resolve('TOTALLY-UNKNOWN-SKU');
        $this->assertSame(SkuResolveResult::UNMATCHED, $result->type);
        $this->assertFalse($result->isResolved());
    }

    // ── Gate 7: resolver never fuzzy-matches ────────────────────────────────

    public function test_resolver_does_not_fuzzy_match(): void
    {
        $this->makeProduct('VM467A');
        $resolver = app(SkuIdentityResolver::class);

        // Partial match must NOT resolve
        $result = $resolver->resolve('VM467');
        $this->assertSame(SkuResolveResult::UNMATCHED, $result->type);
    }

    // ── Gate 10: unmatched Inventory SKU appears in reconciliation queue ─────

    public function test_observe_unmatched_creates_reconciliation_entry(): void
    {
        $resolver = app(SkuIdentityResolver::class);
        $resolver->observeUnmatched('VM467A DARK GREEN', 'inventory_batch', 'batch:1');

        $this->assertDatabaseHas('product_sku_reconciliations', [
            'raw_sku' => 'VM467A DARK GREEN',
            'status' => 'open',
        ]);
    }

    public function test_observe_unmatched_increments_count(): void
    {
        $resolver = app(SkuIdentityResolver::class);
        $resolver->observeUnmatched('VM467A DARK GREEN', 'inventory_batch', 'batch:1');
        $resolver->observeUnmatched('VM467A DARK GREEN', 'inventory_batch', 'batch:2');

        $item = ProductSkuReconciliation::where('raw_sku', 'VM467A DARK GREEN')->first();
        $this->assertSame(2, $item->occurrence_count);
    }

    // ── Gate 18: alias mutations write audit evidence ─────────────────────────

    public function test_alias_create_writes_audit(): void
    {
        $product = $this->makeProduct('VM467A');
        $service = app(SkuAliasService::class);
        $service->createAlias($product->id, 'VM467A DARK GREEN');

        $this->assertDatabaseHas('audit_records', [
            'action' => 'product.sku_alias.created',
        ]);
    }

    public function test_alias_delete_writes_audit(): void
    {
        $product = $this->makeProduct('VM467A');
        $service = app(SkuAliasService::class);
        $alias = $service->createAlias($product->id, 'VM467A DARK GREEN');
        $service->deleteAlias($alias);

        $this->assertDatabaseHas('audit_records', [
            'action' => 'product.sku_alias.deleted',
        ]);
    }

    // ── Gate 16: authorization checks ────────────────────────────────────────

    public function test_view_only_cannot_create_alias(): void
    {
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)
            ->post('/products/sku-aliases/aliases', ['product_id' => 1, 'alias_sku' => 'DENY'])
            ->assertForbidden();
    }

    public function test_view_only_cannot_resolve_reconciliation(): void
    {
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)
            ->post('/products/sku-aliases/reconciliations/1/resolve', [])
            ->assertForbidden();
    }

    // ── Gate 17: real pages, not shell placeholders ───────────────────────────

    public function test_skus_page_is_accessible_with_products_view(): void
    {
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->get('/products/skus')->assertOk();
    }

    public function test_sku_aliases_page_is_accessible_with_products_view(): void
    {
        $user = $this->userWith(['products.view']);
        $this->actingAs($user)->get('/products/sku-aliases')->assertOk();
    }

    public function test_skus_page_forbidden_without_products_view(): void
    {
        $user = $this->userWith(['prototype.view']);
        $this->actingAs($user)->get('/products/skus')->assertForbidden();
    }

    // ── Finding 1: case-insensitive canonical resolution via match key ────────

    public function test_resolver_resolves_canonical_sku_case_insensitively(): void
    {
        $this->makeProduct('VM467A');
        $resolver = app(SkuIdentityResolver::class);

        $result = $resolver->resolve('vm467a'); // lower-case of canonical
        $this->assertSame(SkuResolveResult::CANONICAL, $result->type);
        $this->assertSame('VM467A', $result->canonicalSku);
    }

    public function test_resolver_resolves_canonical_sku_with_leading_trailing_whitespace(): void
    {
        $this->makeProduct('VM467A');
        $resolver = app(SkuIdentityResolver::class);

        $result = $resolver->resolve(' VM467A '); // whitespace around canonical
        $this->assertSame(SkuResolveResult::CANONICAL, $result->type);
    }

    // ── Finding 1: alias vs canonical collision uses match key ────────────────

    public function test_alias_vs_canonical_collision_is_case_insensitive(): void
    {
        $p1 = $this->makeProduct('VM467A');
        $p2 = $this->makeProduct('DARK-SKU');
        $service = app(SkuAliasService::class);

        // Trying to create alias with match key that matches p2's canonical SKU (DARK-SKU → dark-sku)
        $this->expectException(\RuntimeException::class);
        $service->createAlias($p1->id, 'dark-Sku'); // mixed case, same match key as DARK-SKU
    }

    // ── Finding 2: Gate 5 — ProductService createManual rejects alias-collision ──

    public function test_create_manual_product_fails_if_sku_collides_with_alias(): void
    {
        $product = $this->makeProduct('VM467A');
        $aliasService = app(SkuAliasService::class);
        $aliasService->createAlias($product->id, 'VM467A DARK GREEN');

        $productService = app(\App\Modules\Products\Application\ProductService::class);

        $this->expectException(\RuntimeException::class);
        $productService->createManual([
            'sku' => 'VM467A DARK GREEN', // already an alias match key
            'name' => 'Conflict Product',
        ]);
    }

    public function test_update_product_fails_if_new_sku_collides_with_alias(): void
    {
        $product1 = $this->makeProduct('VM467A');
        $product2 = $this->makeProduct('OTHER-SKU');
        $aliasService = app(SkuAliasService::class);
        $aliasService->createAlias($product1->id, 'VM467A DARK GREEN');

        $productService = app(\App\Modules\Products\Application\ProductService::class);

        $this->expectException(\RuntimeException::class);
        $productService->updateProduct($product2, ['sku' => 'VM467A DARK GREEN', 'name' => 'Other Product']);
    }

    public function test_store_product_via_controller_returns_sku_error_on_alias_collision(): void
    {
        $product = $this->makeProduct('VM467A');
        $aliasService = app(SkuAliasService::class);
        $aliasService->createAlias($product->id, 'VM467A DARK GREEN');

        $user = $this->userWith(['products.view', 'products.operate']);
        $response = $this->actingAs($user)->post('/products', [
            'sku' => 'VM467A DARK GREEN',
            'name' => 'Conflict Product',
        ]);

        $response->assertSessionHasErrors(['sku']);
        $this->assertDatabaseMissing('products', ['name' => 'Conflict Product']);
    }

    // ── Finding 3: /products/skus search finds product by alias ──────────────

    public function test_skus_page_search_returns_product_matching_by_alias(): void
    {
        $product = $this->makeProduct('VM467A');
        $aliasService = app(SkuAliasService::class);
        $aliasService->createAlias($product->id, 'VM467A DARK GREEN');

        $user = $this->userWith(['products.view']);
        $response = $this->actingAs($user)
            ->get('/products/skus?search=DARK+GREEN');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Products/Skus')
            ->where('products.data.0.sku', 'VM467A')
        );
    }
}
