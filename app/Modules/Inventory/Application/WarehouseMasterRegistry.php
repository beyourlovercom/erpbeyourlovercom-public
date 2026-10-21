<?php

namespace App\Modules\Inventory\Application;

final class WarehouseMasterRegistry
{
    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return (array) config('warehouse_master.warehouses', []);
    }

    /** @return array<string,mixed> */
    public function forCode(string $code): array
    {
        return (array) ($this->all()[strtoupper(trim($code))] ?? []);
    }

    public function publicSlugForCode(string $code, string $fallback): string
    {
        $slug = trim((string) ($this->forCode($code)['public_slug'] ?? ''));
        return $slug !== '' ? $slug : $fallback;
    }

    public function isOverseasFulfillmentSlug(string $slug): bool
    {
        $code = $this->codeForSlug($slug);
        if ($code === null) return false;
        return (string) ($this->forCode($code)['role_label'] ?? '') === '海外履约仓';
    }

    public function codeForSlug(string $slug): ?string
    {
        $needle = strtolower(trim($slug));
        if ($needle === '') return null;
        foreach ($this->all() as $code => $canonical) {
            $aliases = array_values(array_unique([
                (string) ($canonical['public_slug'] ?? ''),
                ...array_map('strval', (array) ($canonical['legacy_slugs'] ?? [])),
            ]));
            if (collect($aliases)->contains(fn (string $alias): bool => strtolower(trim($alias)) === $needle)) return strtoupper((string) $code);
        }
        return null;
    }
}
