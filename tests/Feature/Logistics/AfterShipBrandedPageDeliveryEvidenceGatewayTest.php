<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Infrastructure\AfterShipBrandedPageDeliveryEvidenceGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class AfterShipBrandedPageDeliveryEvidenceGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.aftership_branded_page.base_url' => 'https://beyourlover.aftership.com',
            'services.aftership_branded_page.parser_version' => 'semantic-html-v1',
            'services.aftership_branded_page.timeout' => 5,
        ]);
    }

    public function test_4px_shipping_soon_preserves_raw_info_received_stage(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/aftership/4px-info-received.html'));
        Http::fake(['https://beyourlover.aftership.com/4PX3003147244389CN*' => Http::response($html, 200)]);

        $evidence = app(AfterShipBrandedPageDeliveryEvidenceGateway::class)->inspect('4px', '4PX3003147244389CN');

        $this->assertTrue($evidence['found']);
        $this->assertSame('InfoReceived', $evidence['tag']);
        $this->assertSame('info_received', $evidence['subtag']);
        $this->assertSame('Your order will ship soon', $evidence['subtag_message']);
        $this->assertSame('Parcel information received', $evidence['checkpoint']['message']);
        $this->assertSame('4PX', $evidence['checkpoint']['source']);
        $this->assertSame('semantic-html-v1', $evidence['checkpoint']['parser_version']);
    }

    public function test_usps_delivered_fixture_maps_to_delivered(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/aftership/usps-delivered.html'));
        Http::fake(['https://beyourlover.aftership.com/9235990424546100226911*' => Http::response($html, 200)]);

        $evidence = app(AfterShipBrandedPageDeliveryEvidenceGateway::class)->inspect('usps', '9235990424546100226911');

        $this->assertTrue($evidence['found']);
        $this->assertSame('Delivered', $evidence['tag']);
        $this->assertSame('delivered', $evidence['subtag']);
        $this->assertSame('Delivered to Agent, Left with Individual', $evidence['checkpoint']['message']);
        $this->assertSame('USPS', $evidence['checkpoint']['source']);
    }

    public function test_unknown_semantic_status_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported or ambiguous');

        app(AfterShipBrandedPageDeliveryEvidenceGateway::class)->parse(
            '<html><body><div role="heading" aria-level="2" aria-label="Something changed">Something changed</div><div>TRACK-X</div></body></html>',
            'usps',
            'TRACK-X',
            'https://beyourlover.aftership.com/TRACK-X',
        );
    }
}
