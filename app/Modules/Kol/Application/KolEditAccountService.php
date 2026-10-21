<?php

namespace App\Modules\Kol\Application;

use App\Modules\Kol\Application\AffiliateWp\CouponProvisioningService;
use App\Modules\Kol\Application\AffiliateWp\KolProvisioningService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateCoupon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class KolEditAccountService
{
    public function __construct(
        private readonly KolProvisioningService $affiliates,
        private readonly CouponProvisioningService $coupons,
        private readonly AffiliateWpGateway $gateway,
    ) {}

    /** @return array{success:bool,affiliate_status:?string,coupon_status:?string} */
    public function provision(Kol $kol, string $password, string $couponCode, Authenticatable $actor): array
    {
        if (! KolScope::canOperate($actor)) {
            throw new AuthorizationException('KOL operation permission is required.');
        }

        $connectionKey = $this->connectionKey();
        $existing = KolAffiliateAccount::query()
            ->where('connection_key', $connectionKey)
            ->where('kol_id', $kol->getKey())
            ->first();

        if ($existing && $existing->affiliate_id !== null) {
            throw ValidationException::withMessages([
                'account' => '该 KOL 已绑定 WordPress / AffiliateWP 账号，不能再次开户。',
            ]);
        }

        if (! $existing) {
            $this->preflight($kol, $connectionKey, $couponCode);
        }

        try {
            $affiliate = $this->affiliates->provisionForExisting(
                $connectionKey,
                (int) $kol->getKey(),
                $actor,
                $password,
            );
        } catch (DomainException|RuntimeException $exception) {
            unset($password);
            report($exception);
            throw ValidationException::withMessages([
                'account' => 'WordPress / Affiliate 账号创建未完成，请检查 KOL 状态并重试。',
            ]);
        }
        unset($password);

        if ($affiliate->provisioning_status !== 'active') {
            return [
                'success' => false,
                'affiliate_status' => $affiliate->provisioning_status,
                'coupon_status' => null,
            ];
        }

        $policy = $this->couponPolicy();
        try {
            $coupon = $this->coupons->provisionForCreate($connectionKey, [
                'kol_id' => (int) $kol->getKey(),
                'code' => trim($couponCode),
                'discount_type' => $policy['discount_type'],
                'amount' => $policy['amount'],
                'expiry' => $policy['expiry'],
            ], $actor);
        } catch (DomainException|RuntimeException $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'account' => 'Affiliate Coupon 创建未完成；WordPress / Affiliate 账号状态已保留，请检查后重试。',
            ]);
        }

        return [
            'success' => $coupon->provisioning_status === 'active',
            'affiliate_status' => $affiliate->provisioning_status,
            'coupon_status' => $coupon->provisioning_status,
        ];
    }

    public function updatePassword(Kol $kol, string $password, Authenticatable $actor): void
    {
        if (! KolScope::canOperate($actor)) {
            throw new AuthorizationException('KOL operation permission is required.');
        }

        $connectionKey = $this->connectionKey();
        $account = KolAffiliateAccount::query()
            ->where('connection_key', $connectionKey)
            ->where('kol_id', $kol->getKey())
            ->first();

        $wordpressUserId = (int) ($account?->wordpress_user_id ?? 0);
        $affiliateId = (int) ($account?->affiliate_id ?? 0);
        if (! $account || $wordpressUserId <= 0 || $affiliateId <= 0) {
            throw ValidationException::withMessages([
                'account' => '该 KOL 尚未绑定可验证的 WordPress / AffiliateWP 账号。',
            ]);
        }

        $payload = [
            'erp_kol_id' => (int) $kol->getKey(),
            'affiliate_id' => $affiliateId,
            'password' => $password,
        ];
        try {
            $response = $this->gateway->updateWordpressPassword(
                $wordpressUserId,
                $payload,
                Str::uuid()->toString(),
            );
        } catch (RuntimeException $exception) {
            unset($payload['password'], $password);
            report($exception);
            throw ValidationException::withMessages([
                'account' => 'WordPress 密码更新失败；账号绑定未修改，请稍后重试。',
            ]);
        }
        unset($payload['password'], $password);

        $data = is_array($response->data) ? $response->data : [];
        if (($data['password_updated'] ?? null) !== true
            || (int) ($data['wordpress_user_id'] ?? 0) !== $wordpressUserId
            || (int) ($data['affiliate_id'] ?? 0) !== $affiliateId) {
            throw ValidationException::withMessages([
                'account' => 'WordPress 密码更新 read-back 与当前绑定不一致，系统未确认成功。',
            ]);
        }
    }

    private function preflight(Kol $kol, string $connectionKey, string $couponCode): void
    {
        $email = mb_strtolower(trim((string) $kol->contact_email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'account' => '请先在 KOL 资料中保存有效联系邮箱，再创建 WordPress / Affiliate 账号。',
            ]);
        }

        if (Kol::query()->whereKeyNot($kol->getKey())
            ->whereRaw('LOWER(TRIM(contact_email)) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['account' => '该邮箱已经属于其他 ERP KOL。']);
        }
        if (KolAffiliateAccount::query()->where('connection_key', $connectionKey)
            ->where('kol_id', '!=', $kol->getKey())
            ->where(fn ($q) => $q->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->orWhereRaw('LOWER(TRIM(wordpress_user_email)) = ?', [$email]))
            ->exists()) {
            throw ValidationException::withMessages(['account' => '该邮箱已经存在于其他 KOL 的 Affiliate mirror，自动建号已阻止。']);
        }

        try {
            $identity = $this->gateway->checkAffiliateIdentity($email);
        } catch (RuntimeException $exception) {
            report($exception);
            throw ValidationException::withMessages(['account' => '无法完成 WordPress / AffiliateWP 实时邮箱检查，请稍后重试。']);
        }
        $identityData = is_array($identity->data) ? $identity->data : [];
        if (($identityData['available'] ?? null) !== true) {
            throw ValidationException::withMessages(['account' => '该邮箱已存在 WordPress / AffiliateWP 账户，自动建号已阻止。']);
        }

        $code = trim($couponCode);
        $normalizedCode = mb_strtolower($code);
        if ($code === '' || mb_strlen($code) > 255) {
            throw ValidationException::withMessages(['affiliate_coupon_code' => 'Code 必填且不能超过 255 个字符。']);
        }
        if (KolAffiliateCoupon::query()->where('connection_key', $connectionKey)
            ->where('normalized_code', $normalizedCode)->exists()) {
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
            || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $amount)
            || ($type === 'percent' && (float) $amount > 100)) {
            throw ValidationException::withMessages(['affiliate_coupon_code' => 'KOL 自动 Coupon 策略尚未正确配置，请联系管理员。']);
        }

        return [
            'discount_type' => $type,
            'amount' => $amount,
            'expiry' => is_string($expiry) && trim($expiry) !== '' ? $expiry : null,
        ];
    }

    private function connectionKey(): string
    {
        $connectionKey = trim((string) ($this->gateway->descriptor()['connection_key'] ?? ''));
        if ($connectionKey === '') {
            throw ValidationException::withMessages([
                'account' => 'WordPress Connector 尚未配置，无法执行账号操作。',
            ]);
        }

        return $connectionKey;
    }
}
