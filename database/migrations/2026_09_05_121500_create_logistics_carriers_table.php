<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_carriers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('logistics_carriers')->insert(array_map(
            fn (string $name): array => ['name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['4PX', 'DHL', 'USPS', 'UPS', 'FedEx', 'Canada Post', 'SF Express', 'Singapore Post'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_carriers');
    }
};
