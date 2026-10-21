<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\WooFinancialEvidenceBootstrapService;
use App\Modules\Orders\Application\Contracts\WooCommerceFinancialEvidenceReader;
use App\Modules\Orders\Application\Contracts\WooCommerceOrderGateway;
use App\Modules\Orders\Application\Inbound\WooCommerceOrderNormalizer;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\WooCommerce\EloquentWooCommerceFinancialEvidenceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WooFinancialEvidenceBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_owned_reader_reuses_woo_normalizer_and_resolves_exact_canonical_reference(): void
    {
        $order = $this->order();
        $this->wooReference($order, '100', 'primary');
        $gateway = new FakeWooFinancialGateway('primary', [$this->wooPayload(100, refund: '-5.00')]);
        $reader = new EloquentWooCommerceFinancialEvidenceReader($gateway, new WooCommerceOrderNormalizer());

        $source = $reader->fetchOne(100);
        self::assertSame('resolved', $source['source_status']);
        self::assertSame($order->id, $source['order_id']);
        self::assertSame('100', $source['provider_reference']);
        self::assertSame('100.00', $source['settlement_evidence']['provider_order_total']);
        self::assertSame('5.00', $source['settlement_evidence']['refund_amount']);
        self::assertSame('matched', $source['settlement_evidence']['reconciliation_status']);

        $page = $reader->fetchPage(['page' => 1]);
        self::assertSame(1, $page['total_pages']);
        self::assertSame('resolved', $page['items'][0]['source_status']);
    }

    public function test_reader_fails_closed_for_connection_mismatch_noncustomer_historical_and_unresolved_reference(): void
    {
        $connectionMismatch = $this->order();
        $this->wooReference($connectionMismatch, '101', 'other');
        $reader = new EloquentWooCommerceFinancialEvidenceReader(
            new FakeWooFinancialGateway('primary', [$this->wooPayload(101)]),
            new WooCommerceOrderNormalizer(),
        );
        self::assertSame('unresolved', $reader->fetchOne(101)['source_status']);

        $nonCustomer = $this->order('kol_fulfillment');
        $this->wooReference($nonCustomer, '102', 'primary');
        $reader = new EloquentWooCommerceFinancialEvidenceReader(
            new FakeWooFinancialGateway('primary', [$this->wooPayload(102)]),
            new WooCommerceOrderNormalizer(),
        );
        self::assertSame('order_kind_not_customer', $reader->fetchOne(102)['blocker']);

        $historical = $this->order();
        $this->wooReference($historical, '103', 'primary');
        $historical->externalReferences()->create([
            'provider' => 'historical_logistics', 'connection_key' => 'legacy', 'reference' => 'H-103',
        ]);
        $reader = new EloquentWooCommerceFinancialEvidenceReader(
            new FakeWooFinancialGateway('primary', [$this->wooPayload(103)]),
            new WooCommerceOrderNormalizer(),
        );
        self::assertSame('historical_logistics_excluded', $reader->fetchOne(103)['blocker']);
    }

    public function test_woo_only_order_without_canonical_reference_is_unresolved_before_settlement_normalization(): void
    {
        $payload = $this->wooPayload(104);
        $payload['line_items'] = [];
        $reader = new EloquentWooCommerceFinancialEvidenceReader(
            new FakeWooFinancialGateway('primary', [$payload]),
            new WooCommerceOrderNormalizer(),
        );

        $source = $reader->fetchOne(104);
        self::assertSame('unresolved', $source['source_status']);
        self::assertSame('canonical_order_not_found', $source['blocker']);
        self::assertNull($source['settlement_evidence']);
    }

    public function test_apply_creates_provider_financials_then_becomes_noop_without_duplicate_or_order_core_mutation(): void
    {
        $order = $this->order();
        $this->wooReference($order, '200', 'primary');
        $source = $this->source($order, '200');
        $before = $order->only(['source_type', 'order_kind', 'order_status', 'currency', 'external_order_number']);

        $first = $this->service()->apply($source);
        self::assertSame('created', $first['disposition']);
        $financials = $order->financials()->firstOrFail();
        self::assertSame('provider_import', $financials->revenue_provenance);
        self::assertSame('100.00', $financials->provider_order_total);
        self::assertSame('10.00', $financials->discount_amount);
        self::assertSame('5.00', $financials->refund_amount);
        self::assertSame('matched', $financials->reconciliation_status);
        self::assertSame('INCOMPLETE', $financials->completeness_status);

        $second = $this->service()->apply($source);
        self::assertSame('noop', $second['disposition']);
        self::assertSame(1, $order->financials()->count());
        self::assertSame($before, $order->fresh()->only(array_keys($before)));
    }

    public function test_reconciliation_exception_key_order_is_semantically_idempotent(): void
    {
        $order = $this->order();
        $this->wooReference($order, '202', 'primary');
        $source = $this->source($order, '202', [
            'reconciliation_status' => 'exception',
            'reconciliation_exception' => [
                'expected_provider_total' => '100.00',
                'provider_total' => '90.00',
                'difference' => '10.00',
            ],
        ]);
        $this->service()->apply($source);

        $financials = $order->financials()->firstOrFail();
        $financials->reconciliation_exception = [
            'difference' => '10.00',
            'provider_total' => '90.00',
            'expected_provider_total' => '100.00',
        ];
        $financials->save();

        self::assertSame('noop', $this->service()->preview($source)['disposition']);
    }

    public function test_provider_update_is_allowed_and_currency_change_clears_frozen_fx(): void
    {
        $order = $this->order();
        $this->wooReference($order, '201', 'primary');
        $this->service()->apply($this->source($order, '201'));
        $order->financials()->update([
            'fx_rate' => '1.25000000', 'fx_rate_date' => '2026-09-07', 'fx_rate_source' => 'fixture',
        ]);

        $source = $this->source($order, '201', ['currency' => 'EUR', 'provider_order_total' => '90.00']);
        $preview = $this->service()->preview($source);
        self::assertSame('update', $preview['disposition']);
        self::assertTrue($preview['clear_fx_snapshot']);
        $result = $this->service()->apply($source);
        self::assertSame('updated', $result['disposition']);

        $financials = $order->financials()->firstOrFail()->fresh();
        self::assertSame('EUR', $financials->currency);
        self::assertSame('90.00', $financials->provider_order_total);
        self::assertNull($financials->fx_rate);
        self::assertNull($financials->fx_rate_date);
        self::assertNull($financials->fx_rate_source);
    }

    public function test_manual_and_legacy_unclassified_revenue_are_never_overwritten(): void
    {
        $manual = $this->order();
        $this->wooReference($manual, '300', 'primary');
        $manual->financials()->create([
            'amount_paid' => '77.00', 'refund_amount' => '0.00', 'currency' => 'USD',
            'revenue_provenance' => 'confirmed_manual', 'completeness_status' => 'INCOMPLETE',
        ]);
        $preview = $this->service()->preview($this->source($manual, '300'));
        self::assertSame('blocked', $preview['disposition']);
        self::assertSame('confirmed_manual_revenue_protected', $preview['blocker']);
        $this->service()->apply($this->source($manual, '300'));
        self::assertSame('77.00', $manual->financials()->firstOrFail()->amount_paid);

        $legacy = $this->order();
        $this->wooReference($legacy, '301', 'primary');
        $legacy->financials()->create([
            'amount_paid' => '66.00', 'refund_amount' => '0.00', 'currency' => 'USD',
            'revenue_provenance' => null, 'completeness_status' => 'INCOMPLETE',
        ]);
        $legacyPreview = $this->service()->preview($this->source($legacy, '301'));
        self::assertSame('blocked', $legacyPreview['disposition']);
        self::assertSame('legacy_unclassified_revenue_protected', $legacyPreview['blocker']);
    }

    public function test_missing_source_fields_stay_null_and_refunds_remain_separate(): void
    {
        $order = $this->order();
        $this->wooReference($order, '400', 'primary');
        $gateway = new FakeWooFinancialGateway('primary', [$this->wooPayload(400, missingFinancials: true)]);
        $reader = new EloquentWooCommerceFinancialEvidenceReader($gateway, new WooCommerceOrderNormalizer());
        $source = $reader->fetchOne(400);

        self::assertNull($source['settlement_evidence']['discount_amount']);
        self::assertNull($source['settlement_evidence']['shipping_revenue']);
        self::assertNull($source['settlement_evidence']['provider_order_total']);
        self::assertNull($source['settlement_evidence']['refund_amount']);
        self::assertSame('incomplete', $source['settlement_evidence']['reconciliation_status']);

        $this->service()->apply($source);
        $financials = $order->financials()->firstOrFail();
        self::assertNull($financials->discount_amount);
        self::assertNull($financials->provider_order_total);
        self::assertSame('INCOMPLETE', $financials->completeness_status);
    }

    public function test_command_is_dry_run_by_default_and_production_apply_is_fail_closed(): void
    {
        $order = $this->order();
        $this->wooReference($order, '500', 'primary');
        $reader = new FakeFinancialEvidenceReader($this->source($order, '500'));
        $this->app->instance(WooCommerceFinancialEvidenceReader::class, $reader);

        $this->artisan('finance:bootstrap-woo-financials', ['--order' => '500', '--json' => true])
            ->assertSuccessful();
        self::assertSame(0, $order->financials()->count());

        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';
        config()->set('erp_accounting.financial_bootstrap.production_apply_enabled', false);
        try {
            $this->artisan('finance:bootstrap-woo-financials', ['--order' => '500', '--apply' => true])
                ->assertFailed();
        } finally {
            $this->app['env'] = $originalEnv;
        }
        self::assertSame(0, $order->financials()->count());
    }

    private function service(): WooFinancialEvidenceBootstrapService
    {
        return $this->app->make(WooFinancialEvidenceBootstrapService::class);
    }

    private function order(string $kind = 'customer'): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'FB-'.strtoupper(uniqid()),
            'source_type' => 'woocommerce',
            'order_kind' => $kind,
            'external_order_number' => 'EXT-'.strtoupper(uniqid()),
            'order_date' => '2026-09-07',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
    }

    private function wooReference(Order $order, string $reference, string $connectionKey): void
    {
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => $connectionKey,
            'reference' => $reference,
            'provider_modified_at' => '2026-09-07 00:00:00',
        ]);
    }

    private function source(Order $order, string $reference, array $overrides = []): array
    {
        $evidence = [
            'provider' => 'woocommerce',
            'provider_reference' => $reference,
            'provider_modified_at' => '2026-09-07T00:00:00',
            'provider_order_status' => 'processing',
            'currency' => 'USD',
            'merchandise_list_amount' => '110.00',
            'discount_amount' => '10.00',
            'shipping_revenue' => '0.00',
            'tax_collected' => '0.00',
            'provider_order_total' => '100.00',
            'refund_amount' => '5.00',
            'refund_tax' => '0.00',
            'payment_method' => 'woocommerce_payments',
            'paid_at' => '2026-09-07T00:00:00',
            'reconciliation_status' => 'matched',
            'reconciliation_exception' => null,
            ...$overrides,
        ];

        return [
            'source_status' => 'resolved',
            'provider' => 'woocommerce',
            'provider_reference' => $reference,
            'connection_key' => 'primary',
            'provider_modified_at' => $evidence['provider_modified_at'],
            'order_id' => $order->id,
            'blocker' => null,
            'settlement_evidence' => $evidence,
        ];
    }

    private function wooPayload(int $id, string $refund = '0.00', bool $missingFinancials = false): array
    {
        $payload = [
            'id' => $id,
            'number' => (string) $id,
            'status' => 'processing',
            'date_created_gmt' => '2026-09-07T00:00:00',
            'date_modified_gmt' => '2026-09-07T00:01:00',
            'date_paid_gmt' => '2026-09-07T00:00:30',
            'currency' => 'USD',
            'payment_method' => 'woocommerce_payments',
            'line_items' => [[
                'subtotal' => '110.00', 'total' => '100.00', 'quantity' => 1, 'name' => 'Item',
            ]],
        ];
        if (! $missingFinancials) {
            $payload += [
                'total' => '100.00', 'discount_total' => '10.00', 'shipping_total' => '0.00', 'total_tax' => '0.00',
                'refunds' => $refund === '0.00' ? [] : [['total' => $refund, 'total_tax' => '0.00']],
            ];
        }

        return $payload;
    }
}

