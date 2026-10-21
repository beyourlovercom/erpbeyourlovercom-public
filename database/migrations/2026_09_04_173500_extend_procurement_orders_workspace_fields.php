<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('procurement_orders', function (Blueprint $table): void {
            $table->string('carrier', 128)->nullable()->after('status');
            $table->string('tracking_number', 128)->nullable()->after('carrier');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('status_updated_at')->nullable()->after('updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_orders', function (Blueprint $table): void {
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['carrier', 'tracking_number', 'updated_by', 'status_updated_at']);
        });
    }
};
