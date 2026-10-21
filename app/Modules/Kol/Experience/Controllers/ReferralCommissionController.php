<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\AffiliateWp\ReferralCommissionSyncService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class ReferralCommissionController
{
    public function __construct(
        private readonly ReferralCommissionSyncService $service,
        private readonly AffiliateWpGateway $gateway,
    ) {}

    public function index(Request $request): Response
    {
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');
        $workspace = $this->service->workspace($connectionKey, $request->user());

        return Inertia::render('Kol/Commissions', [
            'canSync' => $request->user()->can('kol.affiliates.manage'),
            'canRequestPayouts' => $request->user()->can('kol.payouts.request'),
            'canManagePayouts' => $request->user()->can('kol.payouts.manage'),
            'connectionKey' => $connectionKey,
            'referrals' => $workspace['rows'],
            'summary' => $workspace['summary'],
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');
        try {
            $result = $this->service->sync($connectionKey, $request->user());
        } catch (RuntimeException $exception) {
            report($exception);
            return back()->withErrors(['referral_sync' => 'Referral 同步失败。未应用不完整快照，请检查 Connector 后重试。']);
        }

        return back()->with('success', sprintf(
            'Referral 同步完成：%d 条；%d 条历史记录标记缺失；%d 条 Affiliate/KOL 未解析；%d 条订单未解析。',
            $result['synced'],
            $result['marked_missing'],
            $result['affiliate_unresolved'],
            $result['order_unresolved'],
        ));
    }
}
