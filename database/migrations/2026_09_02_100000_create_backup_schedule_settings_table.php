<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_schedule_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('cadence', 16)->default('daily');
            $table->timestamps();
        });

        DB::table('backup_schedule_settings')->insert([
            'id' => 1,
            'enabled' => false,
            'cadence' => 'daily',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_schedule_settings');
    }
};
