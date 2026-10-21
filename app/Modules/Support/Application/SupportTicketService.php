<?php

namespace App\Modules\Support\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Support\Infrastructure\Models\SupportTicket;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class SupportTicketService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function create(array $attributes, ?Authenticatable $actor): SupportTicket
    {
        return DB::transaction(function () use ($attributes, $actor): SupportTicket {
            $now = now();
            $ticket = SupportTicket::query()->create(array_merge($attributes, [
                'source_type' => 'manual', 'source_provider' => null, 'external_ticket_id' => null,
                'status' => 'open', 'priority' => $attributes['priority'] ?? 'normal',
                'opened_at' => $now, 'last_activity_at' => $now,
            ]));
            $this->record($actor, 'support_ticket.created', $ticket, null, $this->auditData($ticket), 'support_ticket.create');
            if ($ticket->assigned_user_id !== null) {
                $this->record($actor, 'support_ticket.assigned', $ticket, ['assigned_user_id' => null], ['assigned_user_id' => $ticket->assigned_user_id], 'support_ticket.assignment');
            }
            return $ticket;
        });
    }

    public function update(SupportTicket $ticket, array $attributes, ?Authenticatable $actor): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $attributes, $actor): SupportTicket {
            $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $before = $this->auditData($ticket);
            $oldStatus = $ticket->status;
            $newStatus = $attributes['status'] ?? $oldStatus;
            $now = now();
            if ($newStatus !== $oldStatus) {
                $attributes['status_updated_at'] = $now;
                if ($newStatus === 'resolved') {
                    $attributes['resolved_at'] = $now;
                    $attributes['closed_at'] = null;
                } elseif ($newStatus === 'closed') {
                    $attributes['closed_at'] = $now;
                    // Preserve historical resolution when a resolved ticket is closed.
                    if ($ticket->resolved_at === null) {
                        $attributes['resolved_at'] = null;
                    }
                } else {
                    $attributes['resolved_at'] = null;
                    $attributes['closed_at'] = null;
                }
            }
            $attributes['last_activity_at'] = $now;
            $ticket->fill($attributes)->save();
            $after = $this->auditData($ticket);
            $this->record($actor, 'support_ticket.updated', $ticket, $before, $after, 'support_ticket.update');
            if ($newStatus !== $oldStatus) $this->record($actor, 'support_ticket.status_changed', $ticket, ['status' => $oldStatus], ['status' => $newStatus], 'support_ticket.status_change');
            if (($before['assigned_user_id'] ?? null) !== ($after['assigned_user_id'] ?? null)) $this->record($actor, 'support_ticket.assigned', $ticket, ['assigned_user_id' => $before['assigned_user_id']], ['assigned_user_id' => $after['assigned_user_id']], 'support_ticket.assignment');
            return $ticket->fresh('assignee');
        });
    }

    private function auditData(Model $ticket): array
    {
        return $ticket->only(['customer_id', 'subject', 'description', 'status', 'priority', 'source_type', 'source_provider', 'external_ticket_id', 'requester_name', 'requester_email', 'requester_phone', 'assigned_user_id', 'opened_at', 'status_updated_at', 'resolved_at', 'closed_at', 'last_activity_at']);
    }

    private function record(?Authenticatable $actor, string $action, SupportTicket $ticket, ?array $before, ?array $after, string $reason): void
    {
        $this->audit->record($actor ? AuditActor::user($actor, AuditSource::Web) : AuditActor::system(AuditSource::Web), $action, SupportTicket::class, $ticket->id, before: $before, after: $after, reason: $reason);
    }
}
