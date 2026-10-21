<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_addresses', function (Blueprint $table): void {
            $table->string('address_line_2', 512)->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasLongValues = DB::table('order_addresses')
            ->whereRaw('CHAR_LENGTH(address_line_2) > 255')
            ->exists();

        if ($hasLongValues) {
            throw new \RuntimeException('Cannot shrink order_addresses.address_line_2 to 255 while values longer than 255 exist.');
        }

        Schema::table('order_addresses', function (Blueprint $table): void {
            $table->string('address_line_2', 255)->nullable()->change();
        });
    }
};
