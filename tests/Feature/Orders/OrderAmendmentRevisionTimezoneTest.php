<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Application\OrderAmendmentRevision;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrderAmendmentRevisionTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_revision_is_stable_across_mysql_connection_timezones_and_changes_on_item_edit(): void
    {
        $product = Product::query()->create([
            'sku' => 'REV-TZ-'.uniqid(),
            'name' => 'Revision test item',
            'source_type' => 'manual',
            'product_type' => 'simple',
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'internal_order_number' => 'REV-TZ-'.uniqid(),
            'erp_order_id' => '007-1014-'.random_int(10000, 99999),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'order_date' => now()->toDateString(),
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_summary' => $product->name,
            'quantity' => 1,
            'unit_price' => '19.99',
            'line_revenue' => '19.99',
        ]);
        $revisions = app(OrderAmendmentRevision::class);

        // On SQLite CI the same assertion verifies deterministic rereads.
        if (DB::connection()->getDriverName() !== 'mysql') {
            $revision = $revisions->forOrder($order);
            $this->assertSame($revision, $revisions->forOrder($order->fresh()));
            $item->forceFill(['quantity' => 2])->save();
            $this->assertNotSame($revision, $revisions->forOrder($order));

            return;
        }

        $original = (string) DB::selectOne('SELECT @@session.time_zone AS tz')->tz;
        try {
            DB::statement("SET time_zone = '+00:00'");
            $revisionUtc = $revisions->forOrder($order);
            DB::statement("SET time_zone = '+07:00'");
            $revisionPlusSeven = $revisions->forOrder($order->fresh());
            $this->assertSame($revisionUtc, $revisionPlusSeven,
                'A TIMESTAMP cast in a second PHP/MySQL connection must not invalidate an unchanged order');

            $item->forceFill(['quantity' => 2])->save();
            $this->assertNotSame($revisionUtc, $revisions->forOrder($order));
        } finally {
            DB::statement("SET time_zone = '".str_replace("'", "''", $original)."'");
        }
    }
}
