<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_addresses', function (Blueprint $table): void {
            $table->string('email', 255)->nullable()->after('phone');
            $table->string('company', 255)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('order_addresses', function (Blueprint $table): void {
            $table->dropColumn(['email', 'company']);
        });
    }
};
