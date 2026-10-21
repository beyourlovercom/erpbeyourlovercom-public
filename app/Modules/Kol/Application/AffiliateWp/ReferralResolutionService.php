<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;

final class ReferralResolutionService
{
    public function resolve(string $connectionKey, int $affiliateId): int
    {
        $account = KolAffiliateAccount::query()->where('connection_key', $connectionKey)->where('affiliate_id', $affiliateId)->first();
        $query = KolAffiliateReferral::query()->where('connection_key', $connectionKey)->where('affiliate_id', $affiliateId);
        if ($account?->kol_id !== null) return $query->update(['kol_id' => $account->kol_id, 'affiliate_resolution_status' => 'bound']);
        return $query->update(['kol_id' => null, 'affiliate_resolution_status' => $account ? 'unbound' : 'missing']);
    }
}
