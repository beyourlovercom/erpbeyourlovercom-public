<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('procurement_orders', function (Blueprint $table): void {
            $table->string('completion_reason', 64)->nullable()->after('tracking_number');
            $table->timestamp('completed_at')->nullable()->after('completion_reason');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_orders', function (Blueprint $table): void {
            $table->dropColumn(['completion_reason', 'completed_at']);
        });
    }
};
