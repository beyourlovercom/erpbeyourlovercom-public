<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class MigrationIndexNameTest extends TestCase
{
    public function test_inventory_lot_composite_index_uses_mysql_safe_explicit_name(): void
    {
        $indexName = 'wh_inv_lots_wh_product_status_idx';
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_08_25_000200_create_inventory_batch_sync_tables.php');

        self::assertLessThanOrEqual(64, strlen($indexName));
        self::assertStringContainsString(
            "\$table->index(['warehouse_id', 'product_id', 'stock_status'], '{$indexName}');",
            $migration,
        );
    }

    public function test_product_packaging_profile_media_uses_mysql_safe_explicit_fk_and_index_names(): void
    {
        $fkName    = 'ppm_media_ppp_id_foreign';
        $indexName = 'ppm_media_ppp_id_kind_idx';
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_09_04_090000_create_product_packaging_profile_media_table.php');

        self::assertLessThanOrEqual(64, strlen($fkName));
        self::assertLessThanOrEqual(64, strlen($indexName));
        self::assertStringContainsString("'".  $fkName  ."'", $migration);
        self::assertStringContainsString("'". $indexName ."'", $migration);

        // Auto-generated names must not appear — they exceed 64 chars.
        self::assertStringNotContainsString(
            'product_packaging_profile_media_product_packaging_profile_id_foreign',
            $migration,
        );
        self::assertStringNotContainsString(
            'product_packaging_profile_media_product_packaging_profile_id_kind_index',
            $migration,
        );
    }
}
