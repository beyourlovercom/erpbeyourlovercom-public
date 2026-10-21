<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('manual_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('website_sync_status', 32)->default('pending');
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('synced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('sync_note')->nullable();
            $table->timestamps();
            $table->index(['website_sync_status', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_syncs');
    }
};
