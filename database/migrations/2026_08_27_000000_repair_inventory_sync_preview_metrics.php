<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('inventory_sync_batches')) {
            return;
        }

        $missing = array_values(array_filter(
            ['preview_duplicate_skus', 'preview_warning_rows', 'preview_zeroed_skus'],
            fn (string $column): bool => ! Schema::hasColumn('inventory_sync_batches', $column),
        ));

        if ($missing === []) {
            return;
        }

        Schema::table('inventory_sync_batches', function (Blueprint $table) use ($missing): void {
            foreach ($missing as $column) {
                $table->unsignedInteger($column)->default(0);
            }
        });
    }

    public function down(): void
    {
        // Compatibility bridge only: these columns are canonical in the create-table migration.
    }
};
