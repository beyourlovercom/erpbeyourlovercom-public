<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\EcbFxService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EcbFxServiceTest extends TestCase
{
    private const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    private function ecbXml(array $rates = [], string $date = '2026-08-28'): string
    {
        $rateXml = '';
        foreach ($rates as $currency => $rate) {
            $rateXml .= "<Cube currency=\"{$currency}\" rate=\"{$rate}\"/>";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
                 xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
    <Cube>
        <Cube time="{$date}">
            {$rateXml}
        </Cube>
    </Cube>
</gesmes:Envelope>
XML;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('ecb_fx_snapshot');
    }

    public function test_eur_to_cny_conversion(): void
    {
        Http::fake([self::ECB_URL => Http::response($this->ecbXml(['CNY' => '7.8']), 200)]);

        $service = app(EcbFxService::class);
        $result = $service->convertToCny([['currency' => 'EUR', 'revenue' => 100.0]]);

        $this->assertTrue($result['available']);
        $this->assertEqualsWithDelta(780.0, $result['amount_cny'], 0.001);
        $this->assertEquals('ECB', $result['source']);
        $this->assertEquals('2026-08-28', $result['reference_date']);
        $this->assertFalse($result['stale']);
    }

    public function test_usd_to_cny_cross_rate(): void
    {
        // ECB_CNY / ECB_USD = 7.8 / 1.1 = 7.0909... CNY per USD
        Http::fake([self::ECB_URL => Http::response($this->ecbXml(['CNY' => '7.8', 'USD' => '1.1']), 200)]);

        $service = app(EcbFxService::class);
        $result = $service->convertToCny([['currency' => 'USD', 'revenue' => 110.0]]);

        $this->assertTrue($result['available']);
        $this->assertEqualsWithDelta(780.0, $result['amount_cny'], 0.01);
    }

    public function test_cny_source_uses_rate_1(): void
    {
        Http::fake([self::ECB_URL => Http::response($this->ecbXml(['CNY' => '7.8']), 200)]);

        $service = app(EcbFxService::class);
        $result = $service->convertToCny([['currency' => 'CNY', 'revenue' => 500.0]]);

        $this->assertTrue($result['available']);
        $this->assertEqualsWithDelta(500.0, $result['amount_cny'], 0.001);
    }

    public function test_mixed_eur_usd_cny(): void
    {
        Http::fake([self::ECB_URL => Http::response($this->ecbXml(['CNY' => '7.8', 'USD' => '1.1']), 200)]);

        $service = app(EcbFxService::class);
        $result = $service->convertToCny([
            ['currency' => 'EUR', 'revenue' => 100.0],  // 780
            ['currency' => 'USD', 'revenue' => 110.0],  // 780
            ['currency' => 'CNY', 'revenue' => 200.0],  // 200
        ]);

        $this->assertTrue($result['available']);
        $this->assertEqualsWithDelta(1760.0, $result['amount_cny'], 0.1);
    }

    public function test_unsupported_currency_returns_unavailable(): void
    {
        Http::fake([self::ECB_URL => Http::response($this->ecbXml(['CNY' => '7.8', 'USD' => '1.1']), 200)]);

        $service = app(EcbFxService::class);
        // GBP is not in our fake XML
        $result = $service->convertToCny([['currency' => 'GBP', 'revenue' => 50.0]]);

        $this->assertFalse($result['available']);
        $this->assertEquals('unsupported_currency', $result['reason']);
    }

    public function test_empty_revenue_returns_zero_available(): void
    {
        Http::fake([self::ECB_URL => Http::response($this->ecbXml(['CNY' => '7.8']), 200)]);

        $service = app(EcbFxService::class);
        $result = $service->convertToCny([]);

        $this->assertTrue($result['available']);
        $this->assertEqualsWithDelta(0.0, $result['amount_cny'], 0.001);
    }

    public function test_cached_fallback_used_when_fetch_fails(): void
    {
        // Prime the cache
        Cache::put('ecb_fx_snapshot', [
            'rates' => ['CNY' => 7.5, 'USD' => 1.05],
            'reference_date' => '2026-08-27',
            'fetched_at' => now()->subHours(25)->toIso8601String(),
        ]);

        Http::fake([self::ECB_URL => Http::response('', 500)]);

        $service = app(EcbFxService::class);
        $result = $service->convertToCny([['currency' => 'EUR', 'revenue' => 100.0]]);

        $this->assertTrue($result['available']);
        $this->assertTrue($result['stale']);
        $this->assertEqualsWithDelta(750.0, $result['amount_cny'], 0.001);
    }

    public function test_no_snapshot_and_fetch_fails_returns_unavailable(): void
    {
        Http::fake([self::ECB_URL => Http::response('', 500)]);

        $service = app(EcbFxService::class);
        $result = $service->convertToCny([['currency' => 'USD', 'revenue' => 100.0]]);

        $this->assertFalse($result['available']);
        $this->assertEquals('fx_unavailable', $result['reason']);
    }

    public function test_fresh_cache_not_refetched(): void
    {
        Cache::put('ecb_fx_snapshot', [
            'rates' => ['CNY' => 8.0],
            'reference_date' => '2026-08-28',
            'fetched_at' => now()->subHour()->toIso8601String(),
        ]);

        Http::fake([self::ECB_URL => Http::response($this->ecbXml(['CNY' => '7.8']), 200)]);

        $service = app(EcbFxService::class);
        $result = $service->convertToCny([['currency' => 'EUR', 'revenue' => 100.0]]);

        // Should use cached rate of 8.0 not fetched 7.8
        $this->assertEqualsWithDelta(800.0, $result['amount_cny'], 0.001);
        Http::assertNothingSent();
    }
}
