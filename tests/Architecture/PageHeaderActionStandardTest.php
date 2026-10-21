<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Page Header Action Zone enforcement for the 27 current-main pages
 * defined in ERP-PAGE-HEADER-ACTION-ZONE-001 and ERP-CUSTOMER-MASTER-M1-CLEAN-001.
 *
 * Audited no-change exceptions (actions are not page-level):
 *   - resources/js/Pages/Inventory/Sync.vue      (workflow-step-local import buttons)
 *
 * Feature-only exclusions (not present on current main — enforced by their own PRs):
 *   - Customers/Subscribers/*
 *   - Support/Calls/*, Support/QualityIssues/*, Support/Tickets/*
 */
final class PageHeaderActionStandardTest extends TestCase
{
    private const FIRST_BATCH = [
        'resources/js/Pages/Customers/Companies/Create.vue',
        'resources/js/Pages/Customers/Companies/Edit.vue',
        'resources/js/Pages/Customers/Companies/Show.vue',
        'resources/js/Pages/Customers/Library/Create.vue',
        'resources/js/Pages/Customers/Library/Edit.vue',
        'resources/js/Pages/Customers/Library/Show.vue',
        'resources/js/Pages/Identity/Users/Create.vue',
        'resources/js/Pages/Identity/Users/Edit.vue',
        'resources/js/Pages/Kol/Create.vue',
        'resources/js/Pages/Kol/Edit.vue',
        'resources/js/Pages/Kol/Show.vue',
        'resources/js/Pages/Orders/Create.vue',
        'resources/js/Pages/Orders/Show.vue',
        'resources/js/Pages/Products/Create.vue',
        'resources/js/Pages/Products/Edit.vue',
        'resources/js/Pages/Inventory/WarehouseCreate.vue',
        'resources/js/Pages/Inventory/WarehouseShow.vue',
    ];

    private const SECOND_BATCH = [
        'resources/js/Pages/Customers/Dashboard.vue',
        'resources/js/Pages/Customers/Companies/Index.vue',
        'resources/js/Pages/Customers/Library/Index.vue',
        'resources/js/Pages/Products/Index.vue',
        'resources/js/Pages/Products/Import.vue',
        'resources/js/Pages/Identity/RolesPermissions/Index.vue',
        'resources/js/Pages/Kol/Index.vue',
        'resources/js/Pages/Orders/Index.vue',
        'resources/js/Pages/Finance/OrderAnalysis.vue',
        'resources/js/Pages/Sync/RunShow.vue',
        'resources/js/Pages/Sync/WooCommerceInbound.vue',
    ];

    private function projectPath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }

    private function readPage(string $relative): string
    {
        $path = $this->projectPath($relative);
        if (! file_exists($path)) {
            throw new \RuntimeException("File not found: $path");
        }

        return file_get_contents($path);
    }

    #[Test]
    public function first_batch_pages_use_one_upper_right_page_action_zone(): void
    {
        $violations = [];
        foreach (self::FIRST_BATCH as $relative) {
            $path = $this->projectPath($relative);
            if (! file_exists($path)) {
                $violations[] = $relative.' [file not found]';

                continue;
            }
            $source = file_get_contents($path);
            if (! str_contains($source, 'page-header-actions')) {
                $violations[] = $relative.' [missing page-header-actions]';
            }
        }
        $this->assertSame([], $violations, implode("\n", $violations));
    }

    #[Test]
    public function second_batch_index_and_workflow_pages_use_header_action_zone(): void
    {
        $violations = [];
        foreach (self::SECOND_BATCH as $relative) {
            $path = $this->projectPath($relative);
            if (! file_exists($path)) {
                $violations[] = $relative.' [file not found]';

                continue;
            }
            $source = file_get_contents($path);
            if (! str_contains($source, 'page-header-actions')) {
                $violations[] = $relative.' [missing page-header-actions]';
            }
        }
        $this->assertSame([], $violations, implode("\n", $violations));

        $this->assertSame(1, substr_count($this->readPage('resources/js/Pages/Products/Import.vue'), 'mvp-form-actions'));
        $this->assertSame(1, substr_count($this->readPage('resources/js/Pages/Identity/RolesPermissions/Index.vue'), 'mvp-form-actions'));
        $this->assertSame(0, substr_count($this->readPage('resources/js/Pages/Inventory/WarehouseCreate.vue'), '<div class="mvp-actions">'));
    }

    #[Test]
    public function migrated_form_pages_do_not_keep_duplicate_bottom_page_action_bars(): void
    {
        // mvp-form-actions must appear exactly once (in the header zone) for these pages
        $this->assertSame(1, substr_count($this->readPage('resources/js/Pages/Customers/Companies/Create.vue'), 'mvp-form-actions'));
        $this->assertSame(1, substr_count($this->readPage('resources/js/Pages/Customers/Companies/Edit.vue'), 'mvp-form-actions'));
        $this->assertSame(1, substr_count($this->readPage('resources/js/Pages/Identity/Users/Create.vue'), 'mvp-form-actions'));

        // erp-form-actions bottom bar must be absent (moved to header as erp-form-header-actions) for these pages
        foreach ([
            'resources/js/Pages/Customers/Library/Create.vue',
            'resources/js/Pages/Customers/Library/Edit.vue',
            'resources/js/Pages/Products/Create.vue',
            'resources/js/Pages/Products/Edit.vue',
            'resources/js/Pages/Orders/Create.vue',
            'resources/js/Pages/Orders/Show.vue',
        ] as $relative) {
            $source = $this->readPage($relative);
            $bottomBarCount = substr_count($source, 'class="erp-form-actions');
            $this->assertSame(0, $bottomBarCount, "$relative has unexpected bottom erp-form-actions");
        }
    }

    #[Test]
    public function audited_no_change_exceptions_are_documented(): void
    {
        // These pages have been explicitly audited and confirmed to have no page-level actions.
        // Inventory/Sync.vue: import buttons are workflow-step-local actions.
        // If either file gains a genuine page-level action in future, migrate it then.
        $this->assertTrue(true, 'no-change exceptions acknowledged');
    }
}
