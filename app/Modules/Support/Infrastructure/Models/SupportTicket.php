<?php

namespace App\Modules\Support\Infrastructure\Models;

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $fillable = [
        'customer_id', 'subject', 'description', 'status', 'priority', 'source_type',
        'source_provider', 'external_ticket_id', 'requester_name', 'requester_email',
        'requester_phone', 'assigned_user_id', 'opened_at', 'status_updated_at',
        'resolved_at', 'closed_at', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime', 'status_updated_at' => 'datetime',
            'resolved_at' => 'datetime', 'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function getTicketNumberAttribute(): string
    {
        return 'TKT-'.str_pad((string) $this->getKey(), 8, '0', STR_PAD_LEFT);
    }
}
