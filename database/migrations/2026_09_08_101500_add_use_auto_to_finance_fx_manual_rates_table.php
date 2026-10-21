<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_fx_manual_rates', function (Blueprint $table): void {
            $table->boolean('use_auto')->default(false)->after('manual_rate_to_cny');
        });
    }

    public function down(): void
    {
        Schema::table('finance_fx_manual_rates', function (Blueprint $table): void {
            $table->dropColumn('use_auto');
        });
    }
};
