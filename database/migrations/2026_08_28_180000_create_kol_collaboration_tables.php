<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kol_collaborations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->nullable()->unique()->constrained('kol_opportunities')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('post_url')->nullable();
            $table->string('cooperation_type', 64)->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->decimal('agreement_amount', 12, 2)->nullable();
            $table->string('currency', 8)->default('USD');
            $table->date('agreed_at')->nullable();
            $table->date('planned_publish_at')->nullable();
            $table->text('deliverables')->nullable();
            $table->boolean('fulfillment_required')->default(false)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['kol_id', 'status']);
            $table->index(['owner_id', 'status']);
        });

        Schema::create('kol_collaboration_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collaboration_id')->constrained('kol_collaborations')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('sku_snapshot', 128);
            $table->string('product_name_snapshot')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            $table->index(['collaboration_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_collaboration_products');
        Schema::dropIfExists('kol_collaborations');
    }
};
