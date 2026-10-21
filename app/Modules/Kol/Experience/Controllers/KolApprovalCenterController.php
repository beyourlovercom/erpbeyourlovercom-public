<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\Approval\ManagerApprovalCenterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use UnexpectedValueException;

final class KolApprovalCenterController
{
    public function __construct(private readonly ManagerApprovalCenterService $approvals) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('kol.approvals.kols');
    }

    public function kols(Request $request): Response
    {
        return $this->render($request, 'kols');
    }

    public function collaborations(Request $request): Response
    {
        return $this->render($request, 'collaborations');
    }

    public function payouts(Request $request): Response
    {
        return $this->render($request, 'payouts');
    }

    private function render(Request $request, string $module): Response
    {
        return Inertia::render('Kol/ApprovalCenter', ['workspace' => $this->approvals->workspace($request->user(), $module)]);
    }

    public function approveKol(Request $request, int $kol): RedirectResponse
    {
        try { $this->approvals->approveKol($kol, $request->user()); }
        catch (LogicException $e) { return back()->withErrors(['approval' => $e->getMessage()]); }
        return back()->with('success', 'KOL 已批准。');
    }

    public function rejectKol(Request $request, int $kol): RedirectResponse
    {
        $reason = $this->reason($request);
        try { $this->approvals->rejectKol($kol, $reason, $request->user()); }
        catch (LogicException $e) { return back()->withErrors(['approval' => $e->getMessage()]); }
        return back()->with('success', 'KOL 已驳回。');
    }

    public function approveCollaboration(Request $request, int $collaboration): RedirectResponse
    {
        try { $this->approvals->approveCollaboration($collaboration, $request->user()); }
        catch (LogicException $e) { return back()->withErrors(['approval' => $e->getMessage()]); }
        return back()->with('success', '付费合作已批准。');
    }

    public function rejectCollaboration(Request $request, int $collaboration): RedirectResponse
    {
        $reason = $this->reason($request);
        try { $this->approvals->rejectCollaboration($collaboration, $reason, $request->user()); }
        catch (LogicException $e) { return back()->withErrors(['approval' => $e->getMessage()]); }
        return back()->with('success', '付费合作已驳回。');
    }

    public function approvePayout(Request $request, int $payoutRequest): RedirectResponse
    {
        try { $this->approvals->approvePayout($payoutRequest, $request->user()); }
        catch (UnexpectedValueException $e) { return back()->withErrors(['approval' => $e->getMessage()]); }
        return back()->with('success', '佣金付款申请已批准，等待 Finance 执行付款。');
    }

    public function rejectPayout(Request $request, int $payoutRequest): RedirectResponse
    {
        $reason = $this->reason($request);
        try { $this->approvals->rejectPayout($payoutRequest, $reason, $request->user()); }
        catch (UnexpectedValueException $e) { return back()->withErrors(['approval' => $e->getMessage()]); }
        return back()->with('success', '佣金付款申请已驳回。');
    }

    private function reason(Request $request): string
    {
        return (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];
    }
}
