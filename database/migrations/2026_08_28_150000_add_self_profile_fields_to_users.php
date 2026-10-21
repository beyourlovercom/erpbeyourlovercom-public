<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('contact_name')->nullable()->after('email');
            $table->string('mobile', 64)->nullable()->after('contact_name');
            $table->string('phone', 64)->nullable()->after('mobile');
            $table->string('qq', 64)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['contact_name', 'mobile', 'phone', 'qq']);
        });
    }
};