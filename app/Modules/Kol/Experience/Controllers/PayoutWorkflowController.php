<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\AffiliateWp\PayoutSnapshotSyncService;
use App\Modules\Kol\Application\AffiliateWp\PayoutWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use UnexpectedValueException;

final class PayoutWorkflowController
{
    public function __construct(
        private readonly PayoutWorkflowService $workflow,
        private readonly PayoutSnapshotSyncService $snapshots,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $this->workflow->workspace($request->user());
        return Inertia::render('Kol/Payouts', [
            'connectionKey' => $workspace['connection_key'],
            'eligible' => $workspace['eligible'],
            'requests' => $workspace['requests'],
            'canRequest' => $request->user()->can('kol.payouts.request'),
            'canApprove' => $request->user()->can('kol.payouts.approve'),
            'canManage' => $request->user()->can('kol.payouts.manage'),
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        $connectionKey = (string)($this->workflow->workspace($request->user())['connection_key'] ?? '');
        try {
            $result = $this->snapshots->sync($connectionKey, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['payout_sync' => 'Payout 快照同步失败，未应用不完整快照。']);
        }
        return back()->with('success', sprintf('Payout 同步完成：%d 条；%d 条标记缺失；%d 条 KOL 未解析。', $result['synced'], $result['marked_missing'], $result['kol_unresolved']));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['referral_ids' => ['required','array','min:1','max:100'], 'referral_ids.*' => ['integer','min:1','distinct']]);
        try { $row = $this->workflow->create($data['referral_ids'], $request->user()); }
        catch (UnexpectedValueException $e) { return back()->withErrors(['payout_request' => $e->getMessage()]); }
        return back()->with('success', '结算申请 #'.$row->id.' 已提交审批。');
    }

    public function approve(Request $request, int $payoutRequest): RedirectResponse
    {
        try { $this->workflow->approve($payoutRequest, $request->user()); }
        catch (UnexpectedValueException $e) { return back()->withErrors(['payout_approval' => $e->getMessage()]); }
        return back()->with('success', '结算申请已批准。');
    }

    public function reject(Request $request, int $payoutRequest): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required','string','max:1000']]);
        try { $this->workflow->reject($payoutRequest, $data['reason'], $request->user()); }
        catch (UnexpectedValueException $e) { return back()->withErrors(['payout_approval' => $e->getMessage()]); }
        return back()->with('success', '结算申请已驳回。');
    }

    public function complete(Request $request, int $payoutRequest): RedirectResponse
    {
        $data = $request->validate(['paypal_transaction_id' => ['required','string','max:255'], 'paid_at' => ['required','date_format:Y-m-d']]);
        try { $this->workflow->complete($payoutRequest, $data['paypal_transaction_id'], $data['paid_at'], $request->user()); }
        catch (ModelNotFoundException $e) { throw $e; }
        catch (RuntimeException $e) { return back()->withErrors(['payout_complete' => $e->getMessage()]); }
        return back()->with('success', 'Provider Payout 已完成并精确回读。');
    }
}
