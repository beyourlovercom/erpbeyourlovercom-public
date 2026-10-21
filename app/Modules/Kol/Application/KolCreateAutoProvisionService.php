<?php

namespace App\Modules\Kol\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\AffiliateWp\CouponProvisioningService;
use App\Modules\Kol\Application\AffiliateWp\KolProvisioningService;
use App\Modules\Kol\Application\Approval\KolApprovalService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateCoupon;
use App\Modules\NotificationCenter\Application\NotificationPublisher;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class KolCreateAutoProvisionService
{
    public function __construct(
        private readonly KolService $kols,
        private readonly KolApprovalService $approvals,
        private readonly KolProvisioningService $affiliates,
        private readonly CouponProvisioningService $coupons,
        private readonly AffiliateWpGateway $gateway,
        private readonly NotificationPublisher $notifications,
    ) {}

    /** @return array{kol:Kol,success:bool,affiliate_status:?string,coupon_status:?string} */
    public function create(
        array $data,
        array $socialAccounts,
        array $salesPerformanceEntries,
        string $password,
        string $couponCode,
        Authenticatable $actor,
    ): array {
        if (! KolScope::canOperate($actor)) {
            throw new AuthorizationException('KOL operation permission is required.');
        }

        $email = mb_strtolower(trim((string) ($data['contact_email'] ?? '')));
        $code = trim($couponCode);
        $connectionKey = (string) ($this->gateway->descriptor()['connection_key'] ?? '');
        if ($connectionKey === '') {
            throw ValidationException::withMessages(['contact_email' => 'WordPress Connector 尚未配置，无法完成自动建号。']);
        }

        $couponPolicy = $this->couponPolicy();
        $this->preflight($connectionKey, $email, $code);

        /** @var Kol $kol */
        $kol = $this->kols->create($data, $socialAccounts, $actor, $salesPerformanceEntries, false);
        $this->approvals->approveVerifiedCreate($kol, $actor);

        $affiliate = null;
        $coupon = null;
        try {
            $affiliate = $this->affiliates->provisionForCreate($connectionKey, (int) $kol->getKey(), $actor, $password);
            unset($password);

            if ($affiliate->provisioning_status !== 'active') {
                return $this->needsAttention(
                    $kol,
                    $actor,
                    'affiliate_'.$affiliate->provisioning_status,
                    $affiliate->provisioning_status,
                    null,
                );
            }

            $coupon = $this->coupons->provisionForCreate($connectionKey, [
                'kol_id' => (int) $kol->getKey(),
                'code' => $code,
                'discount_type' => $couponPolicy['discount_type'],
                'amount' => $couponPolicy['amount'],
                'expiry' => $couponPolicy['expiry'],
            ], $actor);

            if ($coupon->provisioning_status !== 'active') {
                return $this->needsAttention(
                    $kol,
                    $actor,
                    'coupon_'.$coupon->provisioning_status,
                    'active',
                    $coupon->provisioning_status,
                );
            }
        } catch (DomainException|RuntimeException $exception) {
            unset($password);
            report($exception);

            return $this->needsAttention(
                $kol,
                $actor,
                'provider_provision_failed',
                $affiliate?->provisioning_status,
                $coupon?->provisioning_status,
            );
        }

        $this->notifySuccess($kol, $actor, $affiliate, $coupon);

        return [
            'kol' => $kol->fresh(),
            'success' => true,
            'affiliate_status' => $affiliate->provisioning_status,
            'coupon_status' => $coupon->provisioning_status,
        ];
    }

    private function preflight(string $connectionKey, string $email, string $code): void
    {
        if (Kol::query()->whereRaw('LOWER(TRIM(contact_email)) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['contact_email' => '该邮箱已经属于现有 ERP KOL。']);
        }
        if (KolAffiliateAccount::query()->where('connection_key', $connectionKey)
            ->where(fn ($q) => $q->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->orWhereRaw('LOWER(TRIM(wordpress_user_email)) = ?', [$email]))
            ->exists()) {
            throw ValidationException::withMessages(['contact_email' => '该邮箱已经存在于 ERP Affiliate mirror，自动建号已阻止。']);
        }

        try {
            $identity = $this->gateway->checkAffiliateIdentity($email);
        } catch (RuntimeException $exception) {
            report($exception);
            throw ValidationException::withMessages(['contact_email' => '无法完成 WordPress / AffiliateWP 实时邮箱检查，请稍后重试。']);
        }
        $identityData = is_array($identity->data) ? $identity->data : [];
        if (($identityData['available'] ?? null) !== true) {
            throw ValidationException::withMessages(['contact_email' => '该邮箱已存在 WordPress / AffiliateWP 账户，自动建号已阻止。']);
        }

        $normalizedCode = mb_strtolower(trim($code));
        if (KolAffiliateCoupon::query()->where('connection_key', $connectionKey)->where('normalized_code', $normalizedCode)->exists()) {
            throw ValidationException::withMessages(['affiliate_coupon_code' => '该 Code 已存在于 ERP Coupon mirror。']);
        }

        try {
            $couponCheck = $this->gateway->checkCoupon($code);
        } catch (RuntimeException $exception) {
            report($exception);
            throw ValidationException::withMessages(['affiliate_coupon_code' => '无法完成 WooCommerce 实时 Code 检查，请稍后重试。']);
        }
        $couponData = is_array($couponCheck->data) ? $couponCheck->data : [];
        if (($couponData['available'] ?? null) !== true) {
            throw ValidationException::withMessages(['affiliate_coupon_code' => '该 Code 已存在 WordPress / WooCommerce，自动创建已阻止。']);
        }
    }

    /** @return array{discount_type:string,amount:string,expiry:?string} */
    private function couponPolicy(): array
    {
        $type = (string) config('kol.create_auto_provision.coupon_discount_type', 'percent');
        $amount = trim((string) config('kol.create_auto_provision.coupon_amount', ''));
        $expiry = config('kol.create_auto_provision.coupon_expiry');
        if (! in_array($type, ['percent', 'fixed_cart', 'fixed_product'], true)
            || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $amount)) {
            throw ValidationException::withMessages(['affiliate_coupon_code' => 'KOL 自动 Coupon 策略尚未配置，请联系管理员。']);
        }
        if ($type === 'percent' && (float) $amount > 100) {
            throw ValidationException::withMessages(['affiliate_coupon_code' => 'KOL 自动 Coupon 百分比配置无效。']);
        }

        return [
            'discount_type' => $type,
            'amount' => $amount,
            'expiry' => is_string($expiry) && trim($expiry) !== '' ? $expiry : null,
        ];
    }

    /** @return array{kol:Kol,success:bool,affiliate_status:?string,coupon_status:?string} */
    private function needsAttention(
        Kol $kol,
        Authenticatable $actor,
        string $reasonCode,
        ?string $affiliateStatus,
        ?string $couponStatus,
    ): array {
        $this->approvals->markCreateNeedsAttention($kol, $actor, $reasonCode);
        $kol->refresh();

        $payload = [
            'category' => 'kol',
            'title' => 'KOL 自动开通需要处理',
            'message' => $kol->display_name.' 已建立 KOL 记录，但 WordPress / Affiliate / Coupon 自动开通未完整通过 read-back。',
            'action_url' => '/kol/library/'.$kol->getKey(),
            'entity_type' => 'kol',
            'entity_id' => $kol->getKey(),
            'actor_name' => $actor instanceof User ? $actor->name : null,
            'related_links' => [[
                'label' => 'KOL',
                'value' => '#'.$kol->getKey(),
                'url' => '/kol/library/'.$kol->getKey(),
            ]],
        ];

        if ($actor instanceof User) {
            $this->notifications->publishToUser($actor, [...$payload, 'required_permission' => 'kol.operate']);
        }
        $this->notifications->publishToPermission('kol.affiliates.manage', $payload, (int) $actor->getAuthIdentifier());

        return [
            'kol' => $kol,
            'success' => false,
            'affiliate_status' => $affiliateStatus,
            'coupon_status' => $couponStatus,
        ];
    }

    private function notifySuccess(Kol $kol, Authenticatable $actor, object $affiliate, object $coupon): void
    {
        if (! $actor instanceof User) {
            return;
        }

        $this->notifications->publishToUser($actor, [
            'category' => 'kol',
            'title' => 'KOL 自动开通完成',
            'message' => $kol->display_name.' 已创建并自动通过审批，WordPress / AffiliateWP / Coupon 均已完成 read-back。',
            'action_url' => '/kol/library/'.$kol->getKey(),
            'required_permission' => 'kol.operate',
            'entity_type' => 'kol',
            'entity_id' => $kol->getKey(),
            'actor_name' => $actor->name,
            'related_links' => [[
                'label' => 'KOL',
                'value' => '#'.$kol->getKey(),
                'url' => '/kol/library/'.$kol->getKey(),
            ]],
        ]);
    }
}
