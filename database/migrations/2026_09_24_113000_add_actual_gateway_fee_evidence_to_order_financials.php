<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_financials', function (Blueprint $table): void {
            $table->decimal('actual_payment_fee', 18, 2)->nullable()->after('payment_transaction_id');
            $table->string('actual_payment_fee_source', 127)->nullable()->after('actual_payment_fee');
            $table->string('actual_payment_fee_status', 32)->nullable()->after('actual_payment_fee_source');
        });
    }

    public function down(): void
    {
        Schema::table('order_financials', function (Blueprint $table): void {
            $table->dropColumn([
                'actual_payment_fee',
                'actual_payment_fee_source',
                'actual_payment_fee_status',
            ]);
        });
    }
};
