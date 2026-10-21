<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->string('ownership_type', 32)->default('owned')->after('type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropIndex(['ownership_type']);
            $table->dropColumn('ownership_type');
        });
    }
};
