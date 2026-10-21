<?php

namespace Tests\Feature;

use App\Modules\Traffic\Application\Contracts\GoogleAdsReadClient;
use App\Modules\Traffic\Application\Exceptions\GoogleAdsReadException;
use App\Modules\Traffic\Infrastructure\GoogleAds\HttpGoogleAdsReadClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpGoogleAdsReadClientTest extends TestCase
{
    public function test_service_provider_binds_real_read_client(): void
    {
        $this->assertInstanceOf(HttpGoogleAdsReadClient::class, $this->app->make(GoogleAdsReadClient::class));
    }

    public function test_missing_credentials_fail_with_machine_readable_status_before_http(): void
    {
        config()->set('services.google_ads.client_id', '');
        config()->set('services.google_ads.client_secret', '');
        config()->set('services.google_ads.refresh_token', '');
        Http::fake();

        try {
            (new HttpGoogleAdsReadClient())->fetchAccount('502-024-8458');
            $this->fail('Expected credentials_missing.');
        } catch (GoogleAdsReadException $e) {
            $this->assertSame('credentials_missing', $e->status);
        }

        Http::assertNothingSent();
    }

    public function test_real_adapter_maps_account_and_campaign_daily_rows_using_read_only_search_stream(): void
    {
        config()->set('services.google_ads.client_id', 'client-id');
        config()->set('services.google_ads.client_secret', 'client-secret');
        config()->set('services.google_ads.refresh_token', 'refresh-token');
        config()->set('services.google_ads.developer_token', '');
        config()->set('services.google_ads.login_customer_id', '');
        config()->set('services.google_ads.api_version', 'v25');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token'], 200),
            'https://googleads.googleapis.com/*' => Http::sequence()
                ->push([[
                    'results' => [[
                        'customer' => [
                            'id' => '5020248458',
                            'descriptiveName' => '亿碧源-灌溉',
                            'currencyCode' => 'CNY',
                            'timeZone' => 'Asia/Shanghai',
                        ],
                    ]],
                ]], 200)
                ->push([[
                    'results' => [[
                        'campaign' => [
                            'id' => '24023703280',
                            'name' => 'YBY-Search-IRR-SOLUTION-Tanzania',
                            'status' => 'ENABLED',
                            'advertisingChannelType' => 'SEARCH',
                        ],
                        'segments' => ['date' => '2026-09-08'],
                        'metrics' => [
                            'impressions' => '8',
                            'clicks' => '1',
                            'costMicros' => '108033832',
                            'conversions' => 1.0,
                        ],
                    ]],
                ]], 200),
        ]);

        $client = new HttpGoogleAdsReadClient();
        $account = $client->fetchAccount('502-024-8458');
        $rows = iterator_to_array($client->fetchCampaignDailyMetrics('502-024-8458', '2026-09-08', '2026-09-09'));

        $this->assertSame([
            'external_account_id' => '502-024-8458',
            'name' => '亿碧源-灌溉',
            'currency_code' => 'CNY',
            'account_timezone' => 'Asia/Shanghai',
        ], $account);
        $this->assertCount(1, $rows);
        $this->assertSame('24023703280', $rows[0]['external_campaign_id']);
        $this->assertSame('2026-09-08', $rows[0]['metric_date']);
        $this->assertSame(108033832, $rows[0]['cost_micros']);
        $this->assertSame(1.0, $rows[0]['provider_conversions']);

        Http::assertSentCount(3);
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/googleAds:searchStream')) {
                return true;
            }

            $query = (string) ($request['query'] ?? '');

            return ! str_contains(strtolower($query), 'mutate');
        });
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/googleAds:searchStream')) {
                return false;
            }

            $query = (string) ($request['query'] ?? '');

            return str_contains($query, "segments.date BETWEEN '2026-09-08' AND '2026-09-09'");
        });
    }
}
