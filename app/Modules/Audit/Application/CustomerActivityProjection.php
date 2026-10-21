<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Identity\Application\UserDirectory;

final class CustomerActivityProjection
{
    private const ACTION_LABELS = [
        'customer.created' => '创建客户', 'customer.updated' => '更新客户',
        'customer.archived' => '归档客户', 'customer.restored' => '恢复客户',
        'support_ticket.created' => '创建支持工单', 'support_ticket.updated' => '更新支持工单',
        'support_ticket.status_changed' => '变更工单状态', 'support_ticket.assigned' => '分配支持工单',
    ];

    public function __construct(private readonly UserDirectory $users) {}

    /** @param list<int|string> $supportTicketIds @return list<array<string, mixed>> */
    public function forCustomer(int $customerId, array $supportTicketIds = []): array
    {
        $customerType = 'App\\Modules\\Customers\\Infrastructure\\Models\\Customer';
        $ticketType = 'App\\Modules\\Support\\Infrastructure\\Models\\SupportTicket';
        $records = AuditRecord::query()->where(function ($query) use ($customerId, $customerType, $ticketType, $supportTicketIds): void {
            $query->where(fn ($q) => $q->where('entity_type', $customerType)->where('entity_id', (string) $customerId));
            if ($supportTicketIds !== []) {
                $query->orWhere(fn ($q) => $q->where('entity_type', $ticketType)->whereIn('entity_id', array_map('strval', $supportTicketIds)));
            }
        })->latest('created_at')->limit(30)->get();

        $names = $this->users->namesForIds($records->pluck('actor_id'));
        return $records->map(fn (AuditRecord $record): array => [
            'action' => $record->action,
            'action_label' => self::ACTION_LABELS[$record->action] ?? $record->action,
            'actor_id' => $record->actor_id,
            'actor_name' => $record->actor_id !== null ? ($names[(string) $record->actor_id] ?? null) : null,
            'created_at' => $record->created_at?->toIso8601String(),
            'reason' => $record->reason,
        ])->all();
    }
}
