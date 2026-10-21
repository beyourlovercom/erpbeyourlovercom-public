<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->string('contact_email')->nullable()->after('notes');
            $table->string('contact_phone', 64)->nullable()->after('contact_email');
            $table->text('tax_id')->nullable()->after('contact_phone');
            $table->string('payout_method', 32)->nullable()->after('tax_id');
            $table->text('payout_account')->nullable()->after('payout_method');
            $table->string('shipping_recipient_name')->nullable()->after('payout_account');
            $table->string('shipping_country', 8)->nullable()->after('shipping_recipient_name');
            $table->string('shipping_province_state')->nullable()->after('shipping_country');
            $table->string('shipping_city')->nullable()->after('shipping_province_state');
            $table->string('shipping_postal_code', 32)->nullable()->after('shipping_city');
            $table->string('shipping_address_line_1')->nullable()->after('shipping_postal_code');
            $table->string('shipping_address_line_2')->nullable()->after('shipping_address_line_1');
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->dropColumn([
                'contact_email',
                'contact_phone',
                'tax_id',
                'payout_method',
                'payout_account',
                'shipping_recipient_name',
                'shipping_country',
                'shipping_province_state',
                'shipping_city',
                'shipping_postal_code',
                'shipping_address_line_1',
                'shipping_address_line_2',
            ]);
        });
    }
};
