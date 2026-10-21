<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_fx_manual_rates', function (Blueprint $table): void {
            $table->string('currency', 3)->primary();
            $table->decimal('manual_rate_to_cny', 18, 8);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_fx_manual_rates');
    }
};
