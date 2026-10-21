<?php

namespace Tests\Feature;

use JsonException;
use Tests\TestCase;

class ApprovedMenuBaselineTest extends TestCase
{
    private const MISMATCH = 'APPROVED_MENU_BASELINE_MISMATCH';

    private const REQUIRED_FIELDS = [
        'code', 'parent_code', 'level', 'label_zh', 'slug', 'url', 'route', 'permission',
        'emoji', 'function_zh', 'status', 'progress', 'sort',
    ];

    private const STATUSES = ['planned', 'in_progress', 'blocked', 'implemented', 'accepted', 'deprecated'];

    /** @throws JsonException */
    public function test_approved_menu_file_exists_and_has_frozen_metadata(): void
    {
        $path = base_path('menu/APPROVED_MENU.json');
        $this->assertFileExists($path, self::MISMATCH.': approved file is missing');
        $baseline = $this->baseline();

        $this->assertSame('2.1', $baseline['version'] ?? null, self::MISMATCH.': version');
        $this->assertSame('OWNER_APPROVED', $baseline['status'] ?? null, self::MISMATCH.': status');
        $this->assertSame('2026-09-05', $baseline['approved_at'] ?? null, self::MISMATCH.': approved_at');
        $this->assertSame('9443d91410968288e54c587bc85f226b8b772cad', $baseline['approved_head'] ?? null, self::MISMATCH.': approved_head');
        $this->assertSame([1, 2], $baseline['governed_levels'] ?? null, self::MISMATCH.': governed_levels');
        $this->assertIsArray($baseline['items'] ?? null, self::MISMATCH.': items');
    }

    /** @throws JsonException */
    public function test_every_approved_item_satisfies_the_level_one_and_two_schema_contract(): void
    {
        $items = $this->baseline()['items'];
        $codes = array_column($items, 'code');
        $levelOneCodes = array_column(array_filter($items, fn (array $item) => $item['level'] === 1), 'code');

        $this->assertSameSize(array_unique($codes), $codes, self::MISMATCH.': duplicate code');
        $this->assertUrlSharingIsLimitedToApprovedParentChildLandingPairs($items);

        foreach ($items as $item) {
            $this->assertSame(self::REQUIRED_FIELDS, array_keys($item), self::MISMATCH.': fields for '.($item['code'] ?? 'unknown'));
            $this->assertContains($item['level'], [1, 2], self::MISMATCH.': invalid level for '.$item['code']);
            $this->assertNotSame('', trim($item['label_zh']), self::MISMATCH.': empty label for '.$item['code']);
            $this->assertIsString($item['slug'], self::MISMATCH.': slug type for '.$item['code']);
            $this->assertNotSame('', $item['slug'], self::MISMATCH.': empty slug for '.$item['code']);
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $item['slug'], self::MISMATCH.': slug format for '.$item['code']);
            $this->assertFalse(str_starts_with($item['slug'], '/'), self::MISMATCH.': slug begins with slash for '.$item['code']);
            $this->assertStringNotContainsString('/', $item['slug'], self::MISMATCH.': slug contains slash for '.$item['code']);
            $this->assertStringNotContainsString(' ', $item['slug'], self::MISMATCH.': slug contains spaces for '.$item['code']);
            $this->assertSame(basename(trim($item['url'], '/')), $item['slug'], self::MISMATCH.': slug does not match URL for '.$item['code']);
            $this->assertNotSame('', trim($item['function_zh']), self::MISMATCH.': empty function for '.$item['code']);
            $this->assertContains($item['status'], self::STATUSES, self::MISMATCH.': invalid status for '.$item['code']);
            $this->assertIsInt($item['progress'], self::MISMATCH.': progress type for '.$item['code']);
            $this->assertGreaterThanOrEqual(0, $item['progress'], self::MISMATCH.': progress minimum for '.$item['code']);
            $this->assertLessThanOrEqual(100, $item['progress'], self::MISMATCH.': progress maximum for '.$item['code']);

            if ($item['level'] === 1) {
                $this->assertNull($item['parent_code'], self::MISMATCH.': level-1 parent for '.$item['code']);
            } else {
                $this->assertContains($item['parent_code'], $levelOneCodes, self::MISMATCH.': missing parent for '.$item['code']);
            }
        }

