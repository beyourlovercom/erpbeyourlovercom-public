<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\KolCreateAutoProvisionService;
use App\Modules\Kol\Application\KolEditAccountService;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Application\KolService;
use App\Modules\Kol\Application\SavedViewService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateCoupon;
use App\Modules\Shell\Application\UserUiPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class KolController
{
    private const GRID_NAMESPACE = 'grid.kol.library.v1';

    public function __construct(
        private readonly KolService $kols,
        private readonly SavedViewService $views,
        private readonly KolCreateAutoProvisionService $autoProvision,
        private readonly KolEditAccountService $editAccount,
        private readonly UserUiPreferenceService $uiPreferences,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $ownerScopeId = KolScope::effectiveOwnerId($user);
        $canManageViews = KolScope::canManageViews($user);

        $filters = $request->only(['search', 'owner_id', 'status', 'relationship_stage', 'country_code', 'platform', 'followers_min', 'followers_max', 'level', 'content_format', 'mcn', 'has_published_content', 'publication_evidence', 'view', 'sort', 'direction']);
        $kols = $this->kols->list($filters, $ownerScopeId);
        $savedViews = $this->views->forUser('kol', (int) $user->getAuthIdentifier(), $canManageViews);

        $ownerOptions = [];
        if ($canManageViews) {
            $ownerOptions = User::whereHas('roles', function ($q): void {
                $q->whereHas('permissions', function ($q): void {
                    $q->where('key', 'kol.view');
                });
            })->orWhereHas('customPermissions', function ($q): void {
                $q->where('key', 'kol.view');
            })->get(['id', 'name'])->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values()->all();
        }

        return Inertia::render('Kol/Index', [
            'kols' => $kols->through(fn (Kol $kol): array => $this->kolData($kol))->toArray(),
            'filters' => $filters,
            'savedViews' => $savedViews,
            'ownerOptions' => $ownerOptions,
            'canOperate' => KolScope::canOperate($user),
            'canManageViews' => $canManageViews,
            'isAllScope' => KolScope::isAllScope($user),
            'gridPreferenceNamespace' => self::GRID_NAMESPACE,
            'gridPreference' => $user ? $this->uiPreferences->get($user, self::GRID_NAMESPACE) : null,
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();

        $ownerOptions = [];
        if (KolScope::canManageViews($user)) {
            $ownerOptions = User::where('is_active', true)->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values()->all();
        }

        $canManageViews = KolScope::canManageViews($user);
        return Inertia::render('Kol/Create', [
            'ownerOptions' => $ownerOptions,
            'approvalManagerOptions' => $canManageViews ? $this->usersWithPermission('kol.approvals.manage') : [],
            'financeOwnerOptions' => $canManageViews ? $this->usersWithPermission('kol.payouts.manage') : [],
            'currentUserId' => (int) $user->getAuthIdentifier(),
            'canManageViews' => $canManageViews,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'country_code' => 'nullable|string|in:US,GB,DE,FR,CA,EU,AU,ASIA,PH,OTHER',
            'language' => 'nullable|string|in:en,es,de,fr,it,zh,other',
            'status' => 'required|string|in:active,inactive',
            'owner_id' => 'nullable|integer|exists:users,id',
            'approval_manager_id' => 'nullable|integer|exists:users,id',
            'finance_owner_id' => 'nullable|integer|exists:users,id',
            'relationship_stage' => 'required|string|in:prospect,contacted,negotiating,cooperating,long_term,paused,ended',
            'cooperation_type' => 'nullable|string|in:免费发帖,长期合作,付费发帖',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
            'contact_email' => 'required|email|max:255',
            'wordpress_password' => ['required', 'string', 'min:12', 'max:128', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed'],
            'wordpress_password_confirmation' => 'required|string|max:128',
            'affiliate_coupon_code' => 'required|string|max:255',
            'contact_phone' => 'nullable|string|max:64',
            'contact_whatsapp' => 'nullable|string|max:64',
            'other_contact' => 'nullable|string|max:255',
            'preferred_contact_method' => 'nullable|string|in:phone,email,whatsapp,other',
            'tax_id' => 'nullable|string|max:128',
            'payout_method' => 'nullable|string|in:paypal,bank,other',
            'payout_account' => 'nullable|string|max:512',
            'shipping_recipient_name' => 'nullable|string|max:255',
            'shipping_country' => 'nullable|string|max:8',
            'shipping_province_state' => 'nullable|string|max:128',
            'shipping_city' => 'nullable|string|max:128',
            'shipping_postal_code' => 'nullable|string|max:32',
            'shipping_address_line_1' => 'nullable|string|max:255',
            'shipping_address_line_2' => 'nullable|string|max:255',
            'level' => 'nullable|string|in:头部,腰部,尾部,素人',
            'gender' => 'nullable|string|in:男,女,女同,男同,其他',
            'categories' => 'nullable|array|max:1',
            'categories.*' => 'string|in:Lifestyle,Beauty,Fitness,漫画,家居,色情,其他',
            'products' => 'nullable|array|max:1',
            'products.*' => 'string|max:128|exists:products,sku',
            'content_format' => 'nullable|string|in:图文,短视频,直播,图文+视频,混合',
            'product_quantity' => 'nullable|integer|min:0',
            'internal_contact' => 'nullable|string|max:255',
            'mcn_agency' => 'nullable|string|max:255',
            'is_mcn_managed' => 'boolean',
            'social_accounts' => 'nullable|array|max:10',
            'social_accounts.*.platform' => 'required|string|in:instagram,tiktok,youtube,xiaohongshu,weibo,wechat,twitter,facebook,other',
            'social_accounts.*.handle' => 'nullable|string|max:255',
            'social_accounts.*.profile_url' => 'nullable|url|max:500',
            'social_accounts.*.is_active' => 'boolean',
            'social_accounts.*.followers' => 'nullable|integer|min:0',
            'social_accounts.*.raw_followers_value' => 'nullable|string|max:32',
            'sales_performance_entries' => 'nullable|array|max:20',
            'sales_performance_entries.*.order_count' => 'nullable|integer|min:1',
            'sales_performance_entries.*.recorded_at' => 'nullable|date',
            'sales_performance_entries.*.source' => 'nullable|string|max:64',
            'sales_performance_entries.*.note' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        if (! KolScope::isAllScope($user)) {
            $data['owner_id'] = (int) $user->getAuthIdentifier();
            $data['approval_manager_id'] = null;
            $data['finance_owner_id'] = null;
        }

        $password = (string) $data['wordpress_password'];
        $couponCode = (string) $data['affiliate_coupon_code'];
        $socialAccounts = $data['social_accounts'] ?? [];
        $salesPerformanceEntries = $data['sales_performance_entries'] ?? [];
        unset(
            $data['wordpress_password'],
            $data['wordpress_password_confirmation'],
            $data['affiliate_coupon_code'],
            $data['social_accounts'],
            $data['sales_performance_entries'],
        );

        $result = $this->autoProvision->create(
            $data,
            $socialAccounts,
            $salesPerformanceEntries,
            $password,
            $couponCode,
            $user,
        );
        unset($password);

        $redirect = redirect()->route('kol.library.show', $result['kol']);
        return $result['success']
            ? $redirect->with('success', 'KOL 已创建；WordPress / AffiliateWP / Coupon 自动开通完成。')
            : $redirect->with('warning', 'KOL 已创建，但自动开通未完整完成，系统已发送处理通知。');
    }

    public function show(Request $request, Kol $kol): Response
    {
        $this->authorizeKolAccess($request, $kol);
        $relations = ['owner', 'approvalManager', 'financeOwner', 'socialAccounts.latestMetric', 'socialAccounts.metrics'];
        if (Schema::hasTable('kol_opportunities')) {
            $relations[] = 'opportunities.owner';
        }
        if (Schema::hasTable('kol_communications')) {
            $relations[] = 'communications.creator';
            $relations[] = 'communications.opportunity';
        }
        if (Schema::hasTable('kol_collaborations')) {
            $relations[] = 'collaborations.owner';
            $relations[] = 'collaborations.products.product';
            $relations[] = 'collaborations.opportunity';
            $relations[] = 'collaborations.order.shipment.warehouse';
            $relations[] = 'collaborations.order.manualSync';
        }
        if (Schema::hasTable('kol_contents')) {
            $relations[] = 'contents.collaboration';
            $relations[] = 'contents.attachments';
        }
        $kol->load($relations);

        return Inertia::render('Kol/Show', [
            'kol' => $this->kolDetailData($kol),
            'canOperate' => KolScope::canOperate($request->user()),
            'canCreateOrders' => $request->user()->can('orders.create'),
            'canManageViews' => KolScope::canManageViews($request->user()),
        ]);
    }

    public function edit(Request $request, Kol $kol): Response
    {
        $this->authorizeKolAccess($request, $kol);
        $editRelations = ['approvalManager', 'financeOwner', 'socialAccounts.latestMetric'];
        if (Schema::hasTable('kol_opportunities')) {
            $editRelations[] = 'opportunities.owner';
        }
        if (Schema::hasTable('kol_communications')) {
            $editRelations[] = 'communications.creator';
            $editRelations[] = 'communications.opportunity';
        }
        if (Schema::hasTable('kol_collaborations')) {
            $editRelations[] = 'collaborations.owner';
            $editRelations[] = 'collaborations.products.product';
            $editRelations[] = 'collaborations.opportunity';
            $editRelations[] = 'collaborations.order.shipment.warehouse';
            $editRelations[] = 'collaborations.order.manualSync';
        }
        if (Schema::hasTable('kol_contents')) {
            $editRelations[] = 'contents.collaboration';
            $editRelations[] = 'contents.attachments';
        }
        $kol->load($editRelations);

        $user = $request->user();
        $ownerOptions = [];
        if (KolScope::canManageViews($user)) {
            $ownerOptions = User::where('is_active', true)->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values()->all();
        }

        $canManageViews = KolScope::canManageViews($user);
        return Inertia::render('Kol/Edit', [
            'kol' => $this->kolDetailData($kol),
            'affiliateAccount' => $this->affiliateAccountData($kol),
            'ownerOptions' => $ownerOptions,
            'approvalManagerOptions' => $canManageViews ? $this->usersWithPermission('kol.approvals.manage') : [],
            'financeOwnerOptions' => $canManageViews ? $this->usersWithPermission('kol.payouts.manage') : [],
            'canManageViews' => $canManageViews,
            'canCreateOrders' => $user->can('orders.create'),
        ]);
    }

    public function update(Request $request, Kol $kol): RedirectResponse
    {
        $this->authorizeKolAccess($request, $kol);

        $submittedLevel = $request->input('level');
        $submittedContentFormat = $request->input('content_format');
        $submittedProduct = $request->input('products.0');
        $legacyLevelUnchanged = $submittedLevel !== null && $submittedLevel === $kol->level;
        $legacyContentFormatUnchanged = $submittedContentFormat !== null && $submittedContentFormat === $kol->content_format;
        $legacyProductUnchanged = $submittedProduct !== null && in_array($submittedProduct, $kol->products ?? [], true);

        $data = $request->validate([
            'display_name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'country_code' => 'nullable|string|in:US,GB,DE,FR,CA,EU,AU,ASIA,PH,OTHER',
            'language' => 'nullable|string|in:en,es,de,fr,it,zh,other',
            'status' => 'required|string|in:active,inactive',
            'owner_id' => 'nullable|integer|exists:users,id',
            'approval_manager_id' => 'nullable|integer|exists:users,id',
            'finance_owner_id' => 'nullable|integer|exists:users,id',
            'relationship_stage' => 'required|string|in:prospect,contacted,negotiating,cooperating,long_term,paused,ended',
            'cooperation_type' => 'nullable|string|in:免费发帖,长期合作,付费发帖',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:64',
            'contact_whatsapp' => 'nullable|string|max:64',
            'other_contact' => 'nullable|string|max:255',
            'preferred_contact_method' => 'nullable|string|in:phone,email,whatsapp,other',
            'tax_id' => 'nullable|string|max:128',
            'payout_method' => 'nullable|string|in:paypal,bank,other',
            'payout_account' => 'nullable|string|max:512',
            'shipping_recipient_name' => 'nullable|string|max:255',
            'shipping_country' => 'nullable|string|max:8',
            'shipping_province_state' => 'nullable|string|max:128',
            'shipping_city' => 'nullable|string|max:128',
            'shipping_postal_code' => 'nullable|string|max:32',
            'shipping_address_line_1' => 'nullable|string|max:255',
            'shipping_address_line_2' => 'nullable|string|max:255',
            'level' => $legacyLevelUnchanged ? 'nullable|string|max:32' : 'nullable|string|in:头部,腰部,尾部,素人',
            'gender' => 'nullable|string|in:男,女,女同,男同,其他',
            'categories' => 'nullable|array|max:1',
            'categories.*' => 'string|in:Lifestyle,Beauty,Fitness,漫画,家居,色情,其他',
            'products' => 'nullable|array|max:1',
            'products.*' => $legacyProductUnchanged ? 'string|max:128' : 'string|max:128|exists:products,sku',
            'content_format' => $legacyContentFormatUnchanged ? 'nullable|string|max:64' : 'nullable|string|in:图文,短视频,直播,图文+视频,混合',
            'product_quantity' => 'nullable|integer|min:0',
            'internal_contact' => 'nullable|string|max:255',
            'mcn_agency' => 'nullable|string|max:255',
            'is_mcn_managed' => 'boolean',
            'social_accounts' => 'nullable|array|max:10',
            'social_accounts.*.id' => 'nullable|integer',
            'social_accounts.*.platform' => 'required|string|in:instagram,tiktok,youtube,xiaohongshu,weibo,wechat,twitter,facebook,other',
            'social_accounts.*.handle' => 'nullable|string|max:255',
            'social_accounts.*.profile_url' => 'nullable|url|max:500',
            'social_accounts.*.is_active' => 'boolean',
            'social_accounts.*.followers' => 'nullable|integer|min:0',
            'social_accounts.*.raw_followers_value' => 'nullable|string|max:32',
            'sales_performance_entries' => 'nullable|array|max:20',
            'sales_performance_entries.*.order_count' => 'nullable|integer|min:1',
            'sales_performance_entries.*.recorded_at' => 'nullable|date',
            'sales_performance_entries.*.source' => 'nullable|string|max:64',
            'sales_performance_entries.*.note' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        if (! KolScope::isAllScope($user)) {
            unset($data['owner_id'], $data['approval_manager_id'], $data['finance_owner_id']);
        }

        $this->kols->update($kol, $data, $data['social_accounts'] ?? [], $user, $data['sales_performance_entries'] ?? []);

        return redirect()->route('kol.library.show', $kol)->with('success', 'KOL 已更新');
    }


    public function provisionAffiliateAccount(Request $request, Kol $kol): JsonResponse
    {
        $this->authorizeKolAccess($request, $kol);
        $data = $request->validate([
            'password' => ['required', 'string', 'min:12', 'max:128', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed'],
            'password_confirmation' => 'required|string|max:128',
            'affiliate_coupon_code' => 'required|string|max:255',
        ]);

        $password = (string) $data['password'];
        $result = $this->editAccount->provision(
            $kol,
            $password,
            (string) $data['affiliate_coupon_code'],
            $request->user(),
        );
        unset($password, $data['password'], $data['password_confirmation']);

        $kol->refresh();
        return response()->json([
            'message' => $result['success']
                ? 'WordPress / AffiliateWP / Coupon 账号已创建并完成 read-back。'
                : '账号创建已执行，但 provider read-back 尚未完整通过，请检查账号状态。',
            'success' => $result['success'],
            'affiliate_account' => $this->affiliateAccountData($kol),
        ], $result['success'] ? 200 : 202);
    }

    public function updateWordpressPassword(Request $request, Kol $kol): JsonResponse
    {
        $this->authorizeKolAccess($request, $kol);
        $data = $request->validate([
            'password' => ['required', 'string', 'min:12', 'max:128', 'regex:/[A-Za-z]/', 'regex:/[0-9]/', 'confirmed'],
            'password_confirmation' => 'required|string|max:128',
        ]);

        $password = (string) $data['password'];
        $this->editAccount->updatePassword($kol, $password, $request->user());
        unset($password, $data['password'], $data['password_confirmation']);

        return response()->json([
            'message' => 'WordPress 密码已更新并完成 exact read-back。',
        ]);
    }

    public function uploadAvatar(Request $request, Kol $kol): JsonResponse
    {
        $this->authorizeKolAccess($request, $kol);

        $validated = $request->validate([
            'avatar' => 'required|image|mimes:jpeg,jpg,png,webp,gif|max:5120',
        ]);

        if ($kol->avatar_path) {
            Storage::disk('public')->delete($kol->avatar_path);
        }

        $path = $validated['avatar']->store("kol/avatars/{$kol->id}", 'public');
        $kol->forceFill([
            'avatar_path' => $path,
            'avatar_source' => 'manual_paste',
            'avatar_updated_at' => now(),
        ])->save();

        return response()->json([
            'avatar_url' => Storage::disk('public')->url($path),
            'avatar_updated_at' => $kol->avatar_updated_at?->toIso8601String(),
        ]);
    }

    public function archive(Request $request, Kol $kol): RedirectResponse
    {
        $this->authorizeKolAccess($request, $kol);
        $this->kols->archive($kol, $request->user());

        return redirect()->route('kol.library')->with('success', 'KOL 已归档');
    }

    public function reactivate(Request $request, Kol $kol): RedirectResponse
    {
        $this->authorizeKolAccess($request, $kol);
        $this->kols->reactivate($kol, $request->user());

        return redirect()->route('kol.library.show', $kol)->with('success', 'KOL 已重新激活');
    }


    /** @return array<string,mixed>|null */
    private function affiliateAccountData(Kol $kol): ?array
    {
        $connectionKey = trim((string) config('services.andy_core.connection_key', 'beyourlover.com')) ?: 'beyourlover.com';
        $account = KolAffiliateAccount::query()
            ->where('connection_key', $connectionKey)
            ->where('kol_id', $kol->getKey())
            ->first();

        $coupon = KolAffiliateCoupon::query()
            ->where('connection_key', $connectionKey)
            ->where('kol_id', $kol->getKey())
            ->orderByDesc('id')
            ->first();

        if (! $account && ! $coupon) {
            return null;
        }

        return [
            'connection_key' => $connectionKey,
            'affiliate_id' => $account?->affiliate_id !== null ? (int) $account->affiliate_id : null,
            'wordpress_user_id' => $account?->wordpress_user_id !== null ? (int) $account->wordpress_user_id : null,
            'wordpress_user_login' => $account?->wordpress_user_login,
            'wordpress_user_email' => $account?->wordpress_user_email,
            'provider_status' => $account?->provider_status,
            'provisioning_status' => $account?->provisioning_status,
            'sync_status' => $account?->sync_status,
            'reconciliation_status' => $account?->reconciliation_status,
            'last_successful_sync_at' => $account?->last_successful_sync_at?->toIso8601String(),
            'last_error_code' => $account?->last_error_code,
            'coupon' => $coupon ? [
                'coupon_id' => $coupon->coupon_id !== null ? (int) $coupon->coupon_id : null,
                'code' => $coupon->code,
                'provider_status' => $coupon->provider_status,
                'provisioning_status' => $coupon->provisioning_status,
                'sync_status' => $coupon->sync_status,
            ] : null,
        ];
    }

    private function authorizeKolAccess(Request $request, Kol $kol): void
    {
        $user = $request->user();
        if (! KolScope::isAllScope($user) && $kol->owner_id !== (int) $user->getAuthIdentifier()) {
            abort(403);
        }
    }

    /** @return list<array{value:int,label:string}> */
    private function usersWithPermission(string $permission): array
    {
        return User::query()->where('is_active', true)->where(function ($q) use ($permission): void {
            $q->whereHas('roles.permissions', fn ($p) => $p->where('key', $permission))
                ->orWhereHas('customPermissions', fn ($p) => $p->where('key', $permission));
        })->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => ['value' => (int) $u->id, 'label' => $u->name])->values()->all();
    }

    private function kolData(Kol $kol): array
    {
        $latestFollowers = null;
        $primaryPlatform = null;

        foreach ($kol->socialAccounts as $account) {
            if ($account->is_active) {
                if ($primaryPlatform === null) {
                    $primaryPlatform = $account->platform;
                }
                $metric = $account->latestMetric;
                if ($metric && ($latestFollowers === null || $metric->followers > $latestFollowers)) {
                    $latestFollowers = $metric->followers;
                }
            }
        }

        $affiliateAccount = $kol->relationLoaded('affiliateAccounts') ? $kol->affiliateAccounts->first() : null;

        return [
            'id' => $kol->id,
            'display_name' => $kol->display_name,
            'avatar_url' => $kol->avatar_path ? Storage::disk('public')->url($kol->avatar_path) : null,
            'avatar_source' => $kol->avatar_source,
            'avatar_updated_at' => $kol->avatar_updated_at?->toIso8601String(),
            'country_code' => $kol->country_code,
            'shipping_city' => $kol->shipping_city,
            'status' => $kol->status,
            'relationship_stage' => $kol->relationship_stage,
            'owner' => $kol->owner ? ['id' => $kol->owner->id, 'name' => $kol->owner->name] : null,
            'approval_manager_id' => $kol->approval_manager_id,
            'approval_manager' => $kol->approvalManager ? ['id' => $kol->approvalManager->id, 'name' => $kol->approvalManager->name] : null,
            'finance_owner_id' => $kol->finance_owner_id,
            'finance_owner' => $kol->financeOwner ? ['id' => $kol->financeOwner->id, 'name' => $kol->financeOwner->name] : null,
            'primary_platform' => $primaryPlatform,
            'latest_followers' => $latestFollowers,
            'is_100k_plus' => $latestFollowers !== null && $latestFollowers >= 100000,
            'joined_at' => $kol->joined_at?->format('Y-m-d'),
            'level' => $kol->level,
            'gender' => $kol->gender,
            'categories' => $kol->categories ?? [],
            'products' => $kol->products ?? [],
            'content_format' => $kol->content_format,
            'product_quantity' => $kol->product_quantity,
            'internal_contact' => $kol->internal_contact,
            'mcn_agency' => $kol->mcn_agency,
            'is_mcn_managed' => $kol->is_mcn_managed,
            'historical_order_count' => (int) ($kol->historical_order_count ?? 0),
            'has_published_content' => (bool) ($kol->has_published_content ?? false),
            'affiliate_account' => $affiliateAccount ? [
                'affiliate_id' => (int) $affiliateAccount->affiliate_id,
                'wordpress_user_id' => $affiliateAccount->wordpress_user_id !== null ? (int) $affiliateAccount->wordpress_user_id : null,
                'wordpress_user_login' => $affiliateAccount->wordpress_user_login,
            ] : null,
        ];
    }

    private function kolDetailData(Kol $kol): array
    {
        return [
            'id' => $kol->id,
            'display_name' => $kol->display_name,
            'avatar_url' => $kol->avatar_path ? Storage::disk('public')->url($kol->avatar_path) : null,
            'avatar_source' => $kol->avatar_source,
            'avatar_updated_at' => $kol->avatar_updated_at?->toIso8601String(),
            'legal_name' => $kol->legal_name,
            'country_code' => $kol->country_code,
            'language' => $kol->language,
            'status' => $kol->status,
            'relationship_stage' => $kol->relationship_stage,
            'cooperation_type' => $kol->cooperation_type,
            'owner_id' => $kol->owner_id,
            'owner' => $kol->owner ? ['id' => $kol->owner->id, 'name' => $kol->owner->name] : null,
            'joined_at' => $kol->joined_at?->format('Y-m-d'),
            'archived_at' => $kol->archived_at?->format('Y-m-d H:i:s'),
            'notes' => $kol->notes,
            'contact_email' => $kol->contact_email,
            'contact_phone' => $kol->contact_phone,
            'tax_id' => $kol->tax_id,
            'payout_method' => $kol->payout_method,
            'payout_account' => $kol->payout_account,
            'shipping_recipient_name' => $kol->shipping_recipient_name,
            'shipping_country' => $kol->shipping_country,
            'shipping_province_state' => $kol->shipping_province_state,
            'shipping_city' => $kol->shipping_city,
            'shipping_postal_code' => $kol->shipping_postal_code,
            'shipping_address_line_1' => $kol->shipping_address_line_1,
            'shipping_address_line_2' => $kol->shipping_address_line_2,
            'contact_whatsapp' => $kol->contact_whatsapp,
            'other_contact' => $kol->other_contact,
            'preferred_contact_method' => $kol->preferred_contact_method,
            'level' => $kol->level,
            'gender' => $kol->gender,
            'categories' => $kol->categories ?? [],
            'products' => $kol->products ?? [],
            'content_format' => $kol->content_format,
            'product_quantity' => $kol->product_quantity,
            'internal_contact' => $kol->internal_contact,
            'mcn_agency' => $kol->mcn_agency,
            'is_mcn_managed' => $kol->is_mcn_managed,
            'historical_order_count' => $kol->historicalOrderCount(),
            'sales_performance_history' => $kol->salesPerformances()->limit(20)->get()->map(fn ($p) => [
                'id' => $p->id,
                'order_count' => $p->order_count,
                'recorded_at' => $p->recorded_at?->format('Y-m-d'),
                'source' => $p->source,
                'note' => $p->note,
            ])->values()->all(),
            'current_opportunity' => Schema::hasTable('kol_opportunities') && ($opportunity = $kol->relationLoaded('opportunities') ? $kol->opportunities->first(fn ($item) => $item->status === 'active') : null) ? [
                'id' => $opportunity->id,
                'title' => $opportunity->title,
                'cooperation_type' => $opportunity->cooperation_type,
                'target_products' => $opportunity->target_products ?? [],
                'stage' => $opportunity->stage,
                'estimated_cost' => $opportunity->estimated_cost !== null ? (float) $opportunity->estimated_cost : null,
                'currency' => $opportunity->currency,
                'expected_publish_at' => $opportunity->expected_publish_at?->format('Y-m-d'),
                'next_follow_up_at' => $opportunity->next_follow_up_at?->format('Y-m-d H:i:s'),
                'next_action' => $opportunity->next_action,
                'owner' => $opportunity->owner ? ['id' => $opportunity->owner->id, 'name' => $opportunity->owner->name] : null,
                'status' => $opportunity->status,
            ] : null,
            'recent_communications' => Schema::hasTable('kol_communications') && $kol->relationLoaded('communications') ? $kol->communications->take(5)->map(fn ($communication) => [
                'id' => $communication->id,
                'channel' => $communication->channel,
                'direction' => $communication->direction ?? 'outbound',
                'summary' => $communication->summary,
                'contacted_at' => $communication->contacted_at?->format('Y-m-d H:i:s'),
                'next_action' => $communication->next_action,
                'next_follow_up_at' => $communication->next_follow_up_at?->format('Y-m-d H:i:s'),
                'creator' => $communication->creator ? ['id' => $communication->creator->id, 'name' => $communication->creator->name] : null,
                'opportunity' => $communication->opportunity ? ['id' => $communication->opportunity->id, 'title' => $communication->opportunity->title] : null,
            ])->values()->all() : [],
            'communications' => Schema::hasTable('kol_communications') && $kol->relationLoaded('communications') ? $kol->communications->map(fn ($communication) => [
                'id' => $communication->id,
                'opportunity_id' => $communication->opportunity_id,
                'channel' => $communication->channel,
                'direction' => $communication->direction ?? 'outbound',
                'summary' => $communication->summary,
                'contacted_at' => $communication->contacted_at?->format('Y-m-d\TH:i'),
                'next_action' => $communication->next_action,
                'next_follow_up_at' => $communication->next_follow_up_at?->format('Y-m-d\TH:i'),
                'creator' => $communication->creator ? ['id' => $communication->creator->id, 'name' => $communication->creator->name] : null,
                'opportunity' => $communication->opportunity ? ['id' => $communication->opportunity->id, 'title' => $communication->opportunity->title] : null,
            ])->values()->all() : [],
            'opportunities' => Schema::hasTable('kol_opportunities') && $kol->relationLoaded('opportunities') ? $kol->opportunities->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'cooperation_type' => $item->cooperation_type,
                'target_products' => $item->target_products ?? [],
                'stage' => $item->stage,
                'owner_id' => $item->owner_id,
                'order_id' => $item->order_id,
                'owner' => $item->owner ? ['id' => $item->owner->id, 'name' => $item->owner->name] : null,
                'estimated_cost' => $item->estimated_cost !== null ? (float) $item->estimated_cost : null,
                'currency' => $item->currency,
                'expected_publish_at' => $item->expected_publish_at?->format('Y-m-d'),
                'next_follow_up_at' => $item->next_follow_up_at?->format('Y-m-d\TH:i'),
                'next_action' => $item->next_action,
                'status' => $item->status,
                'notes' => $item->notes,
            ])->values()->all() : [],
            'collaborations' => Schema::hasTable('kol_collaborations') && $kol->relationLoaded('collaborations') ? $kol->collaborations->map(fn ($item) => [
                'id' => $item->id,
                'opportunity_id' => $item->opportunity_id,
                'owner_id' => $item->owner_id,
                'owner' => $item->owner ? ['id' => $item->owner->id, 'name' => $item->owner->name] : null,
                'title' => $item->title,
                'post_url' => $item->post_url,
                'cooperation_type' => $item->cooperation_type,
                'status' => $item->status,
                'agreement_amount' => $item->agreement_amount !== null ? (float) $item->agreement_amount : null,
                'currency' => $item->currency,
                'agreed_at' => $item->agreed_at?->format('Y-m-d'),
                'planned_publish_at' => $item->planned_publish_at?->format('Y-m-d'),
                'deliverables' => $item->deliverables,
                'fulfillment_required' => (bool) $item->fulfillment_required,
                'notes' => $item->notes,
                'products' => $item->products->map(fn ($row) => ['id' => $row->id, 'product_id' => $row->product_id, 'sku' => $row->sku_snapshot, 'name' => $row->product_name_snapshot, 'quantity' => $row->quantity])->values()->all(),
                'fulfillment_order' => $item->order ? [
                    'id' => $item->order->id,
                    'internal_order_number' => $item->order->internal_order_number,
                    'erp_order_id' => $item->order->erp_order_id,
                    'external_order_number' => $item->order->external_order_number,
                    'order_status' => $item->order->order_status instanceof \BackedEnum ? $item->order->order_status->value : (string) $item->order->order_status,
                    'shipment' => $item->order->shipment ? [
                        'shipment_status' => $item->order->shipment->shipment_status instanceof \BackedEnum ? $item->order->shipment->shipment_status->value : (string) $item->order->shipment->shipment_status,
                        'warehouse_name' => $item->order->shipment->warehouse?->name,
                        'carrier' => $item->order->shipment->carrier,
                        'service' => $item->order->shipment->service,
                        'tracking_number' => $item->order->shipment->tracking_number,
                        'shipped_at' => $item->order->shipment->shipped_at?->toIso8601String(),
                    ] : null,
                    'manual_sync_status' => $item->order->manualSync?->website_sync_status?->value,
                ] : null,
                'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
            ])->values()->all() : [],
            'contents' => Schema::hasTable('kol_contents') && $kol->relationLoaded('contents') ? $kol->contents->map(fn ($item) => [
                'id' => $item->id,
                'collaboration_id' => $item->collaboration_id,
                'platform' => $item->platform,
                'content_type' => $item->content_type,
                'publication_status' => $item->publication_status,
                'post_url' => $item->post_url,
                'planned_publish_at' => $item->planned_publish_at?->format('Y-m-d'),
                'published_at' => $item->published_at?->format('Y-m-d'),
                'evidence_classification' => $item->evidenceClassification(),
                'attachments' => $item->attachments->map(fn ($attachment) => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'url' => route('kol.contents.evidence.show', ['content' => $item->id, 'attachment' => $attachment->id], false),
                ])->values()->all(),
            ])->values()->all() : [],
            'historical_opportunities' => Schema::hasTable('kol_opportunities') && $kol->relationLoaded('opportunities') ? $kol->opportunities->filter(fn ($item) => $item->status !== 'active')->take(20)->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'cooperation_type' => $item->cooperation_type,
                'stage' => $item->stage,
                'status' => $item->status,
                'created_at' => $item->created_at?->format('Y-m-d'),
                'estimated_cost' => $item->estimated_cost !== null ? (float) $item->estimated_cost : null,
                'currency' => $item->currency,
                'owner' => $item->owner ? ['id' => $item->owner->id, 'name' => $item->owner->name] : null,
            ])->values()->all() : [],
            'social_accounts' => $kol->socialAccounts->map(fn ($sa) => [
                'id' => $sa->id,
                'platform' => $sa->platform,
                'handle' => $sa->handle,
                'profile_url' => $sa->profile_url,
                'is_active' => $sa->is_active,
                'latest_metric' => $sa->latestMetric ? [
                    'followers' => $sa->latestMetric->followers,
                    'raw_followers_value' => $sa->latestMetric->raw_followers_value,
                    'measured_at' => $sa->latestMetric->measured_at?->format('Y-m-d'),
                ] : null,
            ])->values()->all(),
        ];
    }
}
