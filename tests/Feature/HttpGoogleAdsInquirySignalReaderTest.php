<?php

namespace Tests\Feature;

use App\Modules\Traffic\Application\Contracts\InquiryMetricSignalReader;
use App\Modules\Traffic\Application\Exceptions\GoogleAdsReadException;
use App\Modules\Traffic\Infrastructure\GoogleAds\HttpGoogleAdsReadClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpGoogleAdsInquirySignalReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google_ads.client_id', 'client-id');
        config()->set('services.google_ads.client_secret', 'client-secret');
        config()->set('services.google_ads.refresh_token', 'refresh-token');
        config()->set('services.google_ads.developer_token', '');
        config()->set('services.google_ads.login_customer_id', '');
        config()->set('services.google_ads.api_version', 'v25');
        config()->set('services.google_ads.inquiry_actions', [
            'google_ads_form_lead' => 'YBY | WP | 表单Lead提交-V2607',
            'google_ads_whatsapp_lead' => 'YBY | WP | WA聊天留言-V2607',
        ]);
    }

    public function test_service_provider_binds_reader_to_read_only_google_ads_adapter(): void
    {
        $this->assertInstanceOf(HttpGoogleAdsReadClient::class, $this->app->make(InquiryMetricSignalReader::class));
    }

    public function test_adapter_maps_named_actions_and_fills_zero_days(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token'], 200),
            'https://googleads.googleapis.com/*' => Http::sequence()
                ->push([['results' => [
                    ['conversionAction' => ['resourceName' => 'customers/5020248458/conversionActions/10', 'name' => 'YBY | WP | 表单Lead提交-V2607', 'status' => 'ENABLED']],
                    ['conversionAction' => ['resourceName' => 'customers/5020248458/conversionActions/11', 'name' => 'YBY | WP | WA聊天留言-V2607', 'status' => 'ENABLED']],
                ]]], 200)
                ->push([['results' => [
                    ['segments' => ['date' => '2026-09-08', 'conversionAction' => 'customers/5020248458/conversionActions/10'], 'metrics' => ['conversions' => 3.0]],
                    ['segments' => ['date' => '2026-09-09', 'conversionAction' => 'customers/5020248458/conversionActions/10'], 'metrics' => ['conversions' => '6.0']],
                    ['segments' => ['date' => '2026-09-09', 'conversionAction' => 'customers/5020248458/conversionActions/11'], 'metrics' => ['conversions' => 1]],
                ]]], 200),
        ]);

        $rows = iterator_to_array((new HttpGoogleAdsReadClient())->fetchDailySignals('502-024-8458', '2026-09-08', '2026-09-09'));
        $this->assertSame([
            ['metric_date' => '2026-09-08', 'source_type' => 'google_ads_form_lead', 'inquiries' => 3],
            ['metric_date' => '2026-09-08', 'source_type' => 'google_ads_whatsapp_lead', 'inquiries' => 0],
            ['metric_date' => '2026-09-09', 'source_type' => 'google_ads_form_lead', 'inquiries' => 6],
            ['metric_date' => '2026-09-09', 'source_type' => 'google_ads_whatsapp_lead', 'inquiries' => 1],
        ], $rows);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => ! str_contains(strtolower((string) ($request['query'] ?? '')), 'mutate'));
    }

    public function test_missing_configured_action_blocks(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token'], 200),
            'https://googleads.googleapis.com/*' => Http::response([['results' => [
                ['conversionAction' => ['resourceName' => 'customers/5020248458/conversionActions/10', 'name' => 'YBY | WP | 表单Lead提交-V2607', 'status' => 'ENABLED']],
            ]]], 200),
        ]);

        try {
            iterator_to_array((new HttpGoogleAdsReadClient())->fetchDailySignals('502-024-8458', '2026-09-08', '2026-09-09'));
            $this->fail('Expected inquiry_action_not_found.');
        } catch (GoogleAdsReadException $e) {
            $this->assertSame('inquiry_action_not_found', $e->status);
        }
    }

    public function test_fractional_signal_is_rejected(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token'], 200),
            'https://googleads.googleapis.com/*' => Http::sequence()
                ->push([['results' => [
                    ['conversionAction' => ['resourceName' => 'customers/5020248458/conversionActions/10', 'name' => 'YBY | WP | 表单Lead提交-V2607', 'status' => 'ENABLED']],
                    ['conversionAction' => ['resourceName' => 'customers/5020248458/conversionActions/11', 'name' => 'YBY | WP | WA聊天留言-V2607', 'status' => 'ENABLED']],
                ]]], 200)
                ->push([['results' => [[
                    'segments' => ['date' => '2026-09-08', 'conversionAction' => 'customers/5020248458/conversionActions/10'],
                    'metrics' => ['conversions' => 1.5],
                ]]]], 200),
        ]);

        try {
            iterator_to_array((new HttpGoogleAdsReadClient())->fetchDailySignals('502-024-8458', '2026-09-08', '2026-09-09'));
            $this->fail('Expected fractional_inquiry_signal.');
        } catch (GoogleAdsReadException $e) {
            $this->assertSame('fractional_inquiry_signal', $e->status);
        }
    }
}
