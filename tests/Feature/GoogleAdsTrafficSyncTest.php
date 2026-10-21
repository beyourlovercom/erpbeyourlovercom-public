<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\BusinessProjects\Infrastructure\Repositories\EloquentBusinessProjectRepository;
use App\Modules\Traffic\Application\Contracts\GoogleAdsReadClient;
use App\Modules\Traffic\Application\Contracts\TrafficRepository;
use App\Modules\Traffic\Application\GoogleAdsTrafficSyncService;
use App\Modules\Traffic\Application\TrafficService;
use App\Modules\Traffic\Infrastructure\Models\TrafficCampaign;
use App\Modules\Traffic\Infrastructure\Models\TrafficDailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use App\Modules\Traffic\Infrastructure\Repositories\EloquentTrafficRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class GoogleAdsTrafficSyncTest extends TestCase
{
    use RefreshDatabase;

    private BusinessProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = BusinessProject::create([
            'project_key' => 'yby-irrigation',
            'name' => 'YBY Irrigation',
            'status' => 'active',
        ]);
    }

    public function test_dry_run_forwards_bounded_range_and_writes_nothing(): void
    {
        $fake = FakeGoogleAdsReadClient::validatedFixture();
        $result = $this->service($fake)->sync('yby-irrigation', '502-024-8458', '2026-09-08', '2026-09-09', 'dry_run');

        $this->assertSame([], $result['blocked']);
        $this->assertSame(3, $result['campaigns_seen']);
        $this->assertSame(5, $result['daily_rows_seen']);
        $this->assertSame(0, $result['accounts_applied']);
        $this->assertSame(0, TrafficSourceAccount::count());
        $this->assertSame(0, TrafficCampaign::count());
        $this->assertSame(0, TrafficDailyMetric::count());
        $this->assertSame([['502-024-8458', '2026-09-08', '2026-09-09']], $fake->metricCalls);
    }

    public function test_apply_is_idempotent_preserves_integer_micros_and_updates_account_metadata(): void
    {
        $fake = FakeGoogleAdsReadClient::validatedFixture();
        $service = $this->service($fake);

        $first = $service->sync('yby-irrigation', '502-024-8458', '2026-09-08', '2026-09-09', 'apply');
        $this->assertSame([], $first['blocked']);
        $this->assertSame(1, $first['accounts_applied']);
        $this->assertSame(3, $first['campaigns_applied']);
        $this->assertSame(5, $first['daily_rows_applied']);
        $this->assertSame(1, TrafficSourceAccount::count());
        $this->assertSame(3, TrafficCampaign::count());
        $this->assertSame(5, TrafficDailyMetric::count());

        $metric = TrafficDailyMetric::where('metric_date', '2026-09-08')
            ->whereHas('campaign', fn ($query) => $query->where('external_campaign_id', '24023703280'))
            ->firstOrFail();
        $this->assertSame(108033832, $metric->cost_micros);
        $this->assertIsInt($metric->cost_micros);
        $this->assertSame(1.0, (float) $metric->provider_conversions);

        $zero = TrafficDailyMetric::where('metric_date', '2026-09-09')
            ->whereHas('campaign', fn ($query) => $query->where('external_campaign_id', '24220509988'))
            ->firstOrFail();
        $this->assertSame(0, $zero->cost_micros);
        $this->assertSame(0, $zero->clicks);

        $fake->account['name'] = '亿碧源-灌溉 Updated';
        $second = $service->sync('yby-irrigation', '502-024-8458', '2026-09-08', '2026-09-09', 'apply');

        $this->assertSame([], $second['blocked']);
        $this->assertSame(1, TrafficSourceAccount::count());
        $this->assertSame(3, TrafficCampaign::count());
        $this->assertSame(5, TrafficDailyMetric::count());
        $this->assertSame('亿碧源-灌溉 Updated', TrafficSourceAccount::firstOrFail()->name);
    }

    public function test_project_account_mismatch_blocks_before_source_read(): void
    {
        $other = BusinessProject::create(['project_key' => 'other-project', 'name' => 'Other', 'status' => 'active']);
        (new EloquentTrafficRepository())->upsertSourceAccount((int) $other->id, 'google_ads', '502-024-8458', ['name' => 'Other account']);
        $fake = FakeGoogleAdsReadClient::validatedFixture();

        $result = $this->service($fake)->sync('yby-irrigation', '502-024-8458', '2026-09-08', '2026-09-09', 'apply');

        $this->assertSame(['project_account_mismatch'], $result['blocked']);
        $this->assertSame(0, $fake->accountCalls);
        $this->assertSame([], $fake->metricCalls);
        $this->assertSame(1, TrafficSourceAccount::count());
        $this->assertSame(0, TrafficCampaign::count());
    }

    public function test_negative_or_malformed_metric_blocks_before_mutation(): void
    {
        $fake = FakeGoogleAdsReadClient::validatedFixture();
        $fake->rows[0]['cost_micros'] = -1;

        $result = $this->service($fake)->sync('yby-irrigation', '502-024-8458', '2026-09-08', '2026-09-09', 'apply');

        $this->assertSame(['invalid_metric_row'], $result['blocked']);
        $this->assertSame(0, TrafficSourceAccount::count());
        $this->assertSame(0, TrafficCampaign::count());
        $this->assertSame(0, TrafficDailyMetric::count());
    }

    public function test_apply_rolls_back_account_and_campaign_if_metric_write_fails(): void
    {
        $fake = FakeGoogleAdsReadClient::validatedFixture();
        $real = new EloquentTrafficRepository();
        $repo = Mockery::mock(TrafficRepository::class);
        $repo->shouldReceive('findSourceAccount')->andReturnUsing(fn (string $provider, string $account) => $real->findSourceAccount($provider, $account));
        $repo->shouldReceive('upsertSourceAccount')->andReturnUsing(fn (...$args) => $real->upsertSourceAccount(...$args));
        $repo->shouldReceive('upsertCampaign')->andReturnUsing(fn (...$args) => $real->upsertCampaign(...$args));
        $repo->shouldReceive('upsertDailyMetric')->andThrow(new RuntimeException('forced-mid-sync-failure'));

        $service = new GoogleAdsTrafficSyncService(
            new BusinessProjectService(new EloquentBusinessProjectRepository()),
            $fake,
            $repo,
            new TrafficService($repo),
        );

        try {
            $service->sync('yby-irrigation', '502-024-8458', '2026-09-08', '2026-09-09', 'apply');
            $this->fail('Expected the forced repository failure to escape the transaction.');
        } catch (RuntimeException $e) {
            $this->assertSame('forced-mid-sync-failure', $e->getMessage());
        }

        $this->assertSame(0, TrafficSourceAccount::count());
        $this->assertSame(0, TrafficCampaign::count());
        $this->assertSame(0, TrafficDailyMetric::count());
    }

    public function test_command_outputs_deterministic_json_and_defaults_to_dry_run(): void
    {
        $fake = FakeGoogleAdsReadClient::validatedFixture();
        $this->app->instance(GoogleAdsReadClient::class, $fake);

        $expected = json_encode([
            'mode' => 'dry_run',
            'project_key' => 'yby-irrigation',
            'external_account_id' => '502-024-8458',
            'date_from' => '2026-09-08',
            'date_to' => '2026-09-09',
            'campaigns_seen' => 3,
            'daily_rows_seen' => 5,
            'accounts_applied' => 0,
            'campaigns_applied' => 0,
            'daily_rows_applied' => 0,
            'blocked' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->artisan('traffic:google-ads:sync', [
            '--project' => 'yby-irrigation',
            '--account' => '502-024-8458',
            '--from' => '2026-09-08',
            '--to' => '2026-09-09',
        ])->expectsOutput($expected)->assertSuccessful();

        $this->assertSame(0, TrafficSourceAccount::count());
    }

    private function service(FakeGoogleAdsReadClient $fake): GoogleAdsTrafficSyncService
    {
        $this->app->instance(GoogleAdsReadClient::class, $fake);

        return $this->app->make(GoogleAdsTrafficSyncService::class);
    }
}

final class FakeGoogleAdsReadClient implements GoogleAdsReadClient
{
    /** @var array<string, mixed> */
    public array $account;

    /** @var array<int, array<string, mixed>> */
    public array $rows;

    public int $accountCalls = 0;

    /** @var array<int, array{0:string,1:string,2:string}> */
    public array $metricCalls = [];

    /** @param array<string, mixed> $account @param array<int, array<string, mixed>> $rows */
    public function __construct(array $account, array $rows)
    {
        $this->account = $account;
        $this->rows = $rows;
    }

    public static function validatedFixture(): self
    {
        return new self([
            'external_account_id' => '502-024-8458',
            'name' => '亿碧源-灌溉',
            'currency_code' => 'CNY',
            'account_timezone' => 'Asia/Shanghai',
        ], [
            [
                'external_campaign_id' => '21455972299',
                'campaign_name' => '灌溉系统-肯尼亚7.9',
                'campaign_status' => 'ENABLED',
                'advertising_channel_type' => 'SEARCH',
                'metric_date' => '2026-09-08',
                'impressions' => 127,
                'clicks' => 25,
                'cost_micros' => 34034808,
                'provider_conversions' => 0,
            ],
            [
                'external_campaign_id' => '24023703280',
                'campaign_name' => 'YBY-Search-IRR-SOLUTION-Tanzania',
                'campaign_status' => 'ENABLED',
                'advertising_channel_type' => 'SEARCH',
                'metric_date' => '2026-09-08',
                'impressions' => 8,
                'clicks' => 1,
                'cost_micros' => '108033832',
                'provider_conversions' => 1,
            ],
            [
                'external_campaign_id' => '24220509988',
                'campaign_name' => 'IRR-搜索-批发-TZ',
                'campaign_status' => 'ENABLED',
                'advertising_channel_type' => 'SEARCH',
                'metric_date' => '2026-09-08',
                'impressions' => 6,
                'clicks' => 2,
                'cost_micros' => 12160000,
                'provider_conversions' => 2,
            ],
            [
                'external_campaign_id' => '24023703280',
                'campaign_name' => 'YBY-Search-IRR-SOLUTION-Tanzania',
                'campaign_status' => 'ENABLED',
                'advertising_channel_type' => 'SEARCH',
                'metric_date' => '2026-09-09',
                'impressions' => 22,
                'clicks' => 4,
                'cost_micros' => 154100000,
                'provider_conversions' => 3,
            ],
            [
                'external_campaign_id' => '24220509988',
                'campaign_name' => 'IRR-搜索-批发-TZ',
                'campaign_status' => 'ENABLED',
                'advertising_channel_type' => 'SEARCH',
                'metric_date' => '2026-09-09',
                'impressions' => 3,
                'clicks' => 0,
                'cost_micros' => 0,
                'provider_conversions' => 0,
            ],
        ]);
    }

    public function fetchAccount(string $externalAccountId): array
    {
        $this->accountCalls++;

        return $this->account;
    }

    public function fetchCampaignDailyMetrics(string $externalAccountId, string $dateFrom, string $dateTo): iterable
    {
        $this->metricCalls[] = [$externalAccountId, $dateFrom, $dateTo];

        return $this->rows;
    }
}
