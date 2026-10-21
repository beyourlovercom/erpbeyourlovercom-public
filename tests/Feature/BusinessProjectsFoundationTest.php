<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\BusinessProjects\Application\Contracts\BusinessProjectRepository;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProjectSite;
use App\Modules\BusinessProjects\Infrastructure\Repositories\EloquentBusinessProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BusinessProjectsFoundationTest extends TestCase
{
    use RefreshDatabase;

    private BusinessProjectService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BusinessProjectService(new EloquentBusinessProjectRepository());
    }

    // ── Contract binding ───────────────────────────────────────────────────────

    public function test_service_provider_binds_repository_contract(): void
    {
        $repo = $this->app->make(BusinessProjectRepository::class);
        $this->assertInstanceOf(EloquentBusinessProjectRepository::class, $repo);
    }

    // ── Domain normalization ───────────────────────────────────────────────────

    public function test_normalize_domain_strips_scheme_and_trailing_dot(): void
    {
        $this->assertSame('example.com', $this->service->normalizeDomain('https://example.com/path?q=1'));
        $this->assertSame('example.com', $this->service->normalizeDomain('http://example.com.'));
        $this->assertSame('example.com', $this->service->normalizeDomain('HTTPS://EXAMPLE.COM'));
    }

    public function test_normalize_domain_handles_bare_host(): void
    {
        $this->assertSame('example.com', $this->service->normalizeDomain('example.com'));
    }

    public function test_normalize_domain_lowercases_result(): void
    {
        $this->assertSame('sub.example.com', $this->service->normalizeDomain('https://Sub.EXAMPLE.COM/'));
    }

    // ── findByKey ─────────────────────────────────────────────────────────────

    public function test_find_by_key_returns_null_when_missing(): void
    {
        $this->assertNull($this->service->findByKey('nonexistent'));
    }

    public function test_find_by_key_returns_matching_project(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-001', 'name' => 'YBY', 'status' => 'active']);

        $found = $this->service->findByKey('yby-001');

        $this->assertNotNull($found);
        $this->assertSame($project->id, $found->id);
    }

    // ── listSites ─────────────────────────────────────────────────────────────

    public function test_list_sites_returns_only_active_sites_ordered_primary_first(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-002', 'name' => 'YBY2', 'status' => 'active']);

        BusinessProjectSite::create(['business_project_id' => $project->id, 'site_key' => 'archive', 'domain_normalized' => 'archive.com', 'base_url' => 'https://archive.com', 'status' => 'archived', 'is_primary' => false]);
        $secondary = BusinessProjectSite::create(['business_project_id' => $project->id, 'site_key' => 'secondary', 'domain_normalized' => 'secondary.com', 'base_url' => 'https://secondary.com', 'status' => 'active', 'is_primary' => false]);
        $primary = BusinessProjectSite::create(['business_project_id' => $project->id, 'site_key' => 'primary', 'domain_normalized' => 'primary.com', 'base_url' => 'https://primary.com', 'status' => 'active', 'is_primary' => true]);

        $sites = $this->service->listSites($project->id);

        $this->assertCount(2, $sites);
        $this->assertSame($primary->id, $sites->first()->id);
        $this->assertSame($secondary->id, $sites->last()->id);
    }

    public function test_list_sites_returns_empty_when_no_active_sites(): void
    {
        $project = BusinessProject::create(['project_key' => 'yby-003', 'name' => 'YBY3', 'status' => 'active']);
        BusinessProjectSite::create(['business_project_id' => $project->id, 'site_key' => 'old', 'domain_normalized' => 'old.com', 'base_url' => 'https://old.com', 'status' => 'archived', 'is_primary' => false]);

        $this->assertCount(0, $this->service->listSites($project->id));
    }
}