final class FakeWooFinancialGateway implements WooCommerceOrderGateway
{
    /** @param list<array<string,mixed>> $payloads */
    public function __construct(private readonly string $connectionKey, private readonly array $payloads) {}

    public function descriptor(): array
    {
        return ['configured' => true, 'url' => 'https://example.test', 'connection_key' => $this->connectionKey];
    }

    public function probe(): array
    {
        return ['ok' => true, 'status' => 200];
    }

    public function fetchOrder(int $providerOrderId): array
    {
        foreach ($this->payloads as $payload) {
            if ((int) ($payload['id'] ?? 0) === $providerOrderId) {
                return $payload;
            }
        }
        return ['id' => $providerOrderId];
    }

    public function fetchOrders(array $query): array
    {
        return ['items' => $this->payloads, 'total_pages' => 1];
    }
}

final class FakeFinancialEvidenceReader implements WooCommerceFinancialEvidenceReader
{
    public function __construct(private readonly array $source) {}

    public function descriptor(): array
    {
        return ['configured' => true, 'url' => 'https://example.test', 'connection_key' => 'primary'];
    }

    public function fetchOne(int $providerOrderId): array
    {
        return $this->source;
    }

    public function fetchPage(array $query): array
    {
        return ['items' => [$this->source], 'total_pages' => 1];
    }
}
