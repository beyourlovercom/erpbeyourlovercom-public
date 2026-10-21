<?php

namespace Tests\Feature\ProductionStability;

use App\Modules\Finance\Application\ConfirmedLossProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MySql8ProductionCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('BYL_MYSQL_STABILITY_ENABLED') !== '1') {
            $this->markTestSkipped('MySQL 8 Production Stability gate is disabled.');
        }

        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $expectedDatabase = (string) getenv('BYL_MYSQL_STABILITY_DATABASE');

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertNotSame('', $expectedDatabase);
        $this->assertSame($expectedDatabase, $database);
        $this->assertMatchesRegularExpression('/^(?:byl_p4_|erp_stability_)[a-z0-9_]+$/', $database);

        $version = (string) $connection->scalar('select version()');
        $this->assertStringStartsWith('8.', $version, 'Production compatibility gate requires MySQL 8.x.');

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_all_migrations_and_confirmed_loss_scope_execute_on_mysql_8(): void
    {
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('order_financials'));
        $this->assertTrue(Schema::hasTable('order_cost_entries'));
        $this->assertTrue(Schema::hasTable('product_shipping_weight_standard_candidates'));

        $count = app(ConfirmedLossProjection::class)
            ->applyConfirmedLossScope(Order::query())
            ->count();

        $this->assertSame(0, $count);
    }
}
