<?php

namespace App\Modules\Logistics\Application;

final class ChinaFactoryRoutingCostCalculator
{
    /** @param array<string,mixed> $destinationContext @param array<int,array<string,mixed>> $items */
    public function calculate(string $country, float $actualWeightKg, array $items, array $destinationContext = []): array
    {
        $country = strtoupper(trim($country));
        $rates = require resource_path('data/logistics/china-factory-rates-v1.php');

        if ($country === 'US' && $this->isUnservedUsDestination($destinationContext, $rates['us_excluded_zip_prefixes'])) {
            return $this->missing('factory_channel_unserved_destination', 'US source exclusions in 仓库-物流-国内小包费用.xlsx');
        }
        $countryRate = $rates['countries'][$country] ?? null;
        if (! is_array($countryRate)) return $this->missing('factory_rate_unavailable', 'No frozen China rate row for destination country');

        // Owner-confirmed operational rule: 4PX / 速达 SD经济挂号-带电 is billed by actual weight only.
        $chargeable = ceil(max(0.0, $actualWeightKg) * 1000) / 1000;
        $chargeable = max($chargeable, (float) ($countryRate['minimum_billing_kg'] ?? 0));
        $band = null;
        foreach ($countryRate['bands'] ?? [] as $row) {
            if ($chargeable <= (float) $row['max_kg'] + 0.0000001) { $band = $row; break; }
        }
        if (! is_array($band)) return $this->missing('factory_rate_unavailable', 'Weight exceeds frozen China rate rows');

        $components = [[
            'key' => 'china_transport',
            'label' => 'China factory transport',
            'amount' => round($chargeable * (float) $band['per_kg_rmb'] + (float) $band['base_rmb'], 4),
            'currency' => 'CNY',
            'source' => $rates['source'],
            'chargeable_weight_kg' => $chargeable,
            'billing_basis' => 'actual_weight_only',
        ]];
        $lineCount = count(array_unique(array_map(fn (array $item): string => (string) ($item['product_id'] ?? ''), $items)));
        if (in_array($country, $rates['eu27_countries'], true)) {
            $components[] = [
                'key' => 'eu_declared_item_surcharge',
                'label' => 'EU declared Item surcharge',
                'amount' => round($lineCount * (float) $rates['eu27_item_surcharge_eur'], 4),
                'currency' => 'EUR',
                'source' => $rates['source'],
            ];
        }

        return [
            'status' => 'ready',
            'components' => $components,
            'provenance' => 'actual-weight-only',
            'possible_surcharges' => [],
        ];
    }

    private function isUnservedUsDestination(array $context, array $prefixes): bool
    {
        $state = strtoupper(trim((string) ($context['province_state'] ?? $context['state'] ?? '')));
        $city = strtoupper(trim((string) ($context['city'] ?? '')));
        if (in_array($state, ['AK', 'HI', 'AA', 'AE', 'AP'], true) || in_array($city, ['APO', 'FPO', 'DPO'], true)) return true;
        $postal = preg_replace('/[^0-9]/', '', (string) ($context['postal_code'] ?? $context['zip'] ?? ''));
        return $postal !== '' && in_array(substr($postal, 0, 3), $prefixes, true);
    }

    private function missing(string $reasonCode, string $reason): array
    {
        return ['status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null, 'reason_code' => $reasonCode, 'reason' => $reason, 'components' => [], 'possible_surcharges' => []];
    }
}
