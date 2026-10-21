<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable()->after('display_name');
            $table->string('avatar_source', 32)->nullable()->after('avatar_path');
            $table->timestamp('avatar_updated_at')->nullable()->after('avatar_source');
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->dropColumn(['avatar_path', 'avatar_source', 'avatar_updated_at']);
        });
    }
};
