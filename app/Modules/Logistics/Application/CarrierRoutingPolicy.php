<?php

namespace App\Modules\Logistics\Application;

final class CarrierRoutingPolicy
{
    public function __construct(private readonly CarrierServiceCatalog $services) {}

    public function displayName(string $carrier): string
    {
        return $this->services->carrierDisplayName(trim($carrier));
    }

    /** @return array<string,mixed> */
    public function factoryRoute(?string $country): array
    {
        $country = strtoupper(trim((string) $country));
        $default = config('carrier_routing.factory_fallback', []);
        $override = $country !== '' ? config("carrier_routing.factory_country_overrides.{$country}") : null;
        if (! is_array($default)) $default = [];
        $route = is_array($override) ? array_replace($default, $override) : $default;
        return $this->normalizeRoute($route, $country);
    }

    /** @return array<string,mixed> */
    public function warehouseRoute(?string $country, string $warehouseCode): array
    {
        $country = strtoupper(trim((string) $country));
        $warehouseCode = strtoupper(trim($warehouseCode));
        if ($warehouseCode === 'FACTORY') {
            return $this->factoryRoute($country);
        }

        $route = $country !== '' ? config("carrier_routing.countries.{$country}.warehouses.{$warehouseCode}") : null;
        if (! is_array($route)) {
            $route = config("carrier_routing.warehouse_defaults.{$warehouseCode}");
        }
        return is_array($route) ? $this->normalizeRoute($route, $country) : [];
    }

    /** @param array<string,mixed> $route @return array<string,mixed> */
    private function normalizeRoute(array $route, ?string $country): array
    {
        $carrier = trim((string) ($route['carrier'] ?? ''));
        if ($carrier === '') return $route;
        $service = $this->services->resolve($carrier, $route['service'] ?? null, $country);
        if ($service) {
            $route['service'] = $service->service_value;
            $route['carrier_service_id'] = (int) $service->id;
        }
        return $route;
    }

    public function defaultCarrier(?string $country, string $warehouseCode): ?string
    {
        $carrier = trim((string) ($this->warehouseRoute($country, $warehouseCode)['carrier'] ?? ''));
        return $carrier !== '' ? $carrier : null;
    }
}
