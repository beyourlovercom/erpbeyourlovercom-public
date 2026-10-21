<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Application\Contracts\InquiryMetricSignalReader;
use App\Modules\Traffic\Application\InquiryMetricsSyncService;
use App\Modules\Traffic\Infrastructure\Models\TrafficInquiryDailyMetric;
use App\Modules\Traffic\Infrastructure\Repositories\EloquentTrafficRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InquiryMetricsSyncTest extends TestCase
{
    use RefreshDatabase;

    private BusinessProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = BusinessProject::create([
            'project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active',
        ]);
        (new EloquentTrafficRepository())->upsertSourceAccount(
            (int) $this->project->id, 'google_ads', '502-024-8458', ['name' => '亿碧源-灌溉'],
        );
    }

    public function test_dry_run_projects_two_leaf_sources_without_writes(): void
    {
        $fake = FakeInquiryMetricSignalReader::validatedFixture();
        $this->app->instance(InquiryMetricSignalReader::class, $fake);

        $result = $this->app->make(InquiryMetricsSyncService::class)
            ->sync('yby-irrigation', '502-024-8458', '2026-09-08', '2026-09-09', 'dry_run');

        $this->assertSame([], $result['blocked']);
        $this->assertSame(['google_ads_form_lead', 'google_ads_whatsapp_lead'], $result['source_types_seen']);
        $this->assertSame(4, $result['daily_rows_seen']);
        $this->assertSame(6, $result['inquiries_seen']);
        $this->assertSame(0, $result['qualified_inquiries_seen']);
        $this->assertSame(InquiryMetricsSyncService::QUALIFICATION_STATE, $result['qualification_state']);
        $this->assertSame(0, TrafficInquiryDailyMetric::count());
        $this->assertSame([['502-024-8458', '2026-09-08', '2026-09-09']], $fake->calls);
    }

    public function test_apply_is_idempotent_and_keeps_qualification_unavailable(): void
    {
        $fake = FakeInquiryMetricSignalReader::validatedFixture();
        $this->app->instance(InquiryMetricSignalReader::class, $fake);
        $service = $this->app->make(InquiryMetricsSyncService::class);

        $first = $service->sync('yby-irrigation', '502-024-8458', '2026-09-08', '2026-09-09', 'apply');
        $this->assertSame([], $first['blocked']);
        $this->assertSame(4, $first['daily_rows_applied']);
        $this->assertSame(4, TrafficInquiryDailyMetric::count());
        $this->assertSame(6, TrafficInquiryDailyMetric::sum('inquiries'));
        $this->assertSame(0, TrafficInquiryDailyMetric::sum('qualified_inquiries'));

        $second = $service->sync('yby-irrigation', '502-024-8458', '2026-09-08', '2026-09-09', 'apply');
        $this->assertSame([], $second['blocked']);
        $this->assertSame(4, TrafficInquiryDailyMetric::count());

        $zero = TrafficInquiryDailyMetric::where('metric_date', '2026-09-09')
            ->where('source_type', 'google_ads_whatsapp_lead')->firstOrFail();
        $this->assertSame(0, $zero->inquiries);
        $this->assertSame(0, $zero->qualified_inquiries);
    }

    public function test_missing_or_mismatched_source_account_blocks_before_reader(): void
    {
        $fake = FakeInquiryMetricSignalReader::validatedFixture();
        $this->app->instance(InquiryMetricSignalReader::class, $fake);
        $service = $this->app->make(InquiryMetricsSyncService::class);

        $missing = $service->sync('yby-irrigation', '111-222-3333', '2026-09-08', '2026-09-09', 'apply');
        $this->assertSame(['source_account_not_found'], $missing['blocked']);

        $other = BusinessProject::create(['project_key' => 'other-project', 'name' => 'Other', 'status' => 'active']);
        (new EloquentTrafficRepository())->upsertSourceAccount((int) $other->id, 'google_ads', '999-888-7777', ['name' => 'Other']);
        $mismatch = $service->sync('yby-irrigation', '999-888-7777', '2026-09-08', '2026-09-09', 'apply');
        $this->assertSame(['project_account_mismatch'], $mismatch['blocked']);
        $this->assertSame([], $fake->calls);
    }

    public function test_invalid_or_duplicate_signal_rows_block_before_mutation(): void
    {
        $fake = FakeInquiryMetricSignalReader::validatedFixture();
        $fake->rows[0]['inquiries'] = 1.5;
        $this->app->instance(InquiryMetricSignalReader::class, $fake);
        $service = $this->app->make(InquiryMetricsSyncService::class);

        $invalid = $service->sync('yby-irrigation', '502-024-8458', '2026-09-08', '2026-09-09', 'apply');
        $this->assertSame(['invalid_inquiry_signal_row'], $invalid['blocked']);
        $this->assertSame(0, TrafficInquiryDailyMetric::count());

        $fake = FakeInquiryMetricSignalReader::validatedFixture();
        $fake->rows[] = $fake->rows[0];
        $this->app->instance(InquiryMetricSignalReader::class, $fake);
        $duplicate = $this->app->make(InquiryMetricsSyncService::class)
            ->sync('yby-irrigation', '502-024-8458', '2026-09-08', '2026-09-09', 'apply');
        $this->assertSame(['duplicate_inquiry_signal_row'], $duplicate['blocked']);
        $this->assertSame(0, TrafficInquiryDailyMetric::count());
    }

    public function test_command_outputs_deterministic_json_and_defaults_to_dry_run(): void
    {
        $fake = FakeInquiryMetricSignalReader::validatedFixture();
        $this->app->instance(InquiryMetricSignalReader::class, $fake);
        $expected = json_encode([
            'mode' => 'dry_run', 'project_key' => 'yby-irrigation', 'external_account_id' => '502-024-8458',
            'date_from' => '2026-09-08', 'date_to' => '2026-09-09',
            'source_types_seen' => ['google_ads_form_lead', 'google_ads_whatsapp_lead'],
            'daily_rows_seen' => 4, 'inquiries_seen' => 6, 'qualified_inquiries_seen' => 0,
            'daily_rows_applied' => 0, 'qualification_state' => InquiryMetricsSyncService::QUALIFICATION_STATE,
            'blocked' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->artisan('traffic:inquiry-metrics:sync', [
            '--project' => 'yby-irrigation', '--account' => '502-024-8458',
            '--from' => '2026-09-08', '--to' => '2026-09-09',
        ])->expectsOutput($expected)->assertSuccessful();
        $this->assertSame(0, TrafficInquiryDailyMetric::count());
    }
}

final class FakeInquiryMetricSignalReader implements InquiryMetricSignalReader
{
    /** @var array<int, array<string, mixed>> */
    public array $rows;
    /** @var array<int, array{0:string,1:string,2:string}> */
    public array $calls = [];

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(array $rows) { $this->rows = $rows; }

    public static function validatedFixture(): self
    {
        return new self([
            ['metric_date' => '2026-09-08', 'source_type' => 'google_ads_form_lead', 'inquiries' => 3],
            ['metric_date' => '2026-09-08', 'source_type' => 'google_ads_whatsapp_lead', 'inquiries' => 0],
            ['metric_date' => '2026-09-09', 'source_type' => 'google_ads_form_lead', 'inquiries' => 3],
            ['metric_date' => '2026-09-09', 'source_type' => 'google_ads_whatsapp_lead', 'inquiries' => 0],
        ]);
    }

    public function fetchDailySignals(string $externalAccountId, string $dateFrom, string $dateTo): iterable
    {
        $this->calls[] = [$externalAccountId, $dateFrom, $dateTo];
        return $this->rows;
    }
}
