<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_addresses', function (Blueprint $table): void {
            $table->string('recipient_name', 255)->nullable()->change();
            $table->string('phone', 64)->nullable()->change();
            $table->string('country', 128)->nullable()->change();
            $table->string('province_state', 128)->nullable()->change();
            $table->string('city', 128)->nullable()->change();
            $table->string('address_line_1', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_addresses', function (Blueprint $table): void {
            $table->string('recipient_name', 255)->nullable(false)->change();
            $table->string('phone', 64)->nullable(false)->change();
            $table->string('country', 128)->nullable(false)->change();
            $table->string('province_state', 128)->nullable(false)->change();
            $table->string('city', 128)->nullable(false)->change();
            $table->string('address_line_1', 255)->nullable(false)->change();
        });
    }
};
