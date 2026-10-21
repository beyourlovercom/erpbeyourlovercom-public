<?php

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\Contracts\FxManualRateRepository;
use App\Modules\Finance\Infrastructure\Models\FxManualRate;
use Illuminate\Support\Facades\DB;

final class EloquentFxManualRateRepository implements FxManualRateRepository
{
    public function all(): array
    {
        return FxManualRate::query()
            ->get(['currency', 'manual_rate_to_cny', 'use_auto'])
            ->mapWithKeys(fn (FxManualRate $row): array => [
                $row->currency => [
                    'rate' => (float) $row->manual_rate_to_cny,
                    'automatic' => (bool) $row->use_auto,
                ],
            ])
            ->all();
    }

    public function replace(array $rates, array $automatic, ?int $updatedBy): void
    {
        DB::transaction(function () use ($rates, $automatic, $updatedBy): void {
            foreach ($rates as $currency => $rate) {
                $currency = strtoupper($currency);
                $useAuto = $automatic[$currency] ?? true;

                if ($rate === null) {
                    FxManualRate::query()->whereKey($currency)->delete();
                    continue;
                }

                FxManualRate::query()->updateOrCreate(
                    ['currency' => $currency],
                    [
                        'manual_rate_to_cny' => $rate,
                        'use_auto' => $useAuto,
                        'updated_by' => $updatedBy,
                    ],
                );
            }
        });
    }
}
