<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\AffiliateWp\AffiliateBindingService;
use App\Modules\Kol\Application\AffiliateWp\AffiliateSnapshotSyncService;
use App\Modules\Kol\Application\AffiliateWp\CouponProvisioningService;
use App\Modules\Kol\Application\AffiliateWp\CouponSnapshotSyncService;
use App\Modules\Kol\Application\AffiliateWp\KolProvisioningService;
use App\Modules\Kol\Application\Reconciliation\KolReconciliationService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class AffiliateWpSyncController
{
    public function __construct(
        private readonly AffiliateSnapshotSyncService $syncService,
        private readonly AffiliateBindingService $bindingService,
        private readonly KolProvisioningService $provisioningService,
        private readonly CouponSnapshotSyncService $couponSyncService,
        private readonly CouponProvisioningService $couponProvisioningService,
        private readonly AffiliateWpGateway $gateway,
        private readonly KolReconciliationService $reconciliationService,
    ) {}

    public function index(Request $request): Response
    {
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');

        $canManageAffiliates = $request->user()->can('kol.affiliates.manage');
        $kolOptions = [];
        $ownerOptions = [];

        if ($canManageAffiliates) {
            $kolOptions = Kol::query()
                ->whereNull('archived_at')
                ->orderBy('display_name')
                ->get(['id', 'display_name'])
                ->map(fn (Kol $kol): array => ['value' => $kol->id, 'label' => $kol->display_name])
                ->values()
                ->all();

            $ownerOptions = User::query()
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereHas('roles.permissions', fn ($permission): mixed => $permission->where('key', 'kol.view'))
                        ->orWhereHas('customPermissions', fn ($permission): mixed => $permission->where('key', 'kol.view'));
                })
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name])
                ->values()
                ->all();
        }

        return Inertia::render('Kol/AffiliateWp/Index', [
            'canManageApprovals' => $request->user()->can('kol.approvals.manage'),
            'canManageAffiliates' => $canManageAffiliates,
            'affiliates' => $this->syncService->workspaceRows($connectionKey),
            'coupons' => $this->couponSyncService->workspaceRows($connectionKey),
            'provisioningCandidates' => $this->provisioningCandidates($request, $connectionKey, $canManageAffiliates),
            'couponCandidates' => $this->couponCandidates($request, $connectionKey, $canManageAffiliates),
            'kolOptions' => $kolOptions,
            'ownerOptions' => $ownerOptions,
        ]);
    }

    public function reconciliation(Request $request): Response
    {
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');
        return Inertia::render('Kol/AffiliateWp/Reconciliation', [
            'connectionKey' => $connectionKey,
            'batches' => $this->reconciliationService->workspace($connectionKey),
            'canOperate' => $request->user()->can('kol.operate'),
        ]);
    }

    public function previewReconciliation(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx|max:65536']);
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');
        $batch = $this->reconciliationService->preview($request->file('file'), $connectionKey, $request->user());
        return response()->json(['batch' => [
            'id' => $batch->id, 'source_type' => $batch->source_type, 'original_filename' => $batch->original_filename,
            'checksum' => $batch->checksum, 'total_rows' => $batch->total_rows, 'distinct_source_identities' => $batch->distinct_source_identities,
            'summary' => $batch->preview_summary, 'rows' => $batch->rows->map(fn ($row) => [
                'source_row_number' => $row->source_row_number, 'identity' => $row->normalized_identity, 'evidence' => $row->exact_match_evidence,
                'kol' => $row->kol ? ['id' => $row->kol->id, 'display_name' => $row->kol->display_name] : null,
                'affiliate_account_id' => $row->matched_affiliate_account_id, 'kol_status' => $row->kol_status,
                'affiliate_status' => $row->affiliate_status, 'status' => $row->reconciliation_status, 'message' => $row->message,
            ])->values()->all(),
        ]]);
    }

    public function bind(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:kol_affiliate_accounts,id',
            'kol_id' => 'required|integer|exists:kols,id',
        ]);
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');

        try {
            $this->bindingService->bind($connectionKey, (int) $data['account_id'], (int) $data['kol_id'], $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['affiliatewp_binding' => $exception->getMessage()]);
        }

        return back()->with('success', 'Affiliate 已绑定到 ERP KOL。');
    }

    public function convert(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:kol_affiliate_accounts,id',
            'owner_id' => 'required|integer|exists:users,id',
            'display_name' => 'nullable|string|max:255',
        ]);
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');

        try {
            $result = $this->bindingService->convert(
                $connectionKey,
                (int) $data['account_id'],
                (int) $data['owner_id'],
                $data['display_name'] ?? null,
                $request->user(),
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['affiliatewp_binding' => $exception->getMessage()]);
        }

        return back()->with('success', 'Affiliate 已转换并绑定到新 KOL #'.$result['kol']->getKey().'。');
    }

    public function provision(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kol_id' => 'required|integer|exists:kols,id',
        ]);
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');

        try {
            $account = $this->provisioningService->provision($connectionKey, (int) $data['kol_id'], $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['affiliatewp_provision' => $exception->getMessage()]);
        }

        $status = $account->provisioning_status;

        if ($status === 'conflict') {
            return back()->withErrors(['affiliatewp_provision' => 'Provision 遇到 Affiliate ID 冲突。请核对 ERP mirror 与 Provider 后再安全重试。']);
        }
        if ($status === 'readback_mismatch') {
            return back()->withErrors(['affiliatewp_provision' => 'Provision 已调用 Provider，但 read-back 未确认 active + Affiliate ID + WordPress User ID。']);
        }
        if ($status === 'error') {
            return back()->withErrors(['affiliatewp_provision' => 'Provision 调用失败。已保留可重试状态与稳定 idempotency key。']);
        }

        return back()->with('success', $status === 'active'
            ? 'KOL 已成功配置为 AffiliateWP Influencer。'
            : 'KOL Provision 请求已提交（当前状态：'.$status.'）。');
    }


    public function syncCoupons(Request $request): RedirectResponse
    {
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');
        try {
            $result = $this->couponSyncService->sync($connectionKey, $request->user());
        } catch (RuntimeException $exception) {
            report($exception);
            return back()->withErrors(['coupon_sync' => 'Coupon 同步失败。请检查 Connector / WooCommerce 状态后重试。']);
        }

        return back()->with('success', sprintf(
            'Coupon 同步完成：%d 条已同步，%d 条标记为未出现在最新快照。',
            $result['synced'],
            $result['marked_missing'],
        ));
    }

    public function provisionCoupon(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kol_id' => 'required|integer|exists:kols,id',
            'code' => 'required|string|max:255',
            'discount_type' => 'required|in:percent,fixed_cart,fixed_product',
            'amount' => ['required', 'regex:/^(?:0|[1-9]\\d*)(?:\\.\\d{1,6})?$/'],
            'expiry_date' => 'nullable|date_format:Y-m-d',
        ]);
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');
        $expiry = empty($data['expiry_date'])
            ? null
            : CarbonImmutable::createFromFormat('Y-m-d', $data['expiry_date'], config('app.timezone', 'UTC'))->endOfDay()->toIso8601String();

        try {
            $coupon = $this->couponProvisioningService->provision($connectionKey, [
                'kol_id' => (int) $data['kol_id'],
                'code' => $data['code'],
                'discount_type' => $data['discount_type'],
                'amount' => $data['amount'],
                'expiry' => $expiry,
            ], $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['coupon_provision' => $exception->getMessage()]);
        } catch (RuntimeException $exception) {
            report($exception);
            return back()->withErrors(['coupon_provision' => 'Coupon Provider 请求失败，未报告成功。']);
        }

        return match ($coupon->provisioning_status) {
            'active' => back()->with('success', 'Coupon 已创建并通过 WooCommerce + Affiliate read-back 验证。'),
            'conflict' => back()->withErrors(['coupon_provision' => 'Coupon Code 已存在，冲突已硬阻止，未覆盖现有 Owner。']),
            'readback_mismatch' => back()->withErrors(['coupon_provision' => 'Coupon 已调用 Provider，但 read-back 与请求不一致；可使用原 idempotency key 安全重试。']),
            default => back()->withErrors(['coupon_provision' => 'Coupon Provision 未完成；已保留稳定 idempotency key 与错误状态。']),
        };
    }

    /** @return list<array<string,mixed>> */
    private function couponCandidates(Request $request, string $connectionKey, bool $canManageAffiliates): array
    {
        if (! $canManageAffiliates) {
            return [];
        }
        $query = KolAffiliateAccount::query()
            ->where('connection_key', $connectionKey)
            ->whereNotNull('affiliate_id')
            ->whereNotNull('kol_id')
            ->where('reconciliation_status', 'present')
            ->with('kol:id,display_name,owner_id,archived_at')
            ->whereHas('kol', fn ($kol) => $kol->whereNull('archived_at'));
        if (! KolScope::isAllScope($request->user())) {
            $query->whereHas('kol', fn ($kol) => $kol->where('owner_id', (int) $request->user()->getAuthIdentifier()));
        }

        return $query->orderBy('kol_id')->get()->map(fn (KolAffiliateAccount $account): array => [
            'kol_id' => (int) $account->kol_id,
            'display_name' => $account->kol?->display_name ?? 'KOL #'.$account->kol_id,
            'affiliate_id' => (int) $account->affiliate_id,
            'provider_status' => $account->provider_status,
        ])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function provisioningCandidates(Request $request, string $connectionKey, bool $canManageAffiliates): array
    {
        $query = Kol::query()
            ->whereNull('archived_at')
            ->where('approval_status', 'approved')
            ->orderBy('display_name')
            ->orderBy('id');

        if (! KolScope::isAllScope($request->user())) {
            $query->where('owner_id', (int) $request->user()->getAuthIdentifier());
        }

        $kols = $query->get(['id', 'display_name', 'contact_email', 'owner_id', 'approval_status']);
        $accounts = KolAffiliateAccount::query()
            ->where('connection_key', $connectionKey)
            ->whereIn('kol_id', $kols->pluck('id'))
            ->get()
            ->keyBy('kol_id');

        return $kols->map(function (Kol $kol) use ($accounts, $canManageAffiliates): array {
            /** @var KolAffiliateAccount|null $account */
            $account = $accounts->get($kol->id);
            $status = $account?->provisioning_status ?? 'not_requested';
            $hasExternalAffiliate = $account !== null
                && $account->affiliate_id !== null
                && $account->origin !== 'erp_provisioned';

            $disabledReason = match (true) {
                ! $canManageAffiliates => '需要 Affiliate 管理权限',
                $hasExternalAffiliate => '该 KOL 已绑定外部 Affiliate，无需 Provision',
                $status === 'active' => '该 KOL 已完成 Provision',
                $status === 'in_progress' => 'Provision 处理中，请刷新状态后再操作',
                default => null,
            };

            return [
                'kol_id' => $kol->id,
                'display_name' => $kol->display_name,
                'contact_email' => $kol->contact_email,
                'approval_status' => $kol->approval_status,
                'affiliate_id' => $account?->affiliate_id,
                'wordpress_user_id' => $account?->wordpress_user_id,
                'provider_status' => $account?->provider_status,
                'provisioning_status' => $status,
                'last_error_code' => $account?->last_error_code,
                'last_error_message' => $account?->last_error_message,
                'can_provision' => $disabledReason === null,
                'disabled_reason' => $disabledReason,
            ];
        })->values()->all();
    }

    public function sync(Request $request): RedirectResponse
    {
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');

        try {
            $result = $this->syncService->sync($connectionKey, $request->user());
        } catch (RuntimeException $exception) {
            report($exception);

            return back()->withErrors([
                'affiliatewp_sync' => 'AffiliateWP 同步失败。请检查连接器状态后重试。',
            ]);
        }

        return back()->with('success', sprintf(
            'AffiliateWP 同步完成：%d 条已同步，%d 条标记为未出现在最新快照。',
            $result['synced'],
            $result['marked_missing'],
        ));
    }
}
