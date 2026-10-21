<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderIdentitySearchContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_numeric_search_does_not_substring_match_unrelated_erp_order_number(): void
    {
        $wrong = $this->order('CUS-WRONG', '007-0523-c37008', '37008-OLD');
        $wrong->externalReferences()->create([
            'provider' => 'woocommerce',
            'reference' => '37008',
        ]);

        $result = app(OrderService::class)->list(['search' => '7008']);

        self::assertSame(0, $result->total());
    }

    public function test_plain_numeric_search_matches_exact_source_reference(): void
    {
        $target = $this->order('CUS-TARGET', '007-0909-c40405', '40405-VA617A');
        $target->externalReferences()->create([
            'provider' => 'woocommerce',
            'reference' => '40405',
        ]);
        $other = $this->order('CUS-OTHER', '007-0523-c140405', '140405-OTHER');
        $other->externalReferences()->create([
            'provider' => 'woocommerce',
            'reference' => '140405',
        ]);

        $result = app(OrderService::class)->list(['search' => '40405']);

        self::assertSame(1, $result->total());
        self::assertSame($target->id, $result->items()[0]->id);
    }

    public function test_dbid_prefix_is_required_for_internal_primary_key_lookup(): void
    {
        $target = $this->order('CUS-DBID', '007-0909-c40405', '40405-VA617A');

        $result = app(OrderService::class)->list(['search' => 'dbid:'.$target->id]);

        self::assertSame(1, $result->total());
        self::assertSame($target->id, $result->items()[0]->id);
    }

    public function test_full_erp_order_identity_remains_searchable(): void
    {
        $target = $this->order('CUS-ERP', '007-0909-c40405', '40405-VA617A');

        $result = app(OrderService::class)->list(['search' => '007-0909-c40405']);

        self::assertSame(1, $result->total());
        self::assertSame($target->id, $result->items()[0]->id);
    }

    private function order(string $internal, string $erp, string $external): Order
    {
        return Order::query()->create([
            'internal_order_number' => $internal,
            'erp_order_id' => $erp,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => $external,
            'order_date' => '2026-09-09',
            'order_status' => 'pending_shipment',
            'currency' => 'EUR',
        ]);
    }
}
