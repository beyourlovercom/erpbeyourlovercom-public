<?php

namespace App\Modules\Audit\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use Illuminate\Http\JsonResponse;

class AuditRecordController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $records = AuditRecord::query()
            ->latest('id')
            ->limit(100)
            ->get([
                'id',
                'actor_id',
                'action',
                'entity_type',
                'entity_id',
                'before',
                'after',
                'source',
                'request_id',
                'ip_address',
                'user_agent',
                'reason',
                'created_at',
            ]);

        return response()->json(['data' => $records]);
    }
}
