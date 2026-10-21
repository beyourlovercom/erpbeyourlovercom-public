<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 8);
            $table->string('original_filename', 255);
            $table->string('checksum', 64);
            $table->unsignedInteger('preview_total_rows')->default(0);
            $table->unsignedInteger('preview_create_rows')->default(0);
            $table->unsignedInteger('preview_update_rows')->default(0);
            $table->unsignedInteger('preview_unchanged_rows')->default(0);
            $table->unsignedInteger('preview_warning_rows')->default(0);
            $table->unsignedInteger('preview_error_rows')->default(0);
            $table->string('status', 16)->default('preview');
            $table->foreignId('creator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('checksum');
        });

        Schema::create('customer_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('customer_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('source_row_no');
            $table->json('raw_data');
            $table->json('normalized_data');
            $table->json('present_fields');
            $table->foreignId('target_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('action', 16);
            $table->string('validation_status', 16);
            $table->text('validation_message')->nullable();
            $table->timestamps();
            $table->unique(['import_batch_id', 'source_row_no']);
            $table->index(['import_batch_id', 'validation_status']);
            $table->index('target_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_import_rows');
        Schema::dropIfExists('customer_import_batches');
    }
};
