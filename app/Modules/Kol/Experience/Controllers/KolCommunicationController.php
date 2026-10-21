<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCommunication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class KolCommunicationController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function store(Request $request, Kol $kol): JsonResponse
    {
        $this->authorizeKol($request, $kol);
        $data = $this->validatePayload($request, $kol);
        $data['kol_id'] = $kol->id;
        $data['created_by'] = (int) $request->user()->getAuthIdentifier();
        $communication = KolCommunication::create($data)->load(['creator', 'opportunity']);
        $this->audit->record(AuditActor::user($request->user(), AuditSource::Web), 'kol.communication.created', 'kol_communication', $communication->id, null, ['kol_id' => $kol->id, 'channel' => $communication->channel]);
        return response()->json(['communication' => $this->serialize($communication)], 201);
    }

    public function update(Request $request, Kol $kol, KolCommunication $communication): JsonResponse
    {
        $this->authorizeKol($request, $kol);
        abort_unless($communication->kol_id === $kol->id, 404);
        $before = $communication->only(['channel', 'direction', 'summary', 'contacted_at', 'next_action', 'next_follow_up_at', 'opportunity_id']);
        $communication->update($this->validatePayload($request, $kol));
        $communication->load(['creator', 'opportunity']);
        $this->audit->record(AuditActor::user($request->user(), AuditSource::Web), 'kol.communication.updated', 'kol_communication', $communication->id, $before, $communication->only(array_keys($before)));
        return response()->json(['communication' => $this->serialize($communication)]);
    }

    private function validatePayload(Request $request, Kol $kol): array
    {
        return $request->validate([
            'opportunity_id' => ['nullable', 'integer', Rule::exists('kol_opportunities', 'id')->where(fn ($q) => $q->where('kol_id', $kol->id))],
            'channel' => 'required|string|in:email,instagram_dm,x_dm,whatsapp,phone,other',
            'direction' => 'required|string|in:outbound,inbound',
            'summary' => 'required|string|max:5000',
            'contacted_at' => 'required|date',
            'next_action' => 'nullable|string|max:2000',
            'next_follow_up_at' => 'nullable|date',
        ]);
    }

    private function authorizeKol(Request $request, Kol $kol): void
    {
        $user = $request->user();
        if (! KolScope::isAllScope($user) && $kol->owner_id !== (int) $user->getAuthIdentifier()) abort(403);
    }

    private function serialize(KolCommunication $c): array
    {
        return [
            'id' => $c->id,
            'opportunity_id' => $c->opportunity_id,
            'channel' => $c->channel,
            'direction' => $c->direction,
            'summary' => $c->summary,
            'contacted_at' => $c->contacted_at?->format('Y-m-d H:i:s'),
            'next_action' => $c->next_action,
            'next_follow_up_at' => $c->next_follow_up_at?->format('Y-m-d H:i:s'),
            'creator' => $c->creator ? ['id' => $c->creator->id, 'name' => $c->creator->name] : null,
            'opportunity' => $c->opportunity ? ['id' => $c->opportunity->id, 'title' => $c->opportunity->title] : null,
        ];
    }
}
