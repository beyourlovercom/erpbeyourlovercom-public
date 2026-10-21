<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\Contracts\DeliveryEvidenceGateway;
use App\Modules\Logistics\Application\DeliveryEvidenceIngestService;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeliveryEvidenceIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_maps_in_transit_and_is_read_only(): void
    {
        $shipment = $this->scenario();
        $this->fake('InTransit', 'InTransit_007');

        $preview = app(DeliveryEvidenceIngestService::class)->preview($shipment->id);

        $this->assertSame('change', $preview['decision']);
        $this->assertSame('in_transit', $preview['target_delivery_status']);
        $this->assertSame('usps', $preview['remote']['slug']);
        $this->assertDatabaseMissing('shipment_carrier_billing', ['shipment_id' => $shipment->id]);
        $this->assertDatabaseCount('audit_records', 0);
    }

    public function test_apply_delivered_preserves_existing_billing_provenance_and_cost(): void
    {
        $shipment = $this->scenario();
        $billing = $shipment->billingEvidence()->create([
            'actual_cost' => '18.7500',
            'currency' => 'USD',
            'billing_document_number' => 'BILL-1',
            'provider_reference' => 'CARRIER-BILL-REF',
            'data_source' => 'carrier_bill',
            'raw_evidence' => ['billing' => ['source' => 'carrier-file']],
        ]);
        $this->fake('Delivered', 'Delivered_003');
        $service = app(DeliveryEvidenceIngestService::class);
        $preview = $service->preview($shipment->id);

        $result = $service->apply($shipment->id, $preview['fingerprint']);

        $this->assertSame('applied', $result['gate']);
        $billing = $billing->fresh();
        $this->assertSame('delivered', $billing->delivery_status);
        $this->assertSame('18.7500', $billing->actual_cost);
        $this->assertSame('USD', $billing->currency);
        $this->assertSame('BILL-1', $billing->billing_document_number);
        $this->assertSame('CARRIER-BILL-REF', $billing->provider_reference);
        $this->assertSame('carrier_bill', $billing->data_source);
        $this->assertSame('carrier-file', $billing->raw_evidence['billing']['source']);
        $this->assertSame('aftership_tracking_api', $billing->raw_evidence['delivery_evidence']['source']);
        $this->assertSame('Delivered', $billing->raw_evidence['delivery_evidence']['tag']);
        $this->assertSame('delivered', $billing->raw_evidence['delivery_evidence']['normalized_delivery_status']);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'shipment.delivery_evidence_ingested',
            'entity_id' => (string) $billing->id,
            'reason' => 'aftership_tracking_api',
        ]);
    }

    public function test_apply_creates_billing_evidence_without_changing_shipment_status(): void
    {
        $shipment = $this->scenario('exception');
        $this->fake('AttemptFail', 'AttemptFail_001');
        $service = app(DeliveryEvidenceIngestService::class);
        $preview = $service->preview($shipment->id);

        $service->apply($shipment->id, $preview['fingerprint']);

        $this->assertDatabaseHas('shipment_carrier_billing', [
            'shipment_id' => $shipment->id,
            'delivery_status' => 'exception',
            'data_source' => 'aftership_tracking_api',
            'provider_reference' => 'aftership-track-1',
        ]);
        $this->assertSame('exception', $shipment->fresh()->shipment_status);
    }

    public function test_apply_fails_closed_when_delivery_evidence_changes_after_owner_preview(): void
    {
        $shipment = $this->scenario();
        $this->fake('Delivered', 'Delivered_003');
        $service = app(DeliveryEvidenceIngestService::class);
        $preview = $service->preview($shipment->id);

        $shipment->billingEvidence()->create([
            'delivery_status' => 'exception',
            'data_source' => 'carrier_manual_evidence',
            'raw_evidence' => ['delivery_evidence' => ['source' => 'newer_source']],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Delivery evidence fingerprint mismatch.');
        try {
            $service->apply($shipment->id, $preview['fingerprint']);
        } finally {
            $this->assertSame('exception', $shipment->billingEvidence()->firstOrFail()->delivery_status);
        }
    }

    public function test_command_fails_closed_on_fingerprint_mismatch(): void
    {
        $shipment = $this->scenario();
        $this->fake('Delivered', 'Delivered_001');

        $this->artisan('logistics:sync-delivery-evidence', [
            '--shipment-id' => $shipment->id,
            '--apply' => true,
            '--expect-fingerprint' => str_repeat('0', 64),
            '--json' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('shipment_carrier_billing', ['shipment_id' => $shipment->id]);
    }

    public function test_preview_blocks_when_source_is_not_configured(): void
    {
        $shipment = $this->scenario();
        $this->app->instance(DeliveryEvidenceGateway::class, new FakeDeliveryEvidenceGateway(false, []));

        $preview = app(DeliveryEvidenceIngestService::class)->preview($shipment->id);

        $this->assertSame('blocked', $preview['decision']);
        $this->assertSame('delivery_source_not_configured', $preview['reason']);
        $this->assertDatabaseMissing('shipment_carrier_billing', ['shipment_id' => $shipment->id]);
    }

    private function scenario(string $shipmentStatus = 'shipped'): Shipment
    {
        $suffix = uniqid();
        $order = Order::create([
            'internal_order_number' => 'DELIVERY-INGEST-'.$suffix,
            'erp_order_id' => '007-0914-c'.$suffix,
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'external_order_number' => 'DELIVERY-'.$suffix,
            'order_date' => '2026-09-14',
            'order_status' => 'shipped',
            'currency' => 'USD',
        ]);

        return $order->shipment()->create([
            'shipment_status' => $shipmentStatus,
            'carrier' => 'USPS',
            'tracking_number' => '9235990424546100226911',
            'shipped_at' => '2026-09-10 00:00:00',
        ]);
    }

    private function fake(string $tag, ?string $subtag = null): void
    {
        $this->app->instance(DeliveryEvidenceGateway::class, new FakeDeliveryEvidenceGateway(true, [
            'found' => true,
            'tracking_number' => '9235990424546100226911',
            'slug' => 'usps',
            'provider_reference' => 'aftership-track-1',
            'tag' => $tag,
            'subtag' => $subtag,
            'subtag_message' => $tag.' evidence',
            'provider_updated_at' => '2026-09-14T03:00:00Z',
            'checkpoint' => [
                'tag' => $tag,
                'subtag' => $subtag,
                'message' => 'bounded carrier checkpoint',
                'checkpoint_time' => '2026-09-14T02:55:00Z',
                'source' => 'carrier',
            ],
        ]));
    }
}

final class FakeDeliveryEvidenceGateway implements DeliveryEvidenceGateway
{
    /** @param array<string,mixed> $evidence */
    public function __construct(private readonly bool $configured, private readonly array $evidence) {}

    public function descriptor(): array
    {
        return ['configured' => $this->configured, 'source' => 'aftership_tracking_api', 'version' => '2026-07'];
    }

    public function inspect(string $slug, string $trackingNumber): array
    {
        return $this->evidence;
    }
}
