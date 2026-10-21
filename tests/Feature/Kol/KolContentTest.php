<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Kol\Infrastructure\Models\KolContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KolContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_content_requires_url_or_attachment_evidence(): void
    {
        [$user, $kol] = $this->fixture();
        $this->actingAs($user)->postJson('/kol/contents', $this->payload($kol, ['publication_status' => 'published']))
            ->assertStatus(422)->assertJsonValidationErrors('publication_status');
        $this->assertDatabaseCount('kol_contents', 0);
    }

    public function test_url_only_content_is_evidence_backed_and_auto_stamps_publish_time(): void
    {
        [$user, $kol] = $this->fixture();
        $response = $this->actingAs($user)->postJson('/kol/contents', $this->payload($kol, [
            'publication_status' => 'published',
            'post_url' => 'https://instagram.com/p/content-1',
        ]));
        $response->assertCreated()->assertJsonPath('content.evidence_classification', 'url');
        $this->assertNotNull($response->json('content.published_at'));
    }

    public function test_attachment_evidence_is_private_and_owner_scoped(): void
    {
        Storage::fake('local');
        [$owner, $kol] = $this->fixture(['kol.view', 'kol.operate']);
        [$other] = $this->fixture(['kol.view', 'kol.operate']);

        $response = $this->actingAs($owner)->post('/kol/contents', [
            ...$this->payload($kol, ['publication_status' => 'published']),
            'evidence' => UploadedFile::fake()->image('proof.png'),
        ], ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonPath('content.evidence_classification', 'screenshot');

        $contentId = (int) $response->json('content.id');
        $attachmentId = (int) $response->json('content.attachments.0.id');
        $this->assertDatabaseHas('kol_content_attachments', [
            'content_id' => $contentId,
            'disk' => 'local',
            'original_name' => 'proof.png',
        ]);
        $this->assertDatabaseHas('audit_records', [
            'action' => 'kol.content.created',
            'entity_type' => 'kol_content',
            'entity_id' => (string) $contentId,
        ]);
        $url = "/kol/contents/{$contentId}/evidence/{$attachmentId}";
        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($other)->get($url)->assertForbidden();
    }

    public function test_content_kol_is_immutable_and_collaboration_must_match_kol(): void
    {
        [$user, $kol] = $this->fixture();
        $otherKol = Kol::create(['display_name' => 'Other KOL', 'status' => 'active', 'relationship_stage' => 'cooperating', 'owner_id' => $user->id]);
        $foreignCollaboration = KolCollaboration::create(['kol_id' => $otherKol->id, 'owner_id' => $user->id, 'status' => 'confirmed', 'currency' => 'USD']);
        $content = KolContent::create([...$this->modelPayload($kol), 'post_url' => 'https://example.com/original']);

        $this->actingAs($user)->patchJson("/kol/contents/{$content->id}", $this->payload($otherKol, ['post_url' => 'https://example.com/moved']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['kol_id']]);
        $this->actingAs($user)->patchJson("/kol/contents/{$content->id}", $this->payload($kol, ['collaboration_id' => $foreignCollaboration->id, 'post_url' => 'https://example.com/original']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['collaboration_id']]);
        $this->assertSame($kol->id, $content->fresh()->kol_id);
    }

    public function test_content_index_and_options_respect_specialist_owner_scope(): void
    {
        [$ownerA, $kolA] = $this->fixture(['kol.view', 'kol.operate']);
        [$ownerB, $kolB] = $this->fixture(['kol.view', 'kol.operate']);
        KolContent::create([...$this->modelPayload($kolA), 'post_url' => 'https://example.com/a', 'publication_status' => 'published']);
        KolContent::create([...$this->modelPayload($kolB), 'post_url' => 'https://example.com/b', 'publication_status' => 'published']);

        $response = $this->actingAs($ownerA)->get('/kol/content');
        $response->assertOk();
        $props = $response->original->getData()['page']['props'];
        $this->assertCount(1, $props['contents']['data']);
        $this->assertSame($kolA->id, $props['contents']['data'][0]['kol_id']);

        $options = $this->actingAs($ownerA)->getJson('/kol/content/options')->assertOk()->json('kols');
        $this->assertCount(1, $options);
        $this->assertSame($kolA->id, $options[0]['id']);
    }

    public function test_library_combined_published_and_evidence_filters_match_same_content(): void
    {
        Storage::fake('local');
        [$admin, $mixedKol] = $this->fixture(['kol.view', 'kol.operate', 'kol.views.manage']);
        $matchingKol = Kol::create(['display_name' => 'Matching Publish', 'status' => 'active', 'relationship_stage' => 'cooperating', 'owner_id' => $admin->id]);

        $publishedWithScreenshot = KolContent::create([...$this->modelPayload($mixedKol), 'publication_status' => 'published']);
        $publishedWithScreenshot->attachments()->create([
            'uploaded_by' => $admin->id, 'disk' => 'local', 'path' => 'fake/proof.png', 'original_name' => 'proof.png', 'evidence_type' => 'screenshot',
        ]);
        KolContent::create([...$this->modelPayload($mixedKol), 'publication_status' => 'unpublished', 'post_url' => 'https://example.com/planned-url']);
        KolContent::create([...$this->modelPayload($matchingKol), 'publication_status' => 'published', 'post_url' => 'https://example.com/published-url']);

        $response = $this->actingAs($admin)->get('/kol/library?has_published_content=yes&publication_evidence=url');
        $response->assertOk();
        $rows = $response->original->getData()['page']['props']['kols']['data'];
        $this->assertTrue(collect($rows)->contains(fn ($row) => $row['id'] === $matchingKol->id));
        $matchingRow = collect($rows)->firstWhere('id', $matchingKol->id);
        $this->assertTrue((bool) $matchingRow['has_published_content']);
        $this->assertFalse(collect($rows)->contains(fn ($row) => $row['id'] === $mixedKol->id));
    }

    public function test_evidence_add_and_delete_mutations_are_audited(): void
    {
        Storage::fake('local');
        [$user, $kol] = $this->fixture();
        $content = KolContent::create([...$this->modelPayload($kol), 'post_url' => 'https://example.com/evidence-audit']);

        $add = $this->actingAs($user)->post("/kol/contents/{$content->id}/evidence", [
            'evidence' => UploadedFile::fake()->image('audit-proof.png'),
        ], ['Accept' => 'application/json']);
        $add->assertCreated()->assertJsonPath('content.evidence_classification', 'url_and_screenshot');
        $attachmentId = (int) $add->json('attachment_id');
        $this->assertDatabaseHas('audit_records', [
            'action' => 'kol.content.evidence_added',
            'entity_id' => (string) $content->id,
        ]);

        $this->actingAs($user)->deleteJson("/kol/contents/{$content->id}/evidence/{$attachmentId}")->assertOk();
        $this->assertDatabaseHas('audit_records', [
            'action' => 'kol.content.evidence_deleted',
            'entity_id' => (string) $content->id,
        ]);
    }

    public function test_sync_post_image_fetches_allowed_platform_preview_into_private_storage(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://www.instagram.com/p/content-sync/' => Http::response(
                '<html><head><meta property="og:image" content="https://scontent.cdninstagram.com/proof.jpg"></head></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://scontent.cdninstagram.com/proof.jpg' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        [$user, $kol] = $this->fixture();
        $content = KolContent::create([
            ...$this->modelPayload($kol),
            'publication_status' => 'published',
            'post_url' => 'https://www.instagram.com/p/content-sync/',
            'published_at' => '2026-08-28',
        ]);

        $response = $this->actingAs($user)->postJson("/kol/contents/{$content->id}/sync-post-image");
        $response->assertOk()->assertJsonPath('content.evidence_classification', 'url_and_screenshot');
        $this->assertDatabaseHas('kol_content_attachments', [
            'content_id' => $content->id, 'disk' => 'local', 'mime_type' => 'image/jpeg', 'evidence_type' => 'synced_post_image',
        ]);
        $this->assertDatabaseHas('audit_records', ['action' => 'kol.content.evidence_synced', 'entity_id' => (string) $content->id]);
    }

    public function test_sync_post_image_rejects_non_platform_or_private_url_before_http_request(): void
    {
        Storage::fake('local');
        Http::fake();
        [$user, $kol] = $this->fixture();
        $content = KolContent::create([
            ...$this->modelPayload($kol),
            'publication_status' => 'published',
            'post_url' => 'http://127.0.0.1/private-post',
            'published_at' => '2026-08-28',
        ]);

        $this->actingAs($user)->postJson("/kol/contents/{$content->id}/sync-post-image")
            ->assertStatus(422)->assertJsonValidationErrors('sync');
        Http::assertNothingSent();
    }

    public function test_content_dates_persist_and_project_as_date_only(): void
    {
        [$user, $kol] = $this->fixture();
        $response = $this->actingAs($user)->postJson('/kol/contents', $this->payload($kol, [
            'planned_publish_at' => '2026-08-29',
        ]));
        $response->assertCreated()->assertJsonPath('content.planned_publish_at', '2026-08-29');
        $stored = KolContent::query()->where('kol_id', $kol->id)->firstOrFail();
        $this->assertSame('2026-08-29', substr((string) $stored->getRawOriginal('planned_publish_at'), 0, 10));
    }

    public function test_last_attachment_cannot_be_removed_from_published_content_without_url(): void
    {
        Storage::fake('local');
        [$user, $kol] = $this->fixture();
        $content = KolContent::create([...$this->modelPayload($kol), 'publication_status' => 'published']);
        $attachment = $content->attachments()->create([
            'uploaded_by' => $user->id, 'disk' => 'local', 'path' => 'proof/only.png', 'original_name' => 'only.png', 'evidence_type' => 'screenshot',
        ]);
        Storage::disk('local')->put('proof/only.png', 'evidence');

        $this->actingAs($user)->deleteJson("/kol/contents/{$content->id}/evidence/{$attachment->id}")
            ->assertStatus(422)->assertJsonValidationErrors('evidence');
        $this->assertDatabaseHas('kol_content_attachments', ['id' => $attachment->id]);
    }

    private function fixture(array $permissions = ['kol.view', 'kol.operate', 'kol.views.manage']): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->grant($user, $permissions);
        $kol = Kol::create([
            'display_name' => 'Content Creator '.uniqid(),
            'status' => 'active',
            'relationship_stage' => 'cooperating',
            'owner_id' => $user->id,
        ]);
        return [$user, $kol];
    }

    private function payload(Kol $kol, array $overrides = []): array
    {
        return [...[
            'kol_id' => $kol->id,
            'collaboration_id' => null,
            'platform' => 'instagram',
            'content_type' => 'post',
            'publication_status' => 'unpublished',
            'post_url' => null,
            'planned_publish_at' => '2026-08-29',
            'published_at' => null,
        ], ...$overrides];
    }

    private function modelPayload(Kol $kol): array
    {
        return ['kol_id' => $kol->id, 'platform' => 'instagram', 'content_type' => 'post'];
    }

    private function grant(User $user, array $permissions): void
    {
        $role = Role::create(['key' => 'test_kol_content_'.uniqid(), 'label' => 'Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);
    }
}
