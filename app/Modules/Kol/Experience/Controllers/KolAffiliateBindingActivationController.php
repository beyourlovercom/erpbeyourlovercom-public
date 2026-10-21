<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\AffiliateWp\KolAffiliateBindingActivationService;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateBindingActivationBatch;
use App\Modules\Shell\Application\UserUiPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class KolAffiliateBindingActivationController
{
    private const GRID_NAMESPACE = 'grid.kol.affiliate_binding_activation.v1';

    public function __construct(
        private readonly KolAffiliateBindingActivationService $service,
        private readonly UserUiPreferenceService $uiPreferences,
    ) {}

    public function index(Request $request): Response
    {
        $activationBatchId = $request->integer('activation_batch_id') ?: null;
        return Inertia::render('Kol/AffiliateWp/BindingActivation', [
            'workspace' => $this->service->workspace($request->user(), $activationBatchId),
            'gridPreferenceNamespace' => self::GRID_NAMESPACE,
            'gridPreference' => $this->uiPreferences->get($request->user(), self::GRID_NAMESPACE),
        ]);
    }

    public function prepare(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'activation_batch_id' => 'required|integer|exists:kol_import_activation_batches,id',
        ]);
        $batch = $this->service->prepare((int) $data['activation_batch_id'], $request->user());
        return redirect()->to('/kol/affiliatewp/binding-activation?activation_batch_id='.$batch->import_activation_batch_id)
            ->with('success', 'Affiliate 精确绑定计划已生成。');
    }

    public function apply(Request $request, KolAffiliateBindingActivationBatch $bindingBatch): RedirectResponse
    {
        $data = $request->validate([
            'plan_checksum' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'ready_count' => 'required|integer|min:0',
        ]);
        $this->service->apply(
            $bindingBatch,
            (string) $data['plan_checksum'],
            (int) $data['ready_count'],
            $request->user(),
        );
        return redirect()->to('/kol/affiliatewp/binding-activation?activation_batch_id='.$bindingBatch->import_activation_batch_id)
            ->with('success', 'Affiliate 精确绑定完成；历史 Referral 已在 ERP 本地重新解析。');
    }
}
