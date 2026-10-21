<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('erp_order_id', 64)->nullable()->unique()->after('internal_order_number');
        });

        Schema::create('order_identity_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('prefix_token', 24);
            $table->unsignedBigInteger('next_customer_number')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('order_identity_settings')->insert(['id' => 1, 'prefix_token' => '007erp', 'next_customer_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_identity_settings');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['erp_order_id']);
            $table->dropColumn('erp_order_id');
        });
    }
};
