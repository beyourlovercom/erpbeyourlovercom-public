<?php

namespace App\Modules\Guides\Experience\Controllers;

use App\Modules\Guides\Application\MarkdownContentRenderer;
use App\Modules\Orders\Application\OrderIdentityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

final class ReleaseNoteController
{
    public function __construct(
        private readonly MarkdownContentRenderer $renderer,
        private readonly OrderIdentityService $identities,
    ) {}

    public function index(Request $request): Response
    {
        $entries = $this->changelogEntries();
        $requestedDate = $request->query('date');
        $initialDate = is_string($requestedDate)
            && collect($entries)->contains(fn (array $entry): bool => $entry['date'] === $requestedDate)
                ? $requestedDate
                : ($entries[0]['date'] ?? null);

        return Inertia::render('Guides/Releases/Index', [
            'entries' => $entries,
            'initialDate' => $initialDate,
        ]);
    }

    public function settings(): Response
    {
        $entries = $this->changelogEntries();
        $summaries = array_map(fn (array $entry): array => [
            'id' => (int) str_replace('-', '', $entry['date']),
            'version' => $entry['date'],
            'title' => 'ERP 开发记录',
            'summary' => null,
            'released_on' => $entry['date'],
        ], array_slice($entries, 0, 3));

        return Inertia::render('Settings/Index', [
            'currentRelease' => $summaries[0] ?? null,
            'recentReleases' => $summaries,
            'orderIdentity' => [
                'prefix_token' => $this->identities->prefix(),
                'example' => $this->identities->prefix().'-0901-c3721',
            ],
        ]);
    }

    public function updateOrderIdentity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'prefix_token' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        $this->identities->updatePrefix($data['prefix_token'], $request->user());

        return back()->with('success', '订单编号前缀已保存');
    }

    /** @return list<array{date:string,html:string}> */
    private function changelogEntries(): array
    {
        $path = resource_path('content/changelog/erp-changelog.md');
        if (! File::exists($path)) {
            return [];
        }

        preg_match_all(
            '/^##[ \t]+(\d{4}-\d{2}-\d{2})[ \t]*\r?\n(.*?)(?=^##[ \t]+\d{4}-\d{2}-\d{2}[ \t]*\r?$|\z)/ms',
            File::get($path),
            $matches,
            PREG_SET_ORDER,
        );

        $entries = array_map(function (array $match): array {
            $rendered = $this->renderer->render(trim($match[2]));

            return ['date' => $match[1], 'html' => $rendered['html']];
        }, $matches);

        usort($entries, fn (array $left, array $right): int => strcmp($right['date'], $left['date']));

        return $entries;
    }
}
