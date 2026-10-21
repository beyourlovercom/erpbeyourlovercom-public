<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Application\KolService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class AffiliateBindingService
{
    private const ACCOUNT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateAccount';

    private const KOL = 'App\\Modules\\Kol\\Infrastructure\\Models\\Kol';

    private const USER = 'App\\Modules\\Identity\\Infrastructure\\Models\\User';

    public function __construct(
        private readonly KolService $kols,
        private readonly AuditRecorder $audit,
        private readonly ReferralResolutionService $referrals,
    ) {}

    public function bind(string $connectionKey, int $accountId, int $kolId, Authenticatable $actor): Model
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($connectionKey, $accountId, $kolId, $actor): Model {
            $account = $this->lockedAccount($connectionKey, $accountId);
            $kolClass = self::KOL;
            $kol = $kolClass::query()->whereKey($kolId)->whereNull('archived_at')->lockForUpdate()->first();
            if (! $kol) {
                throw new DomainException('KOL is unavailable for Affiliate binding.');
            }

            if ($account->kol_id === $kolId) {
                $this->referrals->resolve($connectionKey, (int) $account->affiliate_id);
                return $account->fresh(['kol']);
            }
            if ($account->kol_id !== null) {
                throw new DomainException('Affiliate is already bound to another KOL.');
            }

            $accountClass = self::ACCOUNT;
            $conflict = $accountClass::query()
                ->where('connection_key', $connectionKey)
                ->where('kol_id', $kolId)
                ->whereKeyNot($accountId)
                ->exists();
            if ($conflict) {
                throw new DomainException('KOL is already bound to another Affiliate for this connection.');
            }

            $before = ['kol_id' => null, 'origin' => $account->origin, 'provider_status' => $account->provider_status];
            $account->update(['kol_id' => $kolId]);
            $this->record($actor, 'kol.affiliate.bound', $account, $before, [
                'kol_id' => $kolId,
                'origin' => $account->origin,
                'provider_status' => $account->provider_status,
            ]);
            $this->referrals->resolve($connectionKey, (int) $account->affiliate_id);

            return $account->fresh(['kol']);
        });
    }

    /** @return array{affiliate:Model, kol:Model} */
    public function convert(
        string $connectionKey,
        int $accountId,
        int $ownerId,
        ?string $displayName,
        Authenticatable $actor,
    ): array {
        $this->authorize($actor);

        return DB::transaction(function () use ($connectionKey, $accountId, $ownerId, $displayName, $actor): array {
            $account = $this->lockedAccount($connectionKey, $accountId);
            if ($account->kol_id !== null) {
                throw new DomainException('Affiliate is already bound and cannot be converted again.');
            }

            $userClass = self::USER;
            $owner = $userClass::query()->whereKey($ownerId)->where('is_active', true)->lockForUpdate()->first();
            if (! $owner || ! $owner->can('kol.view')) {
                throw new DomainException('Selected KOL owner is unavailable.');
            }

            $name = trim((string) $displayName);
            if ($name === '') {
                $name = trim((string) ($account->display_name ?: $account->email ?: 'Affiliate #'.$account->affiliate_id));
            }
            if ($name === '' || mb_strlen($name) > 255) {
                throw new DomainException('Converted KOL display name is invalid.');
            }

            $kol = $this->kols->create([
                'display_name' => $name,
                'status' => 'active',
                'relationship_stage' => 'prospect',
                'owner_id' => $ownerId,
                'contact_email' => $account->email,
            ], [], $actor);

            $before = ['kol_id' => null, 'origin' => $account->origin, 'provider_status' => $account->provider_status];
            $account->update([
                'kol_id' => $kol->getKey(),
                'origin' => 'erp_conversion',
            ]);
            $this->record($actor, 'kol.affiliate.converted', $account, $before, [
                'kol_id' => $kol->getKey(),
                'origin' => 'erp_conversion',
                'provider_status' => $account->provider_status,
            ]);
            $this->referrals->resolve($connectionKey, (int) $account->affiliate_id);

            return ['affiliate' => $account->fresh(['kol']), 'kol' => $kol->fresh()];
        });
    }

    private function lockedAccount(string $connectionKey, int $accountId): Model
    {
        $accountClass = self::ACCOUNT;
        $account = $accountClass::query()
            ->whereKey($accountId)
            ->where('connection_key', $connectionKey)
            ->lockForUpdate()
            ->first();

        if (! $account || $account->affiliate_id === null) {
            throw new DomainException('Authoritative Affiliate is unavailable for this operation.');
        }

        return $account;
    }

    private function authorize(Authenticatable $actor): void
    {
        if (! $actor->can('kol.affiliates.manage')) {
            throw new AuthorizationException;
        }
    }

    private function record(Authenticatable $actor, string $action, Model $account, array $before, array $after): void
    {
        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            $action,
            get_class($account),
            $account->getKey(),
            before: $before,
            after: $after,
        );
    }
}
