<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->string('contact_whatsapp', 64)->nullable()->after('contact_phone');
            $table->string('other_contact')->nullable()->after('contact_whatsapp');
            $table->string('preferred_contact_method', 16)->default('email')->after('other_contact');
            $table->string('level', 32)->nullable()->after('preferred_contact_method');
            $table->string('city')->nullable()->after('level');
            $table->json('categories')->nullable()->after('city');
            $table->json('products')->nullable()->after('categories');
            $table->string('content_format', 64)->nullable()->after('products');
            $table->unsignedInteger('product_quantity')->nullable()->after('content_format');
            $table->unsignedInteger('store_count')->nullable()->after('product_quantity');
            $table->string('internal_contact')->nullable()->after('store_count');
            $table->string('mcn_agency')->nullable()->after('internal_contact');
            $table->boolean('is_mcn_managed')->default(false)->after('mcn_agency');

            $table->index('level');
            $table->index('city');
        });

        Schema::create('kol_sales_performance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kol_id')->constrained('kols')->cascadeOnDelete();
            $table->unsignedInteger('order_count');
            $table->timestamp('recorded_at');
            $table->string('source', 64)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kol_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kol_sales_performance');

        Schema::table('kols', function (Blueprint $table): void {
            $table->dropIndex(['level']);
            $table->dropIndex(['city']);
            $table->dropColumn([
                'contact_whatsapp',
                'other_contact',
                'preferred_contact_method',
                'level',
                'city',
                'categories',
                'products',
                'content_format',
                'product_quantity',
                'store_count',
                'internal_contact',
                'mcn_agency',
                'is_mcn_managed',
            ]);
        });
    }
};
