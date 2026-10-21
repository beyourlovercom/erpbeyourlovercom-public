<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_operational_markers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('marker', 32);
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at');
            $table->timestamps();
            $table->unique(['order_id', 'marker']);
            $table->index(['marker', 'marked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_operational_markers');
    }
};
