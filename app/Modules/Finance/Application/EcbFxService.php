<?php

namespace App\Modules\Finance\Application;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reference-only ECB daily FX service for CNY conversion display.
 * Rates are for management reference; never mutate Order/Finance transaction facts.
 */
final class EcbFxService
{
    private const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
    private const CACHE_KEY = 'ecb_fx_snapshot';
    private const FRESH_TTL_HOURS = 12;

    /**
     * Returns an RMB estimate for a set of currency amounts.
     * Each entry: ['currency' => string, 'revenue' => float]
     *
     * Returns:
     *   ['available' => true,  'amount_cny' => float, 'source' => 'ECB', 'reference_date' => string,
     *    'stale' => bool, 'conversions' => [...]]
     *   ['available' => false, 'reason' => string]
     */
    public function convertToCny(array $revenueByCurrency): array
    {
        $nonzero = array_filter($revenueByCurrency, fn (array $r): bool => $r['revenue'] != 0);

        if (empty($nonzero)) {
            return ['available' => true, 'amount_cny' => 0.0, 'source' => 'ECB',
                'reference_date' => null, 'stale' => false, 'conversions' => []];
        }

        [$rates, $refDate, $stale] = $this->snapshot();

        if ($rates === null) {
            return ['available' => false, 'reason' => 'fx_unavailable'];
        }

        $conversions = [];
        $totalCny = 0.0;

        foreach ($nonzero as $row) {
            $currency = strtoupper($row['currency']);
            $amount = (float) $row['revenue'];

            if ($currency === 'CNY') {
                $rate = 1.0;
            } elseif ($currency === 'EUR') {
                if (!isset($rates['CNY'])) {
                    return ['available' => false, 'reason' => 'unsupported_currency'];
                }
                $rate = (float) $rates['CNY'];
            } else {
                if (!isset($rates[$currency]) || !isset($rates['CNY'])) {
                    return ['available' => false, 'reason' => 'unsupported_currency'];
                }
                // ECB quotes per EUR: CNY_per_source = ECB_CNY / ECB_SOURCE
                $rate = (float) $rates['CNY'] / (float) $rates[$currency];
            }

            $cnyAmount = $amount * $rate;
            $totalCny += $cnyAmount;
            $conversions[] = [
                'original_currency' => $currency,
                'original_amount' => $amount,
                'rate_to_cny' => $rate,
                'cny_amount' => $cnyAmount,
            ];
        }

        return [
            'available' => true,
            'amount_cny' => $totalCny,
            'source' => 'ECB',
            'reference_date' => $refDate,
            'stale' => $stale,
            'conversions' => $conversions,
        ];
    }

    /**
     * Returns [rates_array|null, reference_date|null, stale_bool].
     * Tries a fresh fetch; falls back to cached snapshot on failure.
     */
    private function snapshot(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        $isFresh = $cached !== null
            && isset($cached['fetched_at'])
            && \Carbon\Carbon::parse($cached['fetched_at'])->isAfter(now()->subHours(self::FRESH_TTL_HOURS));

        if ($isFresh) {
            return [$cached['rates'], $cached['reference_date'], false];
        }

        $fetched = $this->fetchFromEcb();

        if ($fetched !== null) {
            Cache::put(self::CACHE_KEY, $fetched, now()->addDay());
            return [$fetched['rates'], $fetched['reference_date'], false];
        }

        if ($cached !== null) {
            return [$cached['rates'], $cached['reference_date'], true];
        }

        return [null, null, false];
    }

    private function fetchFromEcb(): ?array
    {
        try {
            $response = Http::timeout(8)->get(self::ECB_URL);
            if (!$response->successful()) {
                return null;
            }

            $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR);
            if ($xml === false) {
                return null;
            }

            $xml->registerXPathNamespace('gesmes', 'http://www.gesmes.org/xml/2002-08-01');
            $xml->registerXPathNamespace('ecb', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');

            $cubeNodes = $xml->xpath('//ecb:Cube[@time]');
            if (empty($cubeNodes)) {
                return null;
            }

            $dateCube = $cubeNodes[0];
            $refDate = (string) ($dateCube['time'] ?? '');
            if ($refDate === '') {
                return null;
            }

            $rates = [];
            foreach ($dateCube->Cube as $node) {
                $currency = (string) ($node['currency'] ?? '');
                $rate = (string) ($node['rate'] ?? '');
                if ($currency !== '' && $rate !== '') {
                    $rates[$currency] = (float) $rate;
                }
            }

            if (empty($rates)) {
                return null;
            }

            return [
                'rates' => $rates,
                'reference_date' => $refDate,
                'fetched_at' => now()->toIso8601String(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
