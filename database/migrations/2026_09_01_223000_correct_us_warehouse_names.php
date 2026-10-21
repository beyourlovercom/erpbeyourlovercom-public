<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('warehouses')
            ->where('code', 'US-LA')
            ->where('name', '美东洛杉矶仓')
            ->update(['name' => '美西洛杉矶仓']);

        DB::table('warehouses')
            ->where('code', 'US-NY')
            ->where('name', '美西纽约仓')
            ->update(['name' => '美东纽约仓']);
    }

    public function down(): void
    {
        DB::table('warehouses')
            ->where('code', 'US-LA')
            ->where('name', '美西洛杉矶仓')
            ->update(['name' => '美东洛杉矶仓']);

        DB::table('warehouses')
            ->where('code', 'US-NY')
            ->where('name', '美东纽约仓')
            ->update(['name' => '美西纽约仓']);
    }
};
