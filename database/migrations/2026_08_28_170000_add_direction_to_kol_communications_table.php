<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_communications', function (Blueprint $table): void {
            $table->string('direction', 16)->default('outbound')->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('kol_communications', function (Blueprint $table): void {
            $table->dropColumn('direction');
        });
    }
};
