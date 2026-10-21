<?php

namespace App\Modules\Logistics\Application;

final class UspsZoneResolver
{
    /** @var array<string,array<string,mixed>> */
    private array $dataCache = [];

    /** @return array{status:string,zone:?int,prefix:?int,postal_code:?string,source:?array} */
    public function resolve(?string $postalCode, string $dataFile): array
    {
        $postal = trim((string) $postalCode);
        if (! preg_match('/^(\d{5})(?:-\d{4})?$/D', $postal, $match)) {
            return $this->result('invalid_or_missing_zip', null, null, $postal !== '' ? $postal : null, null);
        }

        $relative = ltrim($dataFile, '/');
        $path = base_path($relative);
        if (! is_file($path)) {
            return $this->result('zone_data_missing', null, (int) substr($match[1], 0, 3), $match[1], null);
        }

        $data = $this->dataCache[$path] ??= (array) require $path;
        $prefix = (int) substr($match[1], 0, 3);
        $zones = [];
        foreach (($data['ranges'] ?? []) as $range) {
            $start = (int) ($range['start'] ?? -1);
            $end = (int) ($range['end'] ?? -1);
            if ($prefix >= $start && $prefix <= $end) {
                $zones[(int) ($range['zone'] ?? 0)] = true;
            }
        }

        $matchedZones = array_values(array_filter(array_keys($zones), fn (int $zone): bool => $zone > 0));
        if (count($matchedZones) === 1) {
            return $this->result('matched', $matchedZones[0], $prefix, $match[1], $data['source'] ?? null);
        }
        if (count($matchedZones) > 1) {
            return $this->result('ambiguous_source_ranges', null, $prefix, $match[1], $data['source'] ?? null);
        }

        return $this->result('zone_not_found', null, $prefix, $match[1], $data['source'] ?? null);
    }

    private function result(string $status, ?int $zone, ?int $prefix, ?string $postal, ?array $source): array
    {
        return ['status' => $status, 'zone' => $zone, 'prefix' => $prefix, 'postal_code' => $postal, 'source' => $source];
    }
}
