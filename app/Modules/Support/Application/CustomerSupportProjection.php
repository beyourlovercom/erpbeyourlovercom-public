<?php

namespace App\Modules\Support\Application;

use App\Modules\Support\Infrastructure\Models\SupportTicket;
use Illuminate\Support\Collection;

final class CustomerSupportProjection
{
    public const PENDING_STATUSES = ['open', 'waiting_customer', 'waiting_internal'];

    /** @param iterable<int, int|object> $customerIds */
    public function pendingCountsForCustomers(iterable $customerIds): array
    {
        $ids = collect($customerIds)->map(fn (mixed $id): int => (int) (is_object($id) ? $id->id : $id))->filter()->unique()->values();
        if ($ids->isEmpty()) return [];
        return SupportTicket::query()->whereIn('customer_id', $ids)->whereIn('status', self::PENDING_STATUSES)
            ->selectRaw('customer_id, COUNT(*) as ticket_count')->groupBy('customer_id')->pluck('ticket_count', 'customer_id')->map(fn ($v): int => (int) $v)->all();
    }

    public function pendingCount(): int
    {
        return SupportTicket::query()->whereNotNull('customer_id')->whereIn('status', self::PENDING_STATUSES)->count();
    }

    public function forCustomer(int $customerId): array
    {
        return $this->format(SupportTicket::query()->where('customer_id', $customerId)->with('assignee')->latest('last_activity_at')->latest('id')->get());
    }

    /** @param Collection<int, SupportTicket> $tickets */
    private function format(Collection $tickets): array
    {
        return $tickets->map(fn (SupportTicket $ticket): array => [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'source_type' => $ticket->source_type,
            'source_provider' => $ticket->source_provider,
            'assigned_user_id' => $ticket->assigned_user_id,
            'assignee' => $ticket->assignee ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name] : null,
            'opened_at' => $ticket->opened_at?->toIso8601String(),
            'status_updated_at' => $ticket->status_updated_at?->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'last_activity_at' => $ticket->last_activity_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ])->all();
    }
}
