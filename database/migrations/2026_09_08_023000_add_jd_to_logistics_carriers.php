<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('logistics_carriers')->updateOrInsert(
            ['name' => 'JD'],
            ['is_active' => true, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('logistics_carriers')->where('name', 'JD')->delete();
    }
};
