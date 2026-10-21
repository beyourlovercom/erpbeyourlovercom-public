<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_affiliate_accounts', function (Blueprint $table): void {
            $table->string('wordpress_user_login', 255)->nullable()->after('wordpress_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('kol_affiliate_accounts', function (Blueprint $table): void {
            $table->dropColumn('wordpress_user_login');
        });
    }
};
