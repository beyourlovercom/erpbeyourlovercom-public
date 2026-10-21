<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'order_financials_payment_transaction_unique';

    public function up(): void
    {
        Schema::table('order_financials', function (Blueprint $table): void {
            $table->string('payment_transaction_id', 127)->nullable()->after('payment_method');
            $table->unique(['payment_method', 'payment_transaction_id'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('order_financials', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('payment_transaction_id');
        });
    }
};
