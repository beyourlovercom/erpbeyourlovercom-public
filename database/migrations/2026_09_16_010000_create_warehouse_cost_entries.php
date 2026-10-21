<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_cost_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->date('cost_month')->index();
            $table->string('category', 32)->index();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('note')->nullable();
            $table->string('source_type', 32)->default('manual')->index();
            $table->string('source_reference')->nullable()->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'cost_month'], 'warehouse_cost_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_cost_entries');
    }
};
