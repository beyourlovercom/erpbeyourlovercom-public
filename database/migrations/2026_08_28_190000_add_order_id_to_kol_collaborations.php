<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_collaborations', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->unique()->after('owner_id')->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kol_collaborations', function (Blueprint $table): void {
            $table->dropUnique('kol_collaborations_order_id_unique');
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
