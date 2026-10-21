<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Infrastructure\Models\TrafficCampaign;
use App\Modules\Traffic\Infrastructure\Models\TrafficDailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficInquiryDailyMetric;
use App\Modules\Traffic\Infrastructure\Models\TrafficSourceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TrafficBossDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_marketing_viewer_sees_project_level_boss_dashboard_without_fake_qualification(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-irrigation', 'name' => 'YBY Irrigation', 'status' => 'active']);
        $account = TrafficSourceAccount::create([
            'business_project_id' => $project->id,
            'provider' => 'google_ads',
            'external_account_id' => '5020248458',
            'name' => '亿碧源-灌溉',
            'currency_code' => 'CNY',
            'account_timezone' => 'Asia/Shanghai',
            'last_synced_at' => '2026-09-14 08:00:00',
        ]);
        $campaign = TrafficCampaign::create([
            'traffic_source_account_id' => $account->id,
            'external_campaign_id' => '24023703280',
            'name' => 'YBY-Search-IRR-SOLUTION-Tanzania',
            'status' => 'enabled',
        ]);
        TrafficDailyMetric::create(['traffic_campaign_id' => $campaign->id, 'metric_date' => '2026-09-12', 'impressions' => 300, 'clicks' => 50, 'cost_micros' => 400000000, 'provider_conversions' => 12]);
        TrafficDailyMetric::create(['traffic_campaign_id' => $campaign->id, 'metric_date' => '2026-09-13', 'impressions' => 402, 'clicks' => 59, 'cost_micros' => 480684249, 'provider_conversions' => 15]);
        TrafficInquiryDailyMetric::create(['business_project_id' => $project->id, 'metric_date' => '2026-09-12', 'source_type' => 'google_ads_form_lead', 'inquiries' => 11, 'qualified_inquiries' => 7]);
        TrafficInquiryDailyMetric::create(['business_project_id' => $project->id, 'metric_date' => '2026-09-13', 'source_type' => 'google_ads_form_lead', 'inquiries' => 15, 'qualified_inquiries' => 9]);
        TrafficInquiryDailyMetric::create(['business_project_id' => $project->id, 'metric_date' => '2026-09-13', 'source_type' => 'google_ads_whatsapp_lead', 'inquiries' => 1, 'qualified_inquiries' => 1]);

        $response = $this->actingAs($this->userWith(['marketing.view']))
            ->get('/marketing/traffic?date_from=2026-09-12&date_to=2026-09-13');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Traffic/BossDashboard')
            ->where('project.key', 'yby-irrigation')
            ->where('project.name', 'YBY Irrigation')
            ->where('summary.currency_code', 'CNY')
            ->where('summary.impressions', 702)
            ->where('summary.clicks', 109)
            ->where('summary.spend', '880.68')
            ->where('summary.inquiries', 27)
            ->where('summary.qualified_inquiries', null)
            ->where('summary.qualification_state', 'unavailable_until_case_truth')
            ->where('summary.cost_per_inquiry', '32.62')
            ->has('campaigns', 1)
            ->where('campaigns.0.name', 'YBY-Search-IRR-SOLUTION-Tanzania')
            ->has('sources', 2)
            ->where('data_health.automatic_sync_enabled', false)
            ->where('data_health.data_to', '2026-09-13'));
    }

    public function test_dashboard_requires_marketing_view_permission(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get('/marketing/traffic')->assertForbidden();
    }

    public function test_marketing_navigation_exposes_traffic_dashboard_child(): void
    {
        $user = $this->userWith(['marketing.view'], 'traffic-navigation-user');
        $navigation = app(\App\Modules\Shell\Application\ErpShellNavigation::class)->for($user);
        $marketing = collect($navigation)->firstWhere('key', 'marketing');
        $traffic = collect($marketing['children'] ?? [])->firstWhere('key', 'marketing-traffic');

        $this->assertSame('流量总览', $traffic['label']);
        $this->assertSame('/marketing/traffic', $traffic['path']);
        $this->assertSame('traffic.boss-dashboard', $traffic['route']);
    }

    public function test_dashboard_rejects_invalid_or_unbounded_date_ranges(): void
    {
        $user = $this->userWith(['marketing.view'], 'traffic-date-user');
        $this->actingAs($user)->get('/marketing/traffic?date_from=2026-09-20&date_to=2026-09-01')->assertStatus(422);
        $this->actingAs($user)->get('/marketing/traffic?date_from=2025-01-01&date_to=2026-09-01')->assertStatus(422);
    }

    private function userWith(array $permissions, string $roleKey = 'traffic-boss-viewer'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
