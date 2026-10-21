<?php

namespace App\Modules\Logistics\Application;

final class GermanyDhlRoutingCostCalculator
{
    /** @return array<string,mixed> */
    public function calculate(string $country, float $actualWeightKg): array
    {
        $country = strtoupper(trim($country));
        $rates = require resource_path('data/logistics/germany-dhl-rates-v1.php');
        $weight = max(0.1, $actualWeightKg);
        $components = [];

        if ($country === 'DE') {
            $tierWeight = ceil($weight);
            $band = null;
            foreach ($rates['domestic_bands'] as $row) {
                if ($tierWeight <= (float) $row['max_kg']) { $band = $row; break; }
            }
            if (! is_array($band)) return $this->missing('dhl_weight_unavailable', 'Weight exceeds frozen DHL domestic rate rows');
            $components[] = ['key' => 'dhl_domestic_transport', 'label' => 'DHL domestic transport with fuel', 'amount' => round((float) $band['base_eur'] * (1 + (float) $rates['domestic_fuel_rate']), 4), 'currency' => 'EUR', 'source' => $rates['source'], 'billed_weight_kg' => $tierWeight];
        } else {
            $rate = $rates['international'][$country] ?? null;
            if (! is_array($rate)) return $this->missing('dhl_rate_unavailable', 'No frozen DHL international rate row for destination country');
            $billedWeight = ceil($weight);
            if ($billedWeight > (float) $rate[2]) return $this->missing('dhl_weight_unavailable', 'Weight exceeds frozen DHL international maximum');
            $components[] = ['key' => 'dhl_international_transport', 'label' => 'DHL international transport', 'amount' => round($billedWeight * (float) $rate[0] + (float) $rate[1], 4), 'currency' => 'EUR', 'source' => $rates['source'], 'billed_weight_kg' => $billedWeight];
        }

        $components[] = ['key' => 'dhl_owner_handling', 'label' => 'Owner routing handling', 'amount' => (float) $rates['handling_eur'], 'currency' => 'EUR', 'source' => $rates['source']];
        return ['status' => 'ready', 'components' => $components, 'provenance' => 'approved-dhl-quote', 'possible_surcharges' => ['return', 'address_correction', 'peak', 'bulky', 'irregular']];
    }

    private function missing(string $reasonCode, string $reason): array
    {
        return ['status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null, 'reason_code' => $reasonCode, 'reason' => $reason, 'components' => [], 'possible_surcharges' => []];
    }
}
