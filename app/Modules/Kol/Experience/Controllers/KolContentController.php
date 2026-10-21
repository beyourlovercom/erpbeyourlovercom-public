<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Kol\Infrastructure\Models\KolContent;
use App\Modules\Kol\Infrastructure\Models\KolContentAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class KolContentController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): Response
    {
        $query = KolContent::query()->with(['kol:id,display_name,owner_id', 'collaboration:id,kol_id,post_url,cooperation_type', 'attachments']);
        $this->applyOwnerScope($query, $request);

        if ($status = $request->string('publication_status')->trim()->value()) $query->where('publication_status', $status);
        if ($platform = $request->string('platform')->trim()->value()) $query->where('platform', $platform);
        if ($evidence = $request->string('evidence')->trim()->value()) $this->applyEvidenceFilter($query, $evidence);
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('post_url', 'like', "%{$search}%")
                    ->orWhereHas('kol', fn (Builder $k) => $k->where('display_name', 'like', "%{$search}%"));
            });
        }

        $contents = $query->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(50)->withQueryString()
            ->through(fn (KolContent $item) => $this->payload($item));

        return Inertia::render('Kol/Content', [
            'contents' => $contents->toArray(),
            'filters' => $request->only(['search', 'publication_status', 'platform', 'evidence']),
            'canOperate' => KolScope::canOperate($request->user()),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $query = Kol::query()->active()->with(['collaborations:id,kol_id,post_url,cooperation_type,status,planned_publish_at'])
            ->orderBy('display_name');
        if (! KolScope::isAllScope($request->user())) {
            $query->where('owner_id', (int) $request->user()->getAuthIdentifier());
        }
        if ($request->filled('kol_id')) {
            $query->whereKey((int) $request->input('kol_id'));
        } elseif ($search = $request->string('search')->trim()->value()) {
            $query->where('display_name', 'like', "%{$search}%");
        }

        return response()->json(['kols' => $query->limit(20)->get()->map(fn (Kol $kol) => [
            'id' => $kol->id,
            'display_name' => $kol->display_name,
            'collaborations' => $kol->collaborations->map(fn (KolCollaboration $c) => [
                'id' => $c->id,
                'primary_post_url' => $c->post_url,
                'cooperation_type' => $c->cooperation_type,
                'status' => $c->status,
                'planned_publish_at' => $c->planned_publish_at?->format('Y-m-d'),
            ])->values()->all(),
        ])->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $kol = Kol::findOrFail((int) $data['kol_id']);
        $this->authorizeKol($request, $kol);
        $this->validateCollaboration($kol, $data['collaboration_id'] ?? null);
        $evidence = $data['evidence'] ?? null;
        unset($data['evidence']);
        $this->assertPublishedEvidence($data, $evidence !== null, false);
        if (($data['publication_status'] ?? null) === 'published' && empty($data['published_at'])) {
            $data['published_at'] = now()->toDateString();
        }

        $content = DB::transaction(function () use ($data, $evidence, $request): KolContent {
            $item = KolContent::create($data);
            if ($evidence) $this->storeEvidence($item, $evidence, (int) $request->user()->getAuthIdentifier());
            return $item->load(['kol', 'collaboration', 'attachments']);
        });
        $this->record($request, 'kol.content.created', $content, null, $this->snapshot($content));

        return response()->json(['content' => $this->payload($content)], 201);
    }

    public function update(Request $request, KolContent $content): JsonResponse
    {
        if ((int) $request->input('kol_id') !== (int) $content->kol_id) {
            $this->unprocessable('kol_id', 'Content 创建后不可改到其他 KOL。');
        }
        $content->load(['kol', 'attachments']);
        $this->authorizeKol($request, $content->kol);
        $before = $this->snapshot($content);
        $data = $this->validated($request, $content);
        $this->validateCollaboration($content->kol, $data['collaboration_id'] ?? $content->collaboration_id);
        $evidence = $data['evidence'] ?? null;
        unset($data['evidence']);
        $merged = [...$content->only(['publication_status', 'post_url', 'published_at']), ...$data];
        $this->assertPublishedEvidence($merged, $evidence !== null, $content->attachments->isNotEmpty());
        if (($merged['publication_status'] ?? null) === 'published' && empty($merged['published_at'])) {
            $data['published_at'] = now()->toDateString();
        }

        DB::transaction(function () use ($content, $data, $evidence, $request): void {
            $content->update($data);
            if ($evidence) $this->storeEvidence($content, $evidence, (int) $request->user()->getAuthIdentifier());
        });
        $content = $content->fresh(['kol', 'collaboration', 'attachments']);
        $this->record($request, 'kol.content.updated', $content, $before, $this->snapshot($content));

        return response()->json(['content' => $this->payload($content)]);
    }

    public function addEvidence(Request $request, KolContent $content): JsonResponse
    {
        $content->load('kol');
        $this->authorizeKol($request, $content->kol);
        $validated = $request->validate(['evidence' => 'required|file|mimes:jpeg,jpg,png,webp,gif,pdf|max:10240']);
        $before = $this->snapshot($content->load('attachments'));
        $attachment = $this->storeEvidence($content, $validated['evidence'], (int) $request->user()->getAuthIdentifier());
        $content = $content->fresh(['kol', 'collaboration', 'attachments']);
        $this->record($request, 'kol.content.evidence_added', $content, $before, $this->snapshot($content));

        return response()->json(['content' => $this->payload($content), 'attachment_id' => $attachment->id], 201);
    }

    public function syncPostImage(Request $request, KolContent $content): JsonResponse
    {
        $content->load(['kol', 'attachments']);
        $this->authorizeKol($request, $content->kol);
        $postUrl = trim((string) $content->post_url);
        if ($postUrl === '') {
            $this->unprocessable('post_url', '请先填写 Content 发布 URL，再同步帖子图片。');
        }

        try {
            $html = $this->fetchAllowedRemote($postUrl, $content->platform, false);
            $imageUrl = $this->extractPostImageUrl($html['body'], $html['url']);
            if ($imageUrl === null) {
                throw new \RuntimeException('帖子页面没有可读取的预览图片。');
            }
            $image = $this->fetchAllowedRemote($imageUrl, $content->platform, true);
            $mime = strtolower(trim(explode(';', $image['content_type'])[0] ?? ''));
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            if (! isset($extensions[$mime])) {
                throw new \RuntimeException('抓取结果不是支持的图片格式。');
            }
            if ($image['body'] === '' || strlen($image['body']) > 10 * 1024 * 1024) {
                throw new \RuntimeException('帖子图片为空或超过 10MB。');
            }
        } catch (\RuntimeException $exception) {
            $this->unprocessable('sync', '无法自动抓取帖子图片：'.$exception->getMessage().' 请使用 Ctrl+V / ⌘V 或点击上传。');
        }

        $before = $this->snapshot($content);
        $attachment = DB::transaction(function () use ($content, $request, $image, $mime, $extensions): KolContentAttachment {
            foreach ($content->attachments->where('evidence_type', 'synced_post_image') as $old) {
                Storage::disk($old->disk)->delete($old->path);
                $old->delete();
            }
            $filename = 'synced-post-image-'.now()->format('Ymd-His').'.'.$extensions[$mime];
            $path = "kol/content-evidence/{$content->kol_id}/{$content->id}/".Str::uuid().'.'.$extensions[$mime];
            Storage::disk('local')->put($path, $image['body']);
            return $content->attachments()->create([
                'uploaded_by' => (int) $request->user()->getAuthIdentifier(),
                'disk' => 'local', 'path' => $path, 'original_name' => $filename,
                'mime_type' => $mime, 'size_bytes' => strlen($image['body']), 'evidence_type' => 'synced_post_image',
            ]);
        });
        $content = $content->fresh(['kol', 'collaboration', 'attachments']);
        $this->record($request, 'kol.content.evidence_synced', $content, $before, $this->snapshot($content));

        return response()->json(['content' => $this->payload($content), 'attachment_id' => $attachment->id]);
    }

    public function showEvidence(Request $request, KolContent $content, KolContentAttachment $attachment): StreamedResponse
    {
        $content->load('kol');
        $this->authorizeKol($request, $content->kol);
        abort_unless((int) $attachment->content_id === (int) $content->id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        $headers = $attachment->mime_type ? ['Content-Type' => $attachment->mime_type] : [];
        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, $headers, 'inline');
    }

    public function deleteEvidence(Request $request, KolContent $content, KolContentAttachment $attachment): JsonResponse
    {
        $content->load(['kol', 'attachments']);
        $this->authorizeKol($request, $content->kol);
        abort_unless((int) $attachment->content_id === (int) $content->id, 404);
        if ($content->publication_status === 'published' && trim((string) $content->post_url) === '' && $content->attachments->count() <= 1) {
            $this->unprocessable('evidence', '已发布内容必须至少保留 URL 或发布素材截图。');
        }

        $before = $this->snapshot($content);
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();
        $content = $content->fresh(['kol', 'collaboration', 'attachments']);
        $this->record($request, 'kol.content.evidence_deleted', $content, $before, $this->snapshot($content));

        return response()->json(['content' => $this->payload($content)]);
    }

    private function validated(Request $request, ?KolContent $content = null): array
    {
        $kolRule = ['required', 'integer', 'exists:kols,id'];

        return $request->validate([
            'kol_id' => $kolRule,
            'collaboration_id' => ['nullable', 'integer', 'exists:kol_collaborations,id'],
            'platform' => ['required', Rule::in(['instagram','tiktok','youtube','facebook','twitter','xiaohongshu','weibo','wechat','other'])],
            'content_type' => ['required', Rule::in(['post','reel','short_video','long_video','story','live','article','other'])],
            'publication_status' => ['required', Rule::in(['unpublished','published'])],
            'post_url' => ['nullable', 'url', 'max:2048'],
            'planned_publish_at' => ['nullable', 'date_format:Y-m-d'],
            'published_at' => ['nullable', 'date_format:Y-m-d'],
            'evidence' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp,gif,pdf', 'max:10240'],
        ]);
    }

    private function validateCollaboration(Kol $kol, mixed $collaborationId): void
    {
        if (! $collaborationId) return;
        $valid = KolCollaboration::query()->whereKey((int) $collaborationId)->where('kol_id', $kol->id)->exists();
        if (! $valid) $this->unprocessable('collaboration_id', '所选正式合作不属于该 KOL。');
    }

    private function assertPublishedEvidence(array $data, bool $newEvidence, bool $existingEvidence): void
    {
        if (($data['publication_status'] ?? null) !== 'published') return;
        if (trim((string) ($data['post_url'] ?? '')) !== '' || $newEvidence || $existingEvidence) return;
        $this->unprocessable('publication_status', '标记为已发布时，必须填写发布 URL 或上传发布素材截图。');
    }

    /** @return array{url:string,body:string,content_type:string} */
    private function fetchAllowedRemote(string $url, string $platform, bool $image): array
    {
        $current = $url;
        for ($hop = 0; $hop < 4; $hop++) {
            $this->assertAllowedRemoteUrl($current, $platform, $image);
            $response = Http::timeout(8)->connectTimeout(4)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 BYL-ERP-KOL-Content-Sync/1.0', 'Accept' => $image ? 'image/*' : 'text/html,application/xhtml+xml'])
                ->withOptions(['allow_redirects' => false])->get($current);
            if ($response->status() >= 300 && $response->status() < 400) {
                $location = trim((string) $response->header('Location'));
                if ($location === '') throw new \RuntimeException('远端返回无目标的重定向。');
                $current = $this->resolveRemoteUrl($current, $location);
                continue;
            }
            if (! $response->successful()) throw new \RuntimeException('远端返回 HTTP '.$response->status().'。');
            return ['url' => $current, 'body' => $response->body(), 'content_type' => (string) $response->header('Content-Type')];
        }
        throw new \RuntimeException('远端重定向次数过多。');
    }

    private function assertAllowedRemoteUrl(string $url, string $platform, bool $image): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') throw new \RuntimeException('URL 无效。');
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ! in_array($port, [80, 443], true)) throw new \RuntimeException('不允许使用非标准网络端口同步。');
        if (filter_var($host, FILTER_VALIDATE_IP)) throw new \RuntimeException('不允许使用 IP 地址同步。');
        $allowed = $image ? $this->allowedImageHosts($platform) : $this->allowedPostHosts($platform);
        if (! collect($allowed)->contains(fn (string $suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix))) {
            throw new \RuntimeException('该 URL 不属于当前平台允许同步的公开域名。');
        }
    }

    /** @return list<string> */
    private function allowedPostHosts(string $platform): array
    {
        return match ($platform) {
            'instagram' => ['instagram.com'], 'tiktok' => ['tiktok.com'],
            'youtube' => ['youtube.com', 'youtu.be'], 'facebook' => ['facebook.com', 'fb.com'],
            'twitter' => ['x.com', 'twitter.com'], 'xiaohongshu' => ['xiaohongshu.com', 'xhslink.com'],
            'weibo' => ['weibo.com'], 'wechat' => ['weixin.qq.com'], default => [],
        };
    }

    /** @return list<string> */
    private function allowedImageHosts(string $platform): array
    {
        return match ($platform) {
            'instagram' => ['instagram.com', 'cdninstagram.com', 'fbcdn.net'],
            'tiktok' => ['tiktok.com', 'tiktokcdn.com', 'byteoversea.com', 'ibytedtos.com'],
            'youtube' => ['youtube.com', 'youtu.be', 'ytimg.com', 'googleusercontent.com', 'ggpht.com'],
            'facebook' => ['facebook.com', 'fbcdn.net'], 'twitter' => ['x.com', 'twitter.com', 'twimg.com'],
            'xiaohongshu' => ['xiaohongshu.com', 'xhscdn.com', 'xhslink.com'],
            'weibo' => ['weibo.com', 'sinaimg.cn', 'sinaimg.com'], 'wechat' => ['weixin.qq.com', 'qpic.cn'], default => [],
        };
    }

    private function extractPostImageUrl(string $html, string $baseUrl): ?string
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $loaded = $dom->loadHTML($html); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (! $loaded) return null;
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            $key = strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));
            if (in_array($key, ['og:image', 'og:image:url', 'twitter:image', 'twitter:image:src'], true)) {
                $content = html_entity_decode(trim($meta->getAttribute('content')), ENT_QUOTES | ENT_HTML5);
                if ($content !== '') return $this->resolveRemoteUrl($baseUrl, $content);
            }
        }
        return null;
    }

    private function resolveRemoteUrl(string $baseUrl, string $target): string
    {
        if (preg_match('#^https?://#i', $target)) return $target;
        $base = parse_url($baseUrl);
        $scheme = (string) ($base['scheme'] ?? 'https'); $host = (string) ($base['host'] ?? '');
        if (str_starts_with($target, '//')) return $scheme.':'.$target;
        if (str_starts_with($target, '/')) return $scheme.'://'.$host.$target;
        $path = (string) ($base['path'] ?? '/'); $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
        return $scheme.'://'.$host.$dir.$target;
    }

    private function storeEvidence(KolContent $content, $file, int $userId): KolContentAttachment
    {
        $path = $file->store("kol/content-evidence/{$content->kol_id}/{$content->id}", 'local');
        return $content->attachments()->create([
            'uploaded_by' => $userId,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'evidence_type' => 'screenshot',
        ]);
    }

    private function authorizeKol(Request $request, Kol $kol): void
    {
        if (! KolScope::isAllScope($request->user()) && (int) $kol->owner_id !== (int) $request->user()->getAuthIdentifier()) abort(403);
    }

    private function applyOwnerScope(Builder $query, Request $request): void
    {
        if (! KolScope::isAllScope($request->user())) {
            $query->whereHas('kol', fn (Builder $q) => $q->where('owner_id', (int) $request->user()->getAuthIdentifier()));
        }
    }

    private function applyEvidenceFilter(Builder $query, string $evidence): void
    {
        match ($evidence) {
            'none' => $query->where(fn (Builder $q) => $q->whereNull('post_url')->orWhere('post_url', ''))->whereDoesntHave('attachments'),
            'url' => $query->whereNotNull('post_url')->where('post_url', '!=', '')->whereDoesntHave('attachments'),
            'screenshot' => $query->where(fn (Builder $q) => $q->whereNull('post_url')->orWhere('post_url', ''))->whereHas('attachments'),
            'url_and_screenshot' => $query->whereNotNull('post_url')->where('post_url', '!=', '')->whereHas('attachments'),
            default => null,
        };
    }

    private function payload(KolContent $item): array
    {
        return [
            'id' => $item->id,
            'kol_id' => $item->kol_id,
            'kol' => $item->kol ? ['id' => $item->kol->id, 'display_name' => $item->kol->display_name] : null,
            'collaboration_id' => $item->collaboration_id,
            'collaboration' => $item->collaboration ? [
                'id' => $item->collaboration->id,
                'primary_post_url' => $item->collaboration->post_url,
                'cooperation_type' => $item->collaboration->cooperation_type,
            ] : null,
            'platform' => $item->platform,
            'content_type' => $item->content_type,
            'publication_status' => $item->publication_status,
            'post_url' => $item->post_url,
            'planned_publish_at' => $item->planned_publish_at?->format('Y-m-d'),
            'published_at' => $item->published_at?->format('Y-m-d'),
            'evidence_classification' => $item->evidenceClassification(),
            'attachments' => $item->attachments->map(fn (KolContentAttachment $a) => [
                'id' => $a->id,
                'name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'url' => route('kol.contents.evidence.show', ['content' => $item->id, 'attachment' => $a->id], false),
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values()->all(),
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }

    private function snapshot(KolContent $item): array
    {
        if (! $item->relationLoaded('attachments')) $item->load('attachments');
        return [
            'kol_id' => $item->kol_id,
            'collaboration_id' => $item->collaboration_id,
            'platform' => $item->platform,
            'content_type' => $item->content_type,
            'publication_status' => $item->publication_status,
            'post_url' => $item->post_url,
            'planned_publish_at' => $item->planned_publish_at?->format('Y-m-d'),
            'published_at' => $item->published_at?->format('Y-m-d'),
            'evidence_classification' => $item->evidenceClassification(),
            'attachment_ids' => $item->attachments->pluck('id')->values()->all(),
        ];
    }

    private function unprocessable(string $field, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422));
    }

    private function record(Request $request, string $action, KolContent $item, ?array $before, ?array $after): void
    {
        $this->audit->record(
            AuditActor::user($request->user(), AuditSource::Web),
            $action,
            'kol_content',
            $item->id,
            $before,
            $after,
        );
    }
}
