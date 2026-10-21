<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\PaymentFeeLegacyFxRepairService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PaymentFeeLegacyFxRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_finds_only_owner_rule_legacy_fx_mismatches(): void
    {
        $legacy = $this->legacyOrder();
        $manual = $this->legacyOrder('owner:manual');
        $correct = $this->legacyOrder(exchangeRate: '1.10000000', exchangeDate: '2026-09-07', baseAmount: '3.85');

        $preview = $this->service()->preview();

        self::assertSame(2, $preview['seen']);
        self::assertSame(1, $preview['candidates']);
        self::assertSame(1, $preview['base_changes']);
        self::assertSame(['EUR' => 1], $preview['currencies']);
        self::assertSame($legacy->id, $preview['rows'][0]['order_id']);
        self::assertNotSame($manual->id, $preview['rows'][0]['order_id']);
        self::assertNotSame($correct->id, $preview['rows'][0]['order_id']);
    }

    public function test_apply_repairs_only_fx_metadata_and_records_audit(): void
    {
        $order = $this->legacyOrder();
        $entry = $order->costEntries()->where('cost_type', 'payment_fee')->firstOrFail();
        $originalAmount = $entry->amount;
        $originalSource = $entry->source_reference;

        $result = $this->service()->apply();
        $entry->refresh();

        self::assertSame(1, $result['applied']);
        self::assertSame(0, $result['noop']);
        self::assertSame($originalAmount, $entry->amount);
        self::assertSame($originalSource, $entry->source_reference);
        self::assertSame('1.10000000', $entry->exchange_rate);
        self::assertSame('2026-09-07', $entry->exchange_rate_date->toDateString());
        self::assertSame('settings_fx_posting:EUR(ecb)->USD(ecb)', $entry->exchange_rate_source);
        self::assertSame('3.85', $entry->base_amount);
        self::assertSame('USD', $entry->base_currency);
        self::assertSame(1, DB::table('audit_records')->where('action', 'finance.payment_fee_fx_metadata.repaired')->count());
        self::assertSame(1, DB::table('audit_records')->where('reason', 'payment_fee_fx_legacy_repair')->count());

        $second = $this->service()->preview();
        self::assertSame(0, $second['candidates']);
    }

    public function test_production_apply_requires_dedicated_env_and_exact_counts(): void
    {
        $order = $this->legacyOrder();
        $previousEnv = $this->app['env'];
        $this->app['env'] = 'production';
        putenv('ERP_ACCOUNTING_PAYMENT_FEE_LEGACY_FX_REPAIR_ENABLED');

        try {
            $base = [
                '--apply' => true,
                '--expect-seen' => '1',
                '--expect-candidates' => '1',
                '--expect-base-changes' => '1',
            ];
            $this->artisan('finance:repair-payment-fee-legacy-fx', $base)->assertFailed();
            self::assertSame('1.20000000', $order->costEntries()->firstOrFail()->exchange_rate);

            putenv('ERP_ACCOUNTING_PAYMENT_FEE_LEGACY_FX_REPAIR_ENABLED=true');
            $wrong = $base;
            $wrong['--expect-candidates'] = '2';
            $this->artisan('finance:repair-payment-fee-legacy-fx', $wrong)->assertFailed();
            self::assertSame('1.20000000', $order->costEntries()->firstOrFail()->exchange_rate);

            $this->artisan('finance:repair-payment-fee-legacy-fx', $base)->assertSuccessful();
            self::assertSame('1.10000000', $order->costEntries()->firstOrFail()->fresh()->exchange_rate);
        } finally {
            putenv('ERP_ACCOUNTING_PAYMENT_FEE_LEGACY_FX_REPAIR_ENABLED');
            $this->app['env'] = $previousEnv;
        }
    }

    private function legacyOrder(
        string $sourceReference = 'owner-rule:payment-fee:v1:provider-order-total:3.5pct',
        string $exchangeRate = '1.20000000',
        string $exchangeDate = '2026-09-08',
        string $baseAmount = '4.20',
    ): Order {
        $order = Order::query()->create([
            'internal_order_number' => 'P5-FX-'.strtoupper(uniqid()),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'order_date' => '2026-09-08',
            'order_status' => 'pending_shipment',
            'currency' => 'EUR',
        ]);
        $order->financials()->create([
            'currency' => 'EUR',
            'provider_order_total' => '100.00',
            'fx_rate' => '1.10000000',
            'fx_rate_date' => '2026-09-07',
            'fx_rate_source' => 'settings_fx_posting:EUR(ecb)->USD(ecb)',
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);
        $order->costEntries()->create([
            'cost_type' => 'payment_fee',
            'amount' => '3.50',
            'currency' => 'EUR',
            'provenance' => 'confirmed_manual',
            'status' => 'confirmed',
            'source_reference' => $sourceReference,
            'exchange_rate' => $exchangeRate,
            'exchange_rate_date' => $exchangeDate,
            'exchange_rate_source' => 'settings_fx_posting:EUR(ecb)->USD(ecb)',
            'base_amount' => $baseAmount,
            'base_currency' => 'USD',
        ]);
        return $order;
    }

    private function service(): PaymentFeeLegacyFxRepairService
    {
        return $this->app->make(PaymentFeeLegacyFxRepairService::class);
    }
}
