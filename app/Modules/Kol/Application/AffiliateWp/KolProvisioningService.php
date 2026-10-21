<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class KolProvisioningService
{
    private const ACCOUNT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateAccount';

    private const KOL = 'App\\Modules\\Kol\\Infrastructure\\Models\\Kol';

    /** States from which an operator retry is accepted. */
    private const RETRYABLE = ['not_requested', 'pending', 'error', 'conflict', 'readback_mismatch', 'in_progress'];

    public function __construct(private readonly AffiliateWpGateway $gateway) {}

    /**
     * Provision an approved KOL into WordPress User + active AffiliateWP Affiliate.
     *
     * Creates or reuses a stable placeholder row keyed by (connection_key, kol_id),
     * persists the idempotency key before calling the provider, then transitions to
     * active|conflict|error|readback_mismatch based on the verified read-back response.
     */
    public function provision(string $connectionKey, int $kolId, Authenticatable $actor): Model
    {
        if (! $actor->can('kol.affiliates.manage')) {
            throw new AuthorizationException;
        }

        return $this->provisionInternal($connectionKey, $kolId, $actor, null, true);
    }

    public function provisionForCreate(string $connectionKey, int $kolId, Authenticatable $actor, string $password): Model
    {
        if (! $actor->can('kol.operate')) {
            throw new AuthorizationException;
        }
        if (strlen($password) < 12 || strlen($password) > 128 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            throw new DomainException('WordPress password does not satisfy the create-provision policy.');
        }

        return $this->provisionInternal($connectionKey, $kolId, $actor, $password, true);
    }

    public function provisionForExisting(string $connectionKey, int $kolId, Authenticatable $actor, string $password): Model
    {
        if (! $actor->can('kol.operate')) {
            throw new AuthorizationException;
        }
        if (strlen($password) < 12 || strlen($password) > 128 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            throw new DomainException('WordPress password does not satisfy the provision policy.');
        }

        return $this->provisionInternal($connectionKey, $kolId, $actor, $password, false);
    }

    private function provisionInternal(string $connectionKey, int $kolId, Authenticatable $actor, ?string $password, bool $requireApproval): Model
    {
        $kolClass = self::KOL;
        $query = $kolClass::query()->whereKey($kolId)->whereNull('archived_at');
        if ($requireApproval) {
            $query->where('approval_status', 'approved');
        } else {
            $query->where('status', 'active');
        }
        $kol = $query->first();

        if (! $kol) {
            throw new DomainException($requireApproval
                ? 'KOL must be approved and active to provision.'
                : 'KOL must be active to provision a WordPress / Affiliate account.');
        }

        [$account, $idempotencyKey] = DB::transaction(function () use ($connectionKey, $kolId, $kol): array {
            $accountClass = self::ACCOUNT;

            $existing = $accountClass::query()
                ->where('connection_key', $connectionKey)
                ->where('kol_id', $kolId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->provisioning_status === 'active') {
                    throw new DomainException('KOL is already actively provisioned on this connection.');
                }
                // Reject if the row was sourced from an external sync or explicit bind — those already have
                // an authoritative affiliate_id and must not be re-provisioned.
                if ($existing->affiliate_id !== null && $existing->origin !== 'erp_provisioned') {
                    throw new DomainException('KOL already has an externally-sourced Affiliate. Use bind or sync instead of provisioning.');
                }
                if (! in_array($existing->provisioning_status, self::RETRYABLE, true)) {
                    throw new DomainException('KOL provisioning cannot be retried from state: '.$existing->provisioning_status.'.');
                }

                // Reuse the persisted idempotency key so retries never generate a second Affiliate.
                $key = $existing->provision_idempotency_key ?? Str::uuid()->toString();
                $existing->update([
                    'provision_idempotency_key' => $key,
                    'provisioning_status' => 'in_progress',
                ]);

                return [$existing, $key];
            }

            $key = Str::uuid()->toString();
            $account = $accountClass::create([
                'connection_key' => $connectionKey,
                'affiliate_id' => null,
                'kol_id' => $kolId,
                'display_name' => $kol->display_name,
                'email' => $kol->contact_email,
                'provider_status' => null,
                'origin' => 'erp_provisioned',
                'sync_status' => 'never_synced',
                'reconciliation_status' => 'present',
                'provisioning_status' => 'in_progress',
                'provision_idempotency_key' => $key,
            ]);

            return [$account, $key];
        });

        $email = trim((string) $kol->contact_email);
        $payload = [
            'erp_kol_id' => $kolId,
            'email' => $email,
            'display_name' => (string) $kol->display_name,
            'preferred_username' => 'erp-kol-'.$kolId,
            'requested_affiliate_status' => 'active',
            'payment_email' => $email,
        ];
        if ($password !== null) {
            $payload['password'] = $password;
            $payload['existing_account_policy'] = 'reject';
        }

        try {
            $response = $this->gateway->provisionAffiliate($payload, $idempotencyKey);
            unset($payload['password'], $password);
        } catch (RuntimeException $e) {
            // Detect provider-signalled Affiliate ID conflict without importing Infrastructure.
            $errorCode = property_exists($e, 'errorCode') && is_string($e->errorCode)
                ? $e->errorCode
                : 'CONNECTOR_ERROR';
            $finalStatus = $errorCode === 'AFFILIATE_ID_CONFLICT' ? 'conflict' : 'error';

            return $this->finalizeWithError($account, $finalStatus, $errorCode, $e->getMessage());
        }

        return $this->finalizeWithSuccess($account, $response->data);
    }

    private function finalizeWithSuccess(Model $account, mixed $data): Model
    {
        $affiliateId = $this->extractPositiveInt($data, ['affiliate_id']);
        $wordpressUserId = $this->extractPositiveInt($data, ['wp_user_id', 'user_id', 'wordpress_user_id']);
        $wordpressUserEmail = is_array($data)
            ? ($data['user_email'] ?? $data['wordpress_user_email'] ?? null)
            : null;
        $wordpressUserEmail = is_string($wordpressUserEmail) && $wordpressUserEmail !== '' ? $wordpressUserEmail : null;
        $status = is_array($data) ? ($data['affiliate_status'] ?? $data['status'] ?? null) : null;

        $verified = $affiliateId !== null && $wordpressUserId !== null && $status === 'active';

        if ($verified) {
            try {
                $account->update([
                    'affiliate_id' => $affiliateId,
                    'wordpress_user_id' => $wordpressUserId,
                    'wordpress_user_email' => $wordpressUserEmail,
                    'provider_status' => 'active',
                    'provisioning_status' => 'active',
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);
            } catch (QueryException) {
                // Unique (connection_key, affiliate_id) conflict: a mirror row already claims this ID.
                return $this->finalizeWithError(
                    $account,
                    'conflict',
                    'AFFILIATE_ID_CONFLICT',
                    'Provider affiliate_id conflicts with an existing ERP mirror row.',
                );
            }
        } else {
            $account->update([
                'affiliate_id' => $affiliateId,
                'wordpress_user_id' => $wordpressUserId,
                'wordpress_user_email' => $wordpressUserEmail,
                'provider_status' => is_string($status) ? $status : null,
                'provisioning_status' => 'readback_mismatch',
                'last_error_code' => 'READBACK_MISMATCH',
                'last_error_message' => 'Provider response did not confirm active affiliate_id + user_id + status=active.',
            ]);
        }

        return $account->fresh();
    }

    private function finalizeWithError(Model $account, string $status, string $code, string $message): Model
    {
        $account->update([
            'provisioning_status' => $status,
            'last_error_code' => $code,
            'last_error_message' => mb_substr($message, 0, 500),
        ]);

        return $account->fresh();
    }

    private function extractPositiveInt(mixed $data, array $keys): ?int
    {
        if (! is_array($data)) {
            return null;
        }
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $val = $data[$key];
            if (is_int($val) && $val > 0) {
                return $val;
            }
            if (is_string($val) && ctype_digit($val) && (int) $val > 0) {
                return (int) $val;
            }
        }

        return null;
    }
}
