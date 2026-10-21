<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolOpportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class KolOpportunityController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function store(Request $request, Kol $kol): JsonResponse
    {
        $this->authorizeKol($request, $kol);
        $data = $this->validated($request);
        if (! KolScope::isAllScope($request->user())) {
            $data['owner_id'] = (int) $request->user()->getAuthIdentifier();
        }
        $data['kol_id'] = $kol->id;
        $opportunity = KolOpportunity::create($data);
        $this->recordAudit($request, 'kol.opportunity.created', $opportunity, null, $this->auditSnapshot($opportunity));

        return response()->json(['opportunity' => $this->payload($opportunity->load('owner'))], 201);
    }

    public function update(Request $request, Kol $kol, KolOpportunity $opportunity): JsonResponse
    {
        $this->authorizeOpportunity($request, $kol, $opportunity);
        $before = $this->auditSnapshot($opportunity);
        $data = $this->validated($request);
        if (! KolScope::isAllScope($request->user())) {
            $data['owner_id'] = (int) $request->user()->getAuthIdentifier();
        }
        $opportunity->update($data);
        $this->recordAudit($request, 'kol.opportunity.updated', $opportunity, $before, $this->auditSnapshot($opportunity));

        return response()->json(['opportunity' => $this->payload($opportunity->load('owner'))]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'cooperation_type' => 'nullable|string|in:免费发帖,长期合作,付费发帖',
            'target_products' => 'nullable|array|max:5',
            'target_products.*' => 'string|max:128|exists:products,sku',
            'stage' => 'required|string|in:pending_contact,contacted,communicating,confirmed,waiting_sample,success,failed',
            'owner_id' => 'nullable|integer|exists:users,id',
            'estimated_cost' => 'nullable|numeric|min:0|max:9999999999.99',
            'currency' => 'required|string|size:3',
            'expected_publish_at' => 'nullable|date',
            'next_follow_up_at' => 'nullable|date',
            'next_action' => 'nullable|string|max:2000',
            'status' => 'required|string|in:active,completed,success,failed,paused,cancelled',
            'notes' => 'nullable|string|max:5000',
        ]);
    }

    private function authorizeKol(Request $request, Kol $kol): void
    {
        if (! KolScope::isAllScope($request->user()) && $kol->owner_id !== (int) $request->user()->getAuthIdentifier()) {
            abort(403);
        }
    }

    private function authorizeOpportunity(Request $request, Kol $kol, KolOpportunity $opportunity): void
    {
        $this->authorizeKol($request, $kol);
        abort_unless((int) $opportunity->kol_id === (int) $kol->id, 404);
    }

    private function payload(KolOpportunity $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'cooperation_type' => $item->cooperation_type,
            'target_products' => $item->target_products ?? [],
            'stage' => $item->stage,
            'owner_id' => $item->owner_id,
            'owner' => $item->owner ? ['id' => $item->owner->id, 'name' => $item->owner->name] : null,
            'estimated_cost' => $item->estimated_cost !== null ? (float) $item->estimated_cost : null,
            'currency' => $item->currency,
            'expected_publish_at' => $item->expected_publish_at?->format('Y-m-d'),
            'next_follow_up_at' => $item->next_follow_up_at?->format('Y-m-d\\TH:i'),
            'next_action' => $item->next_action,
            'status' => $item->status,
            'notes' => $item->notes,
        ];
    }

    private function auditSnapshot(KolOpportunity $item): array
    {
        return ['kol_id' => $item->kol_id, 'stage' => $item->stage, 'status' => $item->status, 'owner_id' => $item->owner_id];
    }

    private function recordAudit(Request $request, string $action, KolOpportunity $item, ?array $before, ?array $after): void
    {
        $this->audit->record(AuditActor::user($request->user(), AuditSource::Web), $action, 'kol_opportunity', $item->id, $before, $after);
    }
}
