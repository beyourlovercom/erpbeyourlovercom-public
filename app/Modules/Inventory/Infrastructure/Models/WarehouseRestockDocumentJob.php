<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WarehouseRestockDocumentJob extends Model
{
    protected $fillable = [
        'warehouse_id', 'restock_plan_id', 'reference', 'document_kind', 'status',
        'schema_version', 'template_version', 'generator_version', 'dispatch_mode',
        'plan_snapshot_checksum', 'payload_checksum', 'payload', 'attempt_count', 'next_attempt_at',
        'worker_id', 'claimed_at', 'lease_expires_at', 'started_at', 'last_heartbeat_at',
        'completed_at', 'failed_at', 'result_manifest', 'result_manifest_checksum',
        'output_root', 'last_error_code', 'last_error_message', 'requested_by', 'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'warehouse_id' => 'integer',
            'restock_plan_id' => 'integer',
            'payload' => 'array',
            'attempt_count' => 'integer',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'result_manifest' => 'array',
            'requested_by' => 'integer',
            'requested_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function plan(): BelongsTo { return $this->belongsTo(WarehouseRestockPlan::class, 'restock_plan_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
}
