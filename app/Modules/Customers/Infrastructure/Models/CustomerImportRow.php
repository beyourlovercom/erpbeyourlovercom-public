<?php

namespace App\Modules\Customers\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerImportRow extends Model
{
    protected $fillable = ['import_batch_id', 'source_row_no', 'raw_data', 'normalized_data', 'present_fields', 'target_customer_id', 'action', 'validation_status', 'validation_message'];
    protected function casts(): array { return ['raw_data' => 'array', 'normalized_data' => 'array', 'present_fields' => 'array']; }
    public function batch(): BelongsTo { return $this->belongsTo(CustomerImportBatch::class, 'import_batch_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'target_customer_id'); }
}
