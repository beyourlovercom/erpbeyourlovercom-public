<?php

namespace App\Modules\Finance\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFinancial extends Model
{
    protected $fillable = [
        'merchandise_list_amount', 'discount_amount', 'shipping_revenue',
        'amount_paid', 'refund_amount', 'currency', 'tax_collected', 'provider_order_total', 'refund_tax',
        'payment_method', 'payment_transaction_id', 'actual_payment_fee', 'actual_payment_fee_source', 'actual_payment_fee_status', 'paid_at', 'provider', 'provider_reference', 'provider_order_status', 'provider_modified_at',
        'revenue_provenance', 'completeness_status', 'missing_facts', 'fx_rate', 'fx_rate_date', 'fx_rate_source',
        'reconciliation_status', 'reconciliation_exception', 'reconciliation_status', 'reconciliation_exception',
    ];

    protected $casts = [
        'merchandise_list_amount' => 'decimal:2', 'discount_amount' => 'decimal:2',
        'shipping_revenue' => 'decimal:2', 'amount_paid' => 'decimal:2',
        'refund_amount' => 'decimal:2', 'actual_payment_fee' => 'decimal:2',
        'tax_collected' => 'decimal:2', 'provider_order_total' => 'decimal:2', 'refund_tax' => 'decimal:2',
        'paid_at' => 'immutable_datetime', 'provider_modified_at' => 'immutable_datetime', 'missing_facts' => 'array',
        'reconciliation_exception' => 'array', 'fx_rate' => 'decimal:8', 'fx_rate_date' => 'date', 'reconciliation_exception' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Orders\\Infrastructure\\Models\\Order');
    }
}
