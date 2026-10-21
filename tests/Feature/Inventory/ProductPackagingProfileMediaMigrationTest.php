<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProductPackagingProfileMediaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const FK  = 'ppm_media_ppp_id_foreign';
    private const IDX = 'ppm_media_ppp_id_kind_idx';

    // -------------------------------------------------------------------------
    // Fresh-install path (table created by RefreshDatabase via normal migration)
    // -------------------------------------------------------------------------

    public function test_fresh_install_creates_table_with_all_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('product_packaging_profile_media'));

        foreach (['id', 'product_packaging_profile_id', 'kind', 'disk', 'path',
            'original_name', 'mime_type', 'size_bytes', 'sort_order', 'created_at', 'updated_at'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('product_packaging_profile_media', $col),
                "Column {$col} missing after fresh migration",
            );
        }
    }

    public function test_fresh_install_registers_fk_to_product_packaging_profiles(): void
    {
        $this->assertTrue(
            $this->hasForeignKeyToProfiles(),
            'Expected FK to product_packaging_profiles not found after fresh migration',
        );
    }

    public function test_fresh_install_has_explicit_short_composite_index(): void
    {
        $this->assertContains(
            self::IDX,
            Schema::getIndexListing('product_packaging_profile_media'),
            'Expected composite index '.self::IDX.' not found after fresh migration',
        );
        $this->assertLessThanOrEqual(64, strlen(self::IDX));
    }

    // -------------------------------------------------------------------------
    // Partial Production state repair
    // -------------------------------------------------------------------------

    public function test_up_repairs_partial_state_table_exists_with_columns_and_primary_only(): void
    {
        // Simulate Production partial state: table created with all columns + PRIMARY only,
        // no FK, no composite index (exactly what MySQL left after rejecting the long FK name).
        Schema::dropIfExists('product_packaging_profile_media');

        Schema::create('product_packaging_profile_media', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('product_packaging_profile_id');
            $table->string('kind', 24);
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            // Intentionally NO ->foreign() and NO ->index(['...', 'kind'])
        });

        $this->assertFalse($this->hasForeignKeyToProfiles(), 'Precondition: FK must be absent before repair');
        $this->assertFalse($this->hasCompositeIndex(), 'Precondition: composite index must be absent before repair');

        $migration = require base_path('database/migrations/2026_09_04_090000_create_product_packaging_profile_media_table.php');
        $migration->up();

        $this->assertTrue($this->hasForeignKeyToProfiles(), 'FK was not added during partial-state repair');
        $this->assertTrue($this->hasCompositeIndex(), 'Composite index was not added during partial-state repair');
    }

    public function test_up_is_idempotent_when_constraints_already_present(): void
    {
        // Table is fully set up by RefreshDatabase — rerunning up() must not throw or duplicate.
        $indexesBefore = Schema::getIndexListing('product_packaging_profile_media');

        $migration = require base_path('database/migrations/2026_09_04_090000_create_product_packaging_profile_media_table.php');
        $migration->up();

        $this->assertTrue($this->hasForeignKeyToProfiles());
        $this->assertTrue($this->hasCompositeIndex());
        // Index count must not have grown (no duplicate added).
        $this->assertCount(count($indexesBefore), Schema::getIndexListing('product_packaging_profile_media'));
    }

    // -------------------------------------------------------------------------
    // Incompatible schema rejection
    // -------------------------------------------------------------------------

    public function test_up_throws_for_incompatible_partial_schema_missing_columns(): void
    {
        Schema::dropIfExists('product_packaging_profile_media');

        // Table exists but is missing required columns — incompatible schema must be rejected.
        Schema::create('product_packaging_profile_media', function ($table): void {
            $table->id();
            // All domain columns intentionally absent.
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/incompatible schema/');

        $migration = require base_path('database/migrations/2026_09_04_090000_create_product_packaging_profile_media_table.php');
        $migration->up();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function hasForeignKeyToProfiles(): bool
    {
        foreach (Schema::getForeignKeys('product_packaging_profile_media') as $fk) {
            if ($fk['foreign_table'] === 'product_packaging_profiles'
                && in_array('product_packaging_profile_id', $fk['columns'], true)) {
                return true;
            }
        }

        return false;
    }

    private function hasCompositeIndex(): bool
    {
        return in_array(self::IDX, Schema::getIndexListing('product_packaging_profile_media'), true);
    }
}
