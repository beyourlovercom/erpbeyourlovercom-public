<?php

namespace App\Modules\Procurement\Infrastructure;

use App\Modules\Procurement\Application\ProcurementOrderStatus;
use App\Modules\Procurement\Infrastructure\Models\ProcurementOrder;

final class ProcurementOrderCompletionWriter
{
    private const ACTIVE_STATUSES = ['pending', 'purchasing', 'ordered'];

    public function completeForSourceOrder(int $sourceOrderId): ?ProcurementOrder
    {
        $procurement = ProcurementOrder::query()
            ->where('source_order_id', $sourceOrderId)
            ->first();

        if (! $procurement || ! in_array($procurement->status, self::ACTIVE_STATUSES, true)) {
            return $procurement;
        }

        $now = now();
        $procurement->forceFill([
            'status' => ProcurementOrderStatus::Completed->value,
            'completion_reason' => 'source_order_shipped',
            'completed_at' => $now,
            'status_updated_at' => $now,
        ])->save();

        return $procurement->fresh();
    }
}
