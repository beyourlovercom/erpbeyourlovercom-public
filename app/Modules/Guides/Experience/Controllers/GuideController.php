<?php

namespace App\Modules\Guides\Experience\Controllers;

use App\Modules\Guides\Application\KnowledgeBaseService;
use App\Modules\Guides\Application\MarkdownContentRenderer;
use App\Modules\Guides\Infrastructure\Models\GuideArticle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class GuideController
{
    public function __construct(private readonly KnowledgeBaseService $service, private readonly MarkdownContentRenderer $renderer) {}

    public function index(Request $request): Response
    {
        return $this->listing($request, null, '全部教程');
    }

    public function videos(Request $request): Response
    {
        return $this->listing($request, 'video', '视频教程');
    }

    public function manuals(Request $request): Response
    {
        return $this->listing($request, 'manual', '操作手册');
    }

    public function show(Request $request, GuideArticle $guide): Response
    {
        $canManage = $request->user()?->can('guides.manage') ?? false;
        abort_if($guide->status !== 'published' && ! $canManage, 404);
        $rendered = $this->renderer->render($guide->markdown_body);

        return Inertia::render('Guides/Show', [
            'guide' => $this->payload($guide),
            'html' => $rendered['html'],
            'toc' => $rendered['toc'],
            'canManage' => $canManage,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Guides/Edit', ['guide' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $guide = $this->service->createGuide($request->validate($this->rules()), $request->user());
        return redirect()->route('guides.edit', $guide)->with('success', '教程草稿已创建');
    }

    public function edit(GuideArticle $guide): Response
    {
        return Inertia::render('Guides/Edit', ['guide' => $this->payload($guide)]);
    }

    public function update(Request $request, GuideArticle $guide): RedirectResponse
    {
        $this->service->updateGuide($guide, $request->validate($this->rules()), $request->user());
        return back()->with('success', '教程已保存');
    }

    public function publish(Request $request, GuideArticle $guide): RedirectResponse
    {
        $this->service->setGuidePublished($guide, true, $request->user());
        return back()->with('success', '教程已发布');
    }

    public function unpublish(Request $request, GuideArticle $guide): RedirectResponse
    {
        $this->service->setGuidePublished($guide, false, $request->user());
        return back()->with('success', '教程已转为草稿');
    }

    private function listing(Request $request, ?string $type, string $title): Response
    {
        $canManage = $request->user()?->can('guides.manage') ?? false;
        $query = GuideArticle::query()->when($type, fn ($q, $value) => $q->where('content_type', $value));
        if (! $canManage) {
            $query->published();
        }
        $guides = $query->orderByRaw("CASE WHEN status = 'published' THEN 0 ELSE 1 END")->orderByDesc('published_at')->orderBy('title')->get()->map(fn (GuideArticle $guide) => $this->payload($guide))->all();

        return Inertia::render('Guides/Index', ['title' => $title, 'contentType' => $type, 'guides' => $guides, 'canManage' => $canManage]);
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:160'],
            'content_type' => ['required', Rule::in(['tutorial', 'video', 'manual'])],
            'summary' => ['nullable', 'string', 'max:2000'],
            'markdown_body' => ['required', 'string', 'max:500000'],
            'video_url' => ['nullable', 'url', 'max:1000'],
        ];
    }

    private function payload(GuideArticle $guide): array
    {
        return [
            'id' => $guide->id, 'slug' => $guide->slug, 'content_type' => $guide->content_type,
            'title' => $guide->title, 'summary' => $guide->summary, 'markdown_body' => $guide->markdown_body,
            'video_url' => $guide->video_url, 'status' => $guide->status,
            'published_at' => $guide->published_at?->toIso8601String(), 'updated_at' => $guide->updated_at?->toIso8601String(),
        ];
    }
}
