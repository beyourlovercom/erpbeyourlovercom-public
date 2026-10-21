<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_restock_document_jobs', function (Blueprint $table): void {
            $table->timestamp('next_attempt_at')->nullable()->after('attempt_count');
            $table->index(
                ['document_kind', 'status', 'next_attempt_at', 'requested_at'],
                'restock_document_jobs_worker_queue_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_restock_document_jobs', function (Blueprint $table): void {
            $table->dropIndex('restock_document_jobs_worker_queue_idx');
            $table->dropColumn('next_attempt_at');
        });
    }
};
