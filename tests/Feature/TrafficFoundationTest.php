<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\Contracts\TrafficRepository;
use App\Modules\Traffic\Application\TrafficService;
use App\Modules\Traffic\Infrastructure\Models\LandingPage;
use App\Modules\Traffic\Infrastructure\Models\TrafficCampaign;
use App\Modules\Traffic\Infrastructure\Models\TrafficDailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficInquiryDailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use App\Modules\Traffic\Infrastructure\Repositories\EloquentTrafficRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TrafficFoundationTest extends TestCase
{
    use RefreshDatabase;

    private TrafficService $service;
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TrafficService(new EloquentTrafficRepository());
        $project = BusinessProject::create(['project_key' => 'yby-t01', 'name' => 'YBY Traffic', 'status' => 'active']);
        $this->projectId = $project->id;
    }

    // ── Contract binding ───────────────────────────────────────────────────────

    public function test_service_provider_binds_repository_contract(): void
    {
        $repo = $this->app->make(TrafficRepository::class);
        $this->assertInstanceOf(EloquentTrafficRepository::class, $repo);
    }

    // ── Idempotent upserts: TrafficSourceAccount ───────────────────────────────

    public function test_upsert_source_account_creates_on_first_call(): void
    {
        $account = $this->service->upsertTrafficSourceAccount($this->projectId, 'google_ads', 'ACC-001');

        $this->assertSame(1, TrafficSourceAccount::count());
        $this->assertSame('google_ads', $account->provider);
        $this->assertSame('ACC-001', $account->external_account_id);
    }

    public function test_upsert_source_account_is_idempotent(): void
    {
        $this->service->upsertTrafficSourceAccount($this->projectId, 'google_ads', 'ACC-001', ['name' => 'First']);
        $second = $this->service->upsertTrafficSourceAccount($this->projectId, 'google_ads', 'ACC-001', ['name' => 'Updated']);

        $this->assertSame(1, TrafficSourceAccount::count());
        $this->assertSame('Updated', $second->name);
    }

    public function test_upsert_source_account_allows_different_providers(): void
    {
        $this->service->upsertTrafficSourceAccount($this->projectId, 'google_ads', 'ACC-001');
        $this->service->upsertTrafficSourceAccount($this->projectId, 'meta_ads', 'ACC-001');

        $this->assertSame(2, TrafficSourceAccount::count());
    }

    // ── Idempotent upserts: TrafficCampaign ───────────────────────────────────

    public function test_upsert_campaign_creates_and_updates_idempotently(): void
    {
        $account = $this->service->upsertTrafficSourceAccount($this->projectId, 'google_ads', 'ACC-001');

        $this->service->upsertTrafficCampaign($account->id, 'CAM-001', ['name' => 'Campaign A', 'status' => 'enabled']);
        $second = $this->service->upsertTrafficCampaign($account->id, 'CAM-001', ['name' => 'Campaign A Updated', 'status' => 'paused']);

        $this->assertSame(1, TrafficCampaign::count());
        $this->assertSame('Campaign A Updated', $second->name);
        $this->assertSame('paused', $second->status);
    }

    // ── Idempotent upserts: TrafficDailyMetric with integer cost_micros ────────

    public function test_upsert_daily_metric_stores_cost_micros_as_integer(): void
    {
        $account = $this->service->upsertTrafficSourceAccount($this->projectId, 'google_ads', 'ACC-001');
        $campaign = $this->service->upsertTrafficCampaign($account->id, 'CAM-001', ['name' => 'Campaign A']);

        $metric = $this->service->upsertTrafficDailyMetric($campaign->id, '2026-09-11', [
            'impressions' => 5000,
            'clicks' => 120,
            'cost_micros' => 25000000,
            'provider_conversions' => 3.5,
        ]);

        $this->assertSame(1, TrafficDailyMetric::count());
        $this->assertSame(25000000, $metric->cost_micros);
        $this->assertIsInt($metric->cost_micros);
        $this->assertSame(5000, $metric->impressions);
        $this->assertSame(120, $metric->clicks);
    }

    public function test_upsert_daily_metric_is_idempotent(): void
    {
        $account = $this->service->upsertTrafficSourceAccount($this->projectId, 'google_ads', 'ACC-001');
        $campaign = $this->service->upsertTrafficCampaign($account->id, 'CAM-001', ['name' => 'Campaign A']);

        $this->service->upsertTrafficDailyMetric($campaign->id, '2026-09-11', ['cost_micros' => 10000000]);
        $second = $this->service->upsertTrafficDailyMetric($campaign->id, '2026-09-11', ['cost_micros' => 20000000]);

        $this->assertSame(1, TrafficDailyMetric::count());
        $this->assertSame(20000000, $second->cost_micros);
    }

    // ── Idempotent upserts: TrafficInquiryDailyMetric ─────────────────────────

    public function test_upsert_inquiry_daily_metric_creates_and_updates_idempotently(): void
    {
        $this->service->upsertTrafficInquiryDailyMetric($this->projectId, '2026-09-11', 'organic', ['inquiries' => 10, 'qualified_inquiries' => 3]);
        $second = $this->service->upsertTrafficInquiryDailyMetric($this->projectId, '2026-09-11', 'organic', ['inquiries' => 15, 'qualified_inquiries' => 5]);

        $this->assertSame(1, TrafficInquiryDailyMetric::count());
        $this->assertSame(15, $second->inquiries);
        $this->assertSame(5, $second->qualified_inquiries);
    }

    public function test_upsert_inquiry_metric_allows_different_source_types_same_date(): void
    {
        $this->service->upsertTrafficInquiryDailyMetric($this->projectId, '2026-09-11', 'organic', ['inquiries' => 10]);
        $this->service->upsertTrafficInquiryDailyMetric($this->projectId, '2026-09-11', 'paid', ['inquiries' => 5]);

        $this->assertSame(2, TrafficInquiryDailyMetric::count());
    }

    // ── Idempotent upserts: LandingPage ───────────────────────────────────────

    public function test_register_landing_page_is_idempotent(): void
    {
        $this->service->registerLandingPage($this->projectId, 'home', ['name' => 'Home', 'canonical_url' => 'https://example.com/', 'status' => 'active']);
        $second = $this->service->registerLandingPage($this->projectId, 'home', ['name' => 'Home Updated', 'canonical_url' => 'https://example.com/', 'status' => 'active']);

        $this->assertSame(1, LandingPage::count());
        $this->assertSame('Home Updated', $second->name);
    }

    public function test_list_landing_pages_filters_by_status(): void
    {
        $this->service->registerLandingPage($this->projectId, 'home', ['name' => 'Home', 'canonical_url' => 'https://example.com/', 'status' => 'active']);
        $this->service->registerLandingPage($this->projectId, 'promo', ['name' => 'Promo', 'canonical_url' => 'https://example.com/promo', 'status' => 'inactive']);

        $active = $this->service->listLandingPages($this->projectId, 'active');
        $this->assertCount(1, $active);
        $this->assertSame('home', $active->first()->page_key);
    }

    // ── Query ranges ──────────────────────────────────────────────────────────

    public function test_list_project_daily_traffic_metrics_respects_date_range(): void
    {
        $account = $this->service->upsertTrafficSourceAccount($this->projectId, 'google_ads', 'ACC-001');
        $campaign = $this->service->upsertTrafficCampaign($account->id, 'CAM-001', ['name' => 'Campaign A']);

        $this->service->upsertTrafficDailyMetric($campaign->id, '2026-09-01', ['impressions' => 100]);
        $this->service->upsertTrafficDailyMetric($campaign->id, '2026-09-11', ['impressions' => 200]);
        $this->service->upsertTrafficDailyMetric($campaign->id, '2026-09-20', ['impressions' => 300]);

        $results = $this->service->listProjectDailyTrafficMetrics($this->projectId, '2026-09-01', '2026-09-11');

        $this->assertCount(2, $results);
        $this->assertSame(100, $results->first()->impressions);
        $this->assertSame(200, $results->last()->impressions);
    }

    public function test_list_project_daily_inquiry_metrics_respects_date_range(): void
    {
        $this->service->upsertTrafficInquiryDailyMetric($this->projectId, '2026-09-01', 'organic', ['inquiries' => 5]);
        $this->service->upsertTrafficInquiryDailyMetric($this->projectId, '2026-09-11', 'organic', ['inquiries' => 10]);
        $this->service->upsertTrafficInquiryDailyMetric($this->projectId, '2026-09-20', 'organic', ['inquiries' => 15]);

        $results = $this->service->listProjectDailyInquiryMetrics($this->projectId, '2026-09-11', '2026-09-20');

        $this->assertCount(2, $results);
        $this->assertSame(10, $results->first()->inquiries);
        $this->assertSame(15, $results->last()->inquiries);
    }

    public function test_list_traffic_metrics_returns_empty_for_out_of_range(): void
    {
        $account = $this->service->upsertTrafficSourceAccount($this->projectId, 'google_ads', 'ACC-001');
        $campaign = $this->service->upsertTrafficCampaign($account->id, 'CAM-001', ['name' => 'Campaign A']);
        $this->service->upsertTrafficDailyMetric($campaign->id, '2026-09-11', ['impressions' => 100]);

        $results = $this->service->listProjectDailyTrafficMetrics($this->projectId, '2026-08-01', '2026-08-31');
        $this->assertCount(0, $results);
    }
}
