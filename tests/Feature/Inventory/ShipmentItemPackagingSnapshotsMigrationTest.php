<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ShipmentItemPackagingSnapshotsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const FK_PROFILE     = 'sips_product_pkg_profile_id_fk';
    private const FK_MEASUREMENT = 'sips_pkg_measurement_record_id_fk';

    public function test_fk_identifiers_are_within_mysql_64_char_limit(): void
    {
        $this->assertLessThanOrEqual(64, strlen(self::FK_PROFILE),
            'product_packaging_profile_id FK name exceeds MySQL 64-char limit');
        $this->assertLessThanOrEqual(64, strlen(self::FK_MEASUREMENT),
            'packaging_measurement_record_id FK name exceeds MySQL 64-char limit');
    }

    public function test_fresh_migration_creates_table_with_all_columns(): void
    {
        $this->assertTrue(Schema::hasTable('shipment_item_packaging_snapshots'));

        foreach ([
            'id', 'shipment_id', 'order_item_id', 'product_id',
            'product_packaging_profile_id', 'packaging_measurement_record_id',
            'packaging_method_key', 'packaging_method_label',
            'packaging_length_cm', 'packaging_width_cm', 'packaging_height_cm',
            'packaged_weight_kg', 'measured_at', 'captured_at',
            'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('shipment_item_packaging_snapshots', $col),
                "Column {$col} missing after fresh migration",
            );
        }
    }

    public function test_fresh_migration_registers_fk_to_product_packaging_profiles(): void
    {
        $this->assertTrue(
            $this->hasForeignKeyTo('shipment_item_packaging_snapshots', 'product_packaging_profiles', 'product_packaging_profile_id'),
            'Expected FK to product_packaging_profiles not found',
        );
    }

    public function test_fresh_migration_registers_fk_to_packaging_measurement_records(): void
    {
        $this->assertTrue(
            $this->hasForeignKeyTo('shipment_item_packaging_snapshots', 'packaging_measurement_records', 'packaging_measurement_record_id'),
            'Expected FK to packaging_measurement_records not found',
        );
    }

    public function test_auto_generated_fk_names_would_exceed_mysql_limit(): void
    {
        // Regression guard: confirm the auto-generated names that caused the original bug
        // exceed 64 chars, proving explicit names are required.
        $this->assertGreaterThan(64, strlen('shipment_item_packaging_snapshots_product_packaging_profile_id_foreign'));
        $this->assertGreaterThan(64, strlen('shipment_item_packaging_snapshots_packaging_measurement_record_id_foreign'));
    }

    private function hasForeignKeyTo(string $table, string $foreignTable, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $fk) {
            if ($fk['foreign_table'] === $foreignTable
                && in_array($column, $fk['columns'], true)) {
                return true;
            }
        }

        return false;
    }
}
