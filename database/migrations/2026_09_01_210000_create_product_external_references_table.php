<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_external_references', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('provider');
            $table->string('connection_key');
            $table->string('reference');
            $table->string('reference_type'); // product | variation
            $table->timestamps();

            $table->foreign('product_id')
                ->references('id')->on('products')
                ->onDelete('cascade');

            $table->unique(['provider', 'connection_key', 'reference'], 'per_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_external_references');
    }
};
