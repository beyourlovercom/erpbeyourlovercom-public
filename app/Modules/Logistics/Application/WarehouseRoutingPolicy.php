<?php

namespace App\Modules\Logistics\Application;

final class WarehouseRoutingPolicy
{
    /** @return array<string,mixed>|null */
    public function country(string $country): ?array
    {
        $code = strtoupper(trim($country));
        $policy = config("warehouse_routing.countries.{$code}");
        return is_array($policy) ? $policy : null;
    }

    /** @return list<string> */
    public function automaticCountries(): array
    {
        return collect(config('warehouse_routing.countries', []))
            ->filter(fn ($policy): bool => is_array($policy) && (bool) ($policy['automatic'] ?? false))
            ->keys()->map(fn ($country): string => strtoupper(trim((string) $country)))
            ->filter()->values()->all();
    }

    public function automaticEnabled(string $country): bool
    {
        return (bool) ($this->country($country)['automatic'] ?? false);
    }

    public function strategy(string $country): ?string
    {
        $strategy = $this->country($country)['strategy'] ?? null;
        return is_string($strategy) && $strategy !== '' ? $strategy : null;
    }

    /** @return list<string> */
    public function warehouseCodes(string $country): array
    {
        return collect($this->country($country)['warehouses'] ?? [])
            ->map(fn ($warehouse): string => strtoupper(trim((string) (is_array($warehouse) ? ($warehouse['code'] ?? '') : $warehouse))))
            ->filter()->unique()->values()->all();
    }

    public function fixedWarehouseCode(string $country): ?string
    {
        if ($this->strategy($country) !== 'fixed') return null;
        $codes = $this->warehouseCodes($country);
        return count($codes) === 1 ? $codes[0] : null;
    }

    public function canonicalWarehouseCode(string $warehouse): ?string
    {
        $needle = trim($warehouse);
        if ($needle === '') return null;
        foreach (config('warehouse_routing.aliases', []) as $canonical => $aliases) {
            foreach ((array) $aliases as $alias) {
                if (strcasecmp($needle, trim((string) $alias)) === 0) return strtoupper(trim((string) $canonical));
            }
        }
        return strtoupper($needle);
    }

    /** @return array<string,mixed>|null */
    public function eastZoneRule(string $country): ?array
    {
        $rule = $this->country($country)['rules']['east_zone'] ?? null;
        return is_array($rule) ? $rule : null;
    }

    /** @return array<string,mixed>|null */
    public function physicalZoneRule(string $country): ?array
    {
        $rule = $this->country($country)['rules']['physical_zone'] ?? null;
        return is_array($rule) ? $rule : null;
    }

    /** @return list<string> */
    public function fallbackPriority(string $country): array
    {
        return collect($this->country($country)['rules']['fallback_priority'] ?? [])
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter()->unique()->values()->all();
    }

    /** @return array<string,mixed>|null */
    public function deCostGate(string $country): ?array
    {
        if ($this->strategy($country) !== 'de_cost_gate') return null;
        $rule = $this->country($country)['rules']['cost_gate'] ?? null;
        return is_array($rule) ? $rule : null;
    }

    public function blockedReason(string $country): ?string
    {
        if ($this->strategy($country) !== 'blocked') return null;
        $reason = $this->country($country)['reason'] ?? null;
        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    public function splitShipmentAllowed(): bool
    {
        return (bool) config('warehouse_routing.global.allow_split_shipment', false);
    }

    public function automaticSplitShipmentEnabled(): bool
    {
        return (bool) config('warehouse_routing.global.automatic_split_shipment', false);
    }

    public function splitExecutionMode(): string
    {
        return (string) config('warehouse_routing.global.split_execution_mode', 'human_review_only');
    }

    /** @return array<string,mixed> */
    public function factoryFallback(): array
    {
        $policy = config('warehouse_routing.factory_fallback', []);
        return is_array($policy) ? $policy : [];
    }

    public function factoryAllowed(array $destinationContext = []): bool
    {
        if (! (bool) ($this->factoryFallback()['enabled'] ?? false)) return false;
        return ! $this->isUsMilitaryDestination($destinationContext);
    }

    /** @return array<string,mixed> */
    public function usMilitaryRule(): array
    {
        $rule = config('warehouse_routing.countries.US.rules.destination_constraints.military', []);
        return is_array($rule) ? $rule : [];
    }

    /** @return array<string,mixed> */
    public function usAkHiRule(): array
    {
        $rule = config('warehouse_routing.countries.US.rules.destination_constraints.ak_hi', []);
        return is_array($rule) ? $rule : [];
    }

    public function isUsMilitaryDestination(array $destinationContext): bool
    {
        $rule = $this->usMilitaryRule();
        $city = strtoupper(trim((string) ($destinationContext['city'] ?? '')));
        $state = strtoupper(trim((string) ($destinationContext['province_state'] ?? $destinationContext['state'] ?? '')));
        $cities = array_map(fn ($v): string => strtoupper(trim((string) $v)), (array) ($rule['city_codes'] ?? []));
        $states = array_map(fn ($v): string => strtoupper(trim((string) $v)), (array) ($rule['state_codes'] ?? []));
        return ($city !== '' && in_array($city, $cities, true)) || ($state !== '' && in_array($state, $states, true));
    }

    public function isUsAkHiDestination(array $destinationContext): bool
    {
        if ($this->isUsMilitaryDestination($destinationContext)) return false;
        $state = strtoupper(trim((string) ($destinationContext['province_state'] ?? $destinationContext['state'] ?? '')));
        $states = array_map(fn ($v): string => strtoupper(trim((string) $v)), (array) ($this->usAkHiRule()['state_codes'] ?? []));
        return $state !== '' && in_array($state, $states, true);
    }
}
