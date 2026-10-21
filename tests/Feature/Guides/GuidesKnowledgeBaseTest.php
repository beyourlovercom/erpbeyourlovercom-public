<?php

namespace Tests\Feature\Guides;

use App\Modules\Guides\Infrastructure\Models\GuideArticle;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class GuidesKnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_sees_only_published_guides_and_h2_toc_is_generated(): void
    {
        $viewer = $this->userWith(['guides.view']);
        $published = GuideArticle::create([
            'slug' => 'shipping-guide', 'content_type' => 'tutorial', 'title' => 'Shipping Guide',
            'summary' => 'Published', 'markdown_body' => "# Shipping\n\n## First Step\nText\n\n## First Step\nMore\n<script>alert(1)</script>",
            'status' => 'published', 'published_at' => now(), 'created_by' => $viewer->id, 'updated_by' => $viewer->id,
        ]);
        GuideArticle::create([
            'slug' => 'draft-guide', 'content_type' => 'manual', 'title' => 'Draft Guide',
            'markdown_body' => '# Draft', 'status' => 'draft', 'created_by' => $viewer->id, 'updated_by' => $viewer->id,
        ]);

        $this->actingAs($viewer)->get('/guides')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Guides/Index')->has('guides', 1)->where('guides.0.slug', 'shipping-guide')->where('canManage', false));
        $this->actingAs($viewer)->get('/guides/draft-guide')->assertNotFound();
        $this->actingAs($viewer)->get('/guides/'.$published->slug)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Guides/Show')
            ->where('toc.0.id', 'first-step')->where('toc.0.label', 'First Step')
            ->where('toc.1.id', 'first-step-2')
            ->where('html', fn (string $html): bool => str_contains($html, '<h2 id="first-step">') && ! str_contains($html, '<script>')));
    }

    public function test_video_and_manual_routes_filter_canonical_guide_records(): void
    {
        $viewer = $this->userWith(['guides.view']);
        foreach ([['video-one','video'], ['manual-one','manual'], ['tutorial-one','tutorial']] as [$slug, $type]) {
            GuideArticle::create(['slug'=>$slug,'content_type'=>$type,'title'=>$slug,'markdown_body'=>'# Body','status'=>'published','published_at'=>now(),'created_by'=>$viewer->id,'updated_by'=>$viewer->id]);
        }
        $this->actingAs($viewer)->get('/guides/videos')->assertOk()->assertInertia(fn (Assert $p) => $p->has('guides', 1)->where('guides.0.content_type','video'));
        $this->actingAs($viewer)->get('/guides/manuals')->assertOk()->assertInertia(fn (Assert $p) => $p->has('guides', 1)->where('guides.0.content_type','manual'));
    }

    public function test_manager_creates_updates_and_publishes_markdown_without_a_second_content_truth(): void
    {
        $manager = $this->userWith(['guides.view','guides.manage']);
        $this->actingAs($manager)->post('/guides', [
            'title'=>'Inventory SOP','slug'=>'','content_type'=>'manual','summary'=>'SOP','markdown_body'=>"# Inventory\n\n## Count\nDo it.",'video_url'=>null,
        ])->assertRedirect();
        $guide = GuideArticle::where('title','Inventory SOP')->firstOrFail();
        $this->assertSame('draft', $guide->status);
        $this->assertSame("# Inventory\n\n## Count\nDo it.", $guide->markdown_body);
        $this->actingAs($manager)->patch('/guides/'.$guide->slug, [
            'title'=>'Inventory SOP V2','slug'=>$guide->slug,'content_type'=>'manual','summary'=>'Updated','markdown_body'=>"# Inventory\n\n## Count\nUpdated.",'video_url'=>null,
        ])->assertRedirect();
        $this->actingAs($manager)->post('/guides/'.$guide->slug.'/publish')->assertRedirect();
        $this->assertDatabaseHas('guide_articles',['id'=>$guide->id,'status'=>'published','title'=>'Inventory SOP V2']);
        $this->assertDatabaseHas('audit_records',['action'=>'guide.created']);
        $this->assertDatabaseHas('audit_records',['action'=>'guide.updated']);
        $this->assertDatabaseHas('audit_records',['action'=>'guide.published']);
    }

    public function test_view_only_user_cannot_open_or_mutate_editor_routes(): void
    {
        $viewer = $this->userWith(['guides.view']);
        $guide = GuideArticle::create(['slug'=>'locked','content_type'=>'tutorial','title'=>'Locked','markdown_body'=>'# Locked','status'=>'published','published_at'=>now(),'created_by'=>$viewer->id,'updated_by'=>$viewer->id]);
        $this->actingAs($viewer)->get('/guides/create')->assertForbidden();
        $this->actingAs($viewer)->get('/guides/'.$guide->slug.'/edit')->assertForbidden();
        $this->actingAs($viewer)->post('/guides/'.$guide->slug.'/unpublish')->assertForbidden();
    }

    public function test_changelog_is_read_only_and_comes_from_the_single_markdown_source(): void
    {
        $viewer = $this->userWith(['guides.view']);
        $manager = $this->userWith(['guides.view', 'guides.manage']);

        $this->actingAs($viewer)->get('/guides/changelog')->assertRedirect('/settings/changelog');
        $this->actingAs($viewer)->get('/settings/changelog?date=2026-09-07')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Guides/Releases/Index')
            ->where('initialDate', '2026-09-07')
            ->where('entries.0.date', '2026-09-08')
            ->where('entries.1.date', '2026-09-07')
            ->where('entries.0.html', fn (string $html): bool => str_contains($html, 'Accounting / Order Profit')));

        $this->actingAs($manager)->get('/settings/changelog/create')->assertNotFound();
        $this->actingAs($manager)->post('/settings/changelog', [])->assertStatus(405);
    }

    public function test_settings_release_summary_uses_the_same_markdown_changelog(): void
    {
        $settingsAdmin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($settingsAdmin)->get('/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Index')
            ->where('currentRelease.version', '2026-09-08')
            ->where('currentRelease.released_on', '2026-09-08')
            ->where('orderIdentity.prefix_token', '007erp')
            ->where('orderIdentity.example', '007erp-0901-c3721')
            ->has('recentReleases', 2));

        $this->actingAs($settingsAdmin)->put('/settings/order-identity', ['prefix_token' => ' 007 '])->assertRedirect();
        $this->assertDatabaseHas('order_identity_settings', ['id' => 1, 'prefix_token' => '007', 'updated_by' => $settingsAdmin->id]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active'=>true]);
        $role = Role::create(['key'=>'guide-test-'.uniqid(),'label'=>'Guide Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key'=>$key],['label'=>$key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);
        return $user;
    }
}
