<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Application\Contracts\FxManualRateRepository;

/**
 * Canonical resolver for current management/reference FX to CNY.
 *
 * This service intentionally does NOT write or replace transaction/posting FX
 * snapshots used by historical Accounting facts.
 */
final class FxReferenceSettingsService
{
    private const CURRENCIES = [
        'USD' => '美元',
        'EUR' => '欧元',
        'GBP' => '英镑',
        'CAD' => '加元',
        'AUD' => '澳元',
        'PHP' => '菲律宾比索',
        'CNY' => '人民币',
    ];

    public function __construct(
        private readonly EcbFxService $ecb,
        private readonly FxManualRateRepository $manualRates,
    ) {}

    /**
     * Convert current management/reference amounts to CNY using the mode selected
     * in Settings → FX. Manual mode uses the preserved manual rate; automatic mode
     * delegates only the required currencies to ECB.
     *
     * @param list<array{currency: string, revenue: int|float|string}> $amounts
     */
    public function convertToCny(array $amounts): array
    {
        $nonzero = array_values(array_filter(
            $amounts,
            fn (array $row): bool => (float) ($row['revenue'] ?? 0) != 0.0,
        ));

        if ($nonzero === []) {
            return [
                'available' => true,
                'amount_cny' => 0.0,
                'source' => 'SETTINGS_FX',
                'reference_date' => null,
                'stale' => false,
                'conversions' => [],
            ];
        }

        $saved = $this->manualRates->all();
        $supported = array_keys(self::CURRENCIES);
        $autoCurrencies = [];

        foreach ($nonzero as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            if (! in_array($currency, $supported, true)) {
                return ['available' => false, 'reason' => 'unsupported_currency'];
            }

            if ($currency === 'CNY') {
                continue;
            }

            $config = $saved[$currency] ?? null;
            $automatic = $config['automatic'] ?? true;
            if ($automatic) {
                $autoCurrencies[$currency] = true;
                continue;
            }

            if (! isset($config['rate']) || (float) $config['rate'] <= 0) {
                return ['available' => false, 'reason' => 'fx_unavailable'];
            }
        }

        $ecbRates = [];
        $referenceDate = null;
        $stale = false;
        if ($autoCurrencies !== []) {
            $ecb = $this->ecb->convertToCny(array_map(
                fn (string $currency): array => ['currency' => $currency, 'revenue' => 1.0],
                array_keys($autoCurrencies),
            ));
            if (! ($ecb['available'] ?? false)) {
                return ['available' => false, 'reason' => $ecb['reason'] ?? 'fx_unavailable'];
            }
            foreach ($ecb['conversions'] as $conversion) {
                $ecbRates[$conversion['original_currency']] = (float) $conversion['rate_to_cny'];
            }
            $referenceDate = $ecb['reference_date'] ?? null;
            $stale = (bool) ($ecb['stale'] ?? false);
        }

        $conversions = [];
        $totalCny = 0.0;
        foreach ($nonzero as $row) {
            $currency = strtoupper(trim((string) $row['currency']));
            $amount = (float) $row['revenue'];
            $config = $saved[$currency] ?? null;

            if ($currency === 'CNY') {
                $rate = 1.0;
                $source = 'fixed';
            } elseif (($config['automatic'] ?? true) === false) {
                $rate = (float) $config['rate'];
                $source = 'manual';
            } else {
                $rate = $ecbRates[$currency] ?? null;
                if ($rate === null) {
                    return ['available' => false, 'reason' => 'fx_unavailable'];
                }
                $source = 'ecb';
            }

            $cnyAmount = round($amount * $rate, 8);
            $totalCny = round($totalCny + $cnyAmount, 8);
            $conversions[] = [
                'original_currency' => $currency,
                'original_amount' => $amount,
                'rate_to_cny' => round($rate, 8),
                'cny_amount' => $cnyAmount,
                'source' => $source,
            ];
        }

        return [
            'available' => true,
            'amount_cny' => $totalCny,
            'source' => 'SETTINGS_FX',
            'reference_date' => $referenceDate,
            'stale' => $stale,
            'conversions' => $conversions,
        ];
    }

    public function snapshot(): array
    {
        $saved = $this->manualRates->all();
        $reference = $this->ecb->convertToCny(array_map(
            fn (string $currency): array => ['currency' => $currency, 'revenue' => 1.0],
            array_keys(self::CURRENCIES),
        ));

        $ecbRates = [];
        if ($reference['available'] ?? false) {
            foreach ($reference['conversions'] as $conversion) {
                $ecbRates[$conversion['original_currency']] = round((float) $conversion['rate_to_cny'], 8);
            }
        }

        $rows = [];
        foreach (self::CURRENCIES as $currency => $label) {
            $config = $saved[$currency] ?? null;
            $manualRate = $config['rate'] ?? null;
            $automatic = $currency === 'CNY' ? false : ($config['automatic'] ?? true);
            $ecbRate = $currency === 'CNY' ? 1.0 : ($ecbRates[$currency] ?? null);
            $effective = $currency === 'CNY' ? 1.0 : ($automatic ? $ecbRate : $manualRate);
            $source = $currency === 'CNY'
                ? 'fixed'
                : ($automatic ? ($ecbRate !== null ? 'ecb' : 'unavailable') : ($manualRate !== null ? 'manual' : 'unavailable'));

            $rows[] = [
                'currency' => $currency,
                'label' => $label,
                'ecbRate' => $ecbRate,
                'manualRate' => $manualRate,
                'automatic' => $automatic,
                'effectiveRate' => $effective,
                'source' => $source,
                'manualEditable' => $currency !== 'CNY',
            ];
        }

        return [
            'rows' => $rows,
            'reference' => [
                'source' => 'ECB',
                'available' => (bool) ($reference['available'] ?? false),
                'referenceDate' => $reference['reference_date'] ?? null,
                'stale' => (bool) ($reference['stale'] ?? false),
                'refreshHours' => 12,
                'policy' => 'explicit_mode',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rates
     * @param array<string, mixed> $automatic
     */
    public function save(array $rates, array $automatic, ?int $actorId): void
    {
        $allowed = array_filter(array_keys(self::CURRENCIES), fn (string $currency): bool => $currency !== 'CNY');
        $normalizedRates = [];
        $normalizedAutomatic = [];

        foreach ($allowed as $currency) {
            $value = $rates[$currency] ?? null;
            $normalizedRates[$currency] = $value === null || $value === '' ? null : (float) $value;
            $normalizedAutomatic[$currency] = (bool) ($automatic[$currency] ?? true);
        }

        $this->manualRates->replace($normalizedRates, $normalizedAutomatic, $actorId);
    }
}
