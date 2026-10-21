<?php

namespace App\Modules\Kol\Infrastructure;

use App\Modules\Kol\Application\Contracts\AffiliateCommissionEvidenceReader;

final class EloquentAffiliateCommissionEvidenceReader implements AffiliateCommissionEvidenceReader
{
    public function read(int|string $orderId): array
    {
        $referralClass = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateReferral';

        return $referralClass::query()->where('order_id', $orderId)->orderBy('id')->get()->map(fn ($row): array => [
            'id' => (int) $row->getKey(),
            'referral_id' => (int) $row->referral_id,
            'commission_amount' => $row->commission_amount,
            'currency' => $row->currency,
            'provider_status' => $row->provider_status,
            'order_resolution_status' => $row->order_resolution_status,
            'reconciliation_status' => $row->reconciliation_status,
            'referral_date' => $row->referral_date,
            'last_synced_at' => $row->last_synced_at,
            'updated_at' => $row->updated_at,
        ])->all();
    }
}
