<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id(); $table->string('slug',64)->unique(); $table->string('code',64)->unique(); $table->string('name',191);
            $table->char('country_code',2)->nullable(); $table->string('address_line1')->nullable(); $table->string('address_line2')->nullable();
            $table->string('city',128)->nullable(); $table->string('state_region',128)->nullable(); $table->string('postal_code',32)->nullable();
            $table->string('timezone',64)->nullable(); $table->string('type',32)->default('warehouse'); $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100); $table->longText('billing_markdown')->nullable(); $table->timestamps();
        });
        Schema::create('warehouse_inventory', function (Blueprint $table): void {
            $table->id(); $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity_on_hand')->default(0); $table->unsignedInteger('quantity_reserved')->default(0);
            $table->timestamp('inbound_at')->nullable(); $table->string('location',128)->nullable(); $table->unsignedInteger('standard_qoh')->nullable();
            $table->unsignedInteger('expected_restock_qty')->default(0); $table->timestamps();
            $table->unique(['warehouse_id','product_id']); $table->index(['product_id','warehouse_id']);
        });
        Schema::create('warehouse_service_regions', function (Blueprint $table): void {
            $table->id(); $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete(); $table->char('country_code',2); $table->unsignedInteger('priority')->default(100); $table->timestamps();
            $table->unique(['warehouse_id','country_code']);
        });
        Schema::create('inventory_adjustments', function (Blueprint $table): void {
            $table->id(); $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete(); $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_inventory_id')->constrained('warehouse_inventory')->cascadeOnDelete();
            $table->unsignedInteger('quantity_before'); $table->unsignedInteger('quantity_after'); $table->unsignedInteger('reserved_before'); $table->unsignedInteger('reserved_after');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete(); $table->string('reason',191); $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments'); Schema::dropIfExists('warehouse_service_regions'); Schema::dropIfExists('warehouse_inventory'); Schema::dropIfExists('warehouses');
    }
};
