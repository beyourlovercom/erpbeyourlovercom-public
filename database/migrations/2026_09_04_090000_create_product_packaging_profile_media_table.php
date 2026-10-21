<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Explicit short names required: auto-generated names exceed MySQL's 64-char limit.
    private const FK  = 'ppm_media_ppp_id_foreign';
    private const IDX = 'ppm_media_ppp_id_kind_idx';

    public function up(): void
    {
        if (! Schema::hasTable('product_packaging_profile_media')) {
            Schema::create('product_packaging_profile_media', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_packaging_profile_id')
                    ->constrained('product_packaging_profiles', indexName: self::FK)
                    ->cascadeOnDelete();
                $table->string('kind', 24);
                $table->string('disk', 32)->default('local');
                $table->string('path');
                $table->string('original_name')->nullable();
                $table->string('mime_type', 128);
                $table->unsignedBigInteger('size_bytes');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->index(['product_packaging_profile_id', 'kind'], self::IDX);
            });

            return;
        }

        // Table exists (partial Production state): verify compatible schema before adding constraints.
        $this->assertCompatiblePartialSchema();

        if (! $this->hasForeignKey()) {
            Schema::table('product_packaging_profile_media', function (Blueprint $table): void {
                $table->foreign('product_packaging_profile_id', self::FK)
                    ->references('id')->on('product_packaging_profiles')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->hasCompositeIndex()) {
            Schema::table('product_packaging_profile_media', function (Blueprint $table): void {
                $table->index(['product_packaging_profile_id', 'kind'], self::IDX);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_packaging_profile_media');
    }

    private function assertCompatiblePartialSchema(): void
    {
        $required = ['id', 'product_packaging_profile_id', 'kind', 'disk', 'path',
            'original_name', 'mime_type', 'size_bytes', 'sort_order', 'created_at', 'updated_at'];

        $actual  = Schema::getColumnListing('product_packaging_profile_media');
        $missing = array_diff($required, $actual);

        if ($missing !== []) {
            throw new \RuntimeException(
                'product_packaging_profile_media exists with an incompatible schema — missing columns: '
                .implode(', ', $missing).'. Manual intervention required.',
            );
        }
    }

    private function hasForeignKey(): bool
    {
        // FK constraint names are not portable (SQLite returns null); detect by target table + column.
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
};
