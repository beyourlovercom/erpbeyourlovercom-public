<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Infrastructure\AfterShipDeliveryEvidenceGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AfterShipDeliveryEvidenceGatewayTest extends TestCase
{
    public function test_it_reads_current_tracking_api_and_returns_bounded_delivery_evidence(): void
    {
        config()->set('services.aftership_tracking.api_key', 'test-api-key');
        config()->set('services.aftership_tracking.base_url', 'https://api.aftership.com/tracking');
        config()->set('services.aftership_tracking.version', '2026-07');
        Http::fake([
            'https://api.aftership.com/tracking/2026-07/trackings*' => Http::response([
                'data' => ['trackings' => [[
                    'id' => 'tracking-1',
                    'tracking_number' => '9235990424546100226911',
                    'slug' => 'usps',
                    'tag' => 'Delivered',
                    'subtag' => 'Delivered_003',
                    'subtag_message' => 'Signed by customer',
                    'updated_at' => '2026-09-14T03:00:00Z',
                    'customers' => [['name' => 'DO NOT PERSIST']],
                    'checkpoints' => [[
                        'tag' => 'Delivered',
                        'subtag' => 'Delivered_003',
                        'subtag_message' => 'Signed by customer',
                        'message' => 'Delivered',
                        'checkpoint_time' => '2026-09-14T02:55:00Z',
                        'source' => 'carrier',
                        'country_region_name' => 'DO NOT PERSIST',
                    ]],
                ]]],
            ], 200),
        ]);

        $result = app(AfterShipDeliveryEvidenceGateway::class)->inspect('usps', '9235990424546100226911');

        $this->assertTrue($result['found']);
        $this->assertSame('tracking-1', $result['provider_reference']);
        $this->assertSame('Delivered', $result['tag']);
        $this->assertSame('Delivered_003', $result['subtag']);
        $this->assertSame('carrier', $result['checkpoint']['source']);
        $this->assertArrayNotHasKey('customers', $result);
        $this->assertArrayNotHasKey('country_region_name', $result['checkpoint']);
        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('as-api-key', 'test-api-key')
                && $request->url() === 'https://api.aftership.com/tracking/2026-07/trackings?tracking_numbers=9235990424546100226911&slug=usps&limit=10';
        });
    }

    public function test_it_returns_not_found_for_no_exact_tracking_match(): void
    {
        config()->set('services.aftership_tracking.api_key', 'test-api-key');
        Http::fake(['*' => Http::response(['data' => ['trackings' => []]], 200)]);

        $result = app(AfterShipDeliveryEvidenceGateway::class)->inspect('usps', '9235990424546100226911');

        $this->assertFalse($result['found']);
        $this->assertNull($result['tag']);
    }
}
