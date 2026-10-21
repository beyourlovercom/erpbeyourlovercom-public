<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_financials', function (Blueprint $table): void {
            $table->decimal('tax_collected', 18, 2)->nullable()->after('shipping_revenue');
            $table->decimal('provider_order_total', 18, 2)->nullable()->after('amount_paid');
            $table->decimal('refund_tax', 18, 2)->nullable()->after('refund_amount');
            $table->string('payment_method', 127)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('provider_reference', 127)->nullable();
            $table->string('provider_order_status', 32)->nullable();
            $table->timestamp('provider_modified_at')->nullable();
            $table->string('revenue_provenance', 64)->nullable();
            $table->string('completeness_status', 32)->default('UNPOSTED');
            $table->json('missing_facts')->nullable();
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->string('fx_rate_source', 127)->nullable();
            $table->string('reconciliation_status', 32)->nullable();
            $table->json('reconciliation_exception')->nullable();
            $table->index(['completeness_status', 'currency']);
        });
        Schema::table('order_cost_entries', function (Blueprint $table): void {
            $table->string('provenance', 64)->default('confirmed_manual');
            $table->string('status', 32)->default('confirmed');
            $table->string('source_reference', 255)->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('source_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('exchange_rate', 18, 8)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->string('exchange_rate_source', 127)->nullable();
            $table->decimal('base_amount', 18, 2)->nullable();
            $table->string('base_currency', 3)->default('USD');
            $table->index(['provenance', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('order_cost_entries', function (Blueprint $table): void {
            $table->dropIndex(['provenance', 'status']);
            $table->dropForeign(['created_by']); $table->dropForeign(['confirmed_by']);
            $table->dropColumn(['provenance', 'status', 'source_reference', 'effective_at', 'source_at', 'created_by', 'confirmed_by', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'base_amount', 'base_currency']);
        });
        Schema::table('order_financials', function (Blueprint $table): void {
            $table->dropIndex(['completeness_status', 'currency']);
            $table->dropColumn(['tax_collected', 'provider_order_total', 'refund_tax', 'payment_method', 'paid_at', 'provider', 'provider_reference', 'provider_order_status', 'provider_modified_at', 'revenue_provenance', 'completeness_status', 'missing_facts', 'fx_rate', 'fx_rate_date', 'fx_rate_source', 'reconciliation_status', 'reconciliation_exception']);
        });
    }
};