        foreach (collect($items)->groupBy(fn (array $item) => $item['parent_code'] ?? '__root__') as $siblings) {
            $sorts = $siblings->pluck('sort')->all();
            $this->assertSameSize(array_unique($sorts), $sorts, self::MISMATCH.': duplicate sibling sort');
        }
    }

    /** @throws JsonException */
    public function test_current_approval_contains_exactly_the_thirteen_owner_approved_level_one_items(): void
    {
        $items = $this->baseline()['items'];
        $levelOne = collect($items)->where('level', 1)->values();
        $levelTwo = collect($items)->where('level', 2)->values();

        $this->assertCount(13, $levelOne, self::MISMATCH.': level-1 count');
        $this->assertCount(64, $levelTwo, self::MISMATCH.': owner-approved level-2 count after retiring the obsolete Logistics pending workspace');
        $this->assertSame(['settings-backup'], $levelTwo->where('code', 'settings-backup')->pluck('code')->all(), self::MISMATCH.': backup child approval');
        $this->assertSame(['settings-fx'], $levelTwo->where('code', 'settings-fx')->pluck('code')->all(), self::MISMATCH.': FX child approval');
        $this->assertSame(
            ['dashboard', 'orders', 'logistics', 'sync', 'customers', 'marketing', 'kol', 'inventory', 'procurement', 'products', 'guides', 'accounting', 'settings'],
            $levelOne->pluck('code')->all(),
            self::MISMATCH.': level-1 order',
        );
        $this->assertSame(
            ['📊', '📋', '🚚', '🔄', '👥', '📣', '⭐', '📦', '🛒', '🏷️', '📖', '🧾', '⚙️'],
            $levelOne->pluck('emoji')->all(),
            self::MISMATCH.': Emoji approval',
        );
        $this->assertSame(
            ['accounting-overview', 'accounting-order-costs'],
            $levelTwo->where('parent_code', 'accounting')->sortBy('sort')->pluck('code')->values()->all(),
            self::MISMATCH.': Accounting A8 level-2 closure',
        );
    }

    /** @throws JsonException */
    public function test_customer_navigation_closure_has_only_stable_workspaces_and_no_ghost_my_orders_route(): void
    {
        $customerItems = collect($this->baseline()['items'])
            ->where('parent_code', 'customers')
            ->sortBy('sort')
            ->values();

        $this->assertSame(
            ['customers-library', 'customers-groups', 'customers-companies', 'customers-orders', 'customers-import'],
            $customerItems->pluck('code')->all(),
            self::MISMATCH.': Customer level-2 workspace closure',
        );
        $this->assertFalse(app('router')->has('customers.orders.mine'), self::MISMATCH.': stale customers.orders.mine route must not exist');
    }

    /** @throws JsonException */
    public function test_runtime_level_one_and_two_menu_matches_the_owner_approved_baseline(): void
    {
        $approved = array_map(fn (array $item) => $this->comparableApprovedItem($item), $this->baseline()['items']);
        $runtime = $this->flattenRuntime(config('erp_shell_navigation'));

        $this->assertSame($approved, $runtime, self::MISMATCH.': runtime menu differs from Owner approval');
    }

    private function assertUrlSharingIsLimitedToApprovedParentChildLandingPairs(array $items): void
    {
        foreach (collect($items)->groupBy('url') as $url => $group) {
            if ($group->count() === 1) {
                continue;
            }

            $approvedShortcutAliases = [
                '/customers/orders' => ['customers-orders', 'orders-customers'],
                '/kol/orders' => ['kol-orders', 'orders-kol'],
            ];

            if (isset($approvedShortcutAliases[$url])) {
                $this->assertSame(
                    $approvedShortcutAliases[$url],
                    $group->pluck('code')->sort()->values()->all(),
                    self::MISMATCH.': unapproved cross-module shortcut aliases for '.$url,
                );

                continue;
            }

            $this->assertCount(2, $group, self::MISMATCH.': URL shared by more than an approved pair for '.$url);
            $this->assertSame([1, 2], $group->pluck('level')->sort()->values()->all(), self::MISMATCH.': shared URL must be a level-1/level-2 pair for '.$url);

            $parent = $group->firstWhere('level', 1);
            $child = $group->firstWhere('level', 2);
            $this->assertSame($parent['code'], $child['parent_code'], self::MISMATCH.': shared URL child must belong to the sharing parent for '.$url);
        }
    }

    /** @throws JsonException */
    private function baseline(): array
    {
        $path = base_path('menu/APPROVED_MENU.json');
        if (! is_file($path)) {
            $this->fail(self::MISMATCH.': approved file is missing');
        }

        try {
            return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail(self::MISMATCH.': invalid JSON: '.$exception->getMessage());
        }
    }

    private function comparableApprovedItem(array $item): array
    {
        return [
            'code' => $item['code'],
            'parent_code' => $item['parent_code'],
            'level' => $item['level'],
            'label_zh' => $item['label_zh'],
            'slug' => $item['slug'],
            'url' => $item['url'],
            'route' => $item['route'],
            'permission' => $item['permission'],
            'emoji' => $item['emoji'],
            'status' => $item['status'],
            'progress' => $item['progress'],
            'sort' => $item['sort'],
        ];
    }

    private function flattenRuntime(array $items, ?string $parentCode = null, int $level = 1): array
    {
        if ($level > 2) {
            return [];
        }

        $flattened = [];
        foreach (array_values($items) as $index => $item) {
            $flattened[] = [
                'code' => $item['key'],
                'parent_code' => $parentCode,
                'level' => $level,
                'label_zh' => $item['label'],
                'slug' => basename(trim($item['path'], '/')),
                'url' => $item['path'],
                'route' => $item['route'],
                'permission' => $item['permission'],
                'emoji' => $item['emoji'] ?? null,
                'status' => $item['status'] ?? null,
                'progress' => $item['progress'] ?? null,
                'sort' => ($index + 1) * 10,
            ];

            if ($level < 2) {
                $flattened = [
                    ...$flattened,
                    ...$this->flattenRuntime($item['children'] ?? [], $item['key'], $level + 1),
                ];
            }
        }

        return $flattened;
    }
}
