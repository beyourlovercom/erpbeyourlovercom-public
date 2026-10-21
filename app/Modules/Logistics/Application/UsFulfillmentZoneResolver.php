<?php

namespace App\Modules\Logistics\Application;

final class UsFulfillmentZoneResolver
{
    /** @return array<string,mixed> */
    public function resolve(?string $postalCode, array $destinationContext = []): array
    {
        $rule = config('warehouse_routing.countries.US.rules.physical_zone', []);
        $postal = trim((string) $postalCode);
        $state = strtoupper(trim((string) ($destinationContext['province_state'] ?? $destinationContext['state'] ?? '')));

        if (! preg_match('/^(\d{5})(?:-\d{4})?$/D', $postal, $match)) {
            return $this->result('invalid_or_missing_zip', null, null, $postal !== '' ? $postal : null, $state, []);
        }
        $zip3 = (int) substr($match[1], 0, 3);
        $zipZone = $this->zoneForZip3($zip3, $rule);
        if ($zipZone === null) {
            return $this->result('zip_not_mapped', null, $zip3, $match[1], $state !== '' ? $state : null, []);
        }

        // ZIP is the canonical physical-zone signal. State is optional and only
        // cross-checks the ZIP when present; a conflicting state fails closed.
        if ($state !== '') {
            $stateZone = $this->zoneForState($state, $rule);
            if ($stateZone === null) {
                return $this->result('unsupported_state', null, $zip3, $match[1], $state, []);
            }
            if ($zipZone !== $stateZone) {
                return $this->result('state_zip_zone_conflict', null, $zip3, $match[1], $state, []);
            }
        }

        $zoneRule = is_array($rule[$zipZone] ?? null) ? $rule[$zipZone] : [];
        return $this->result('matched', $zipZone, $zip3, $match[1], $state !== '' ? $state : null, $zoneRule);
    }

    /** @return list<string> */
    public function priorityFor(array $resolution): array
    {
        return collect($resolution['warehouse_priority'] ?? [])
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter()->unique()->values()->all();
    }

    private function zoneForZip3(int $zip3, array $rule): ?string
    {
        foreach (['west', 'east'] as $zone) {
            foreach ((array) ($rule[$zone]['zip3_ranges'] ?? []) as $range) {
                if (! is_array($range) || count($range) < 2) continue;
                if ($zip3 >= (int) $range[0] && $zip3 <= (int) $range[1]) return $zone;
            }
        }
        return null;
    }

    private function zoneForState(string $state, array $rule): ?string
    {
        foreach (['west', 'east'] as $zone) {
            $states = array_map(fn ($value): string => strtoupper(trim((string) $value)), (array) ($rule[$zone]['state_codes'] ?? []));
            if (in_array($state, $states, true)) return $zone;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function result(string $status, ?string $zone, ?int $zip3, ?string $postal, ?string $state, array $zoneRule): array
    {
        return [
            'status' => $status,
            'zone' => $zone,
            'zone_label' => $zoneRule['label'] ?? null,
            'postal_code' => $postal,
            'zip3' => $zip3,
            'state' => $state,
            'warehouse_priority' => array_values((array) ($zoneRule['warehouse_priority'] ?? [])),
        ];
    }
}
