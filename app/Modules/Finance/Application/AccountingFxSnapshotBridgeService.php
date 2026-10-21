<?php

namespace App\Modules\Finance\Application;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Bridges the closed Settings FX resolver into immutable Accounting posting snapshots.
 * It never revalues an already-frozen OrderFinancial FX snapshot.
 */
final class AccountingFxSnapshotBridgeService
{
    public function __construct(private readonly FxReferenceSettingsService $settings) {}

    /** @return array<string,mixed> */
    public function preview(Model $order, string $sourceCurrency): array
    {
        $sourceCurrency = strtoupper(trim($sourceCurrency));
        $transactionCurrency = strtoupper((string) (($order->financials()->first()?->currency) ?? $order->currency ?? 'USD'));
        if ($sourceCurrency === '' || $transactionCurrency === '') {
            return ['available' => false, 'reason' => 'fx_currency_missing'];
        }

        $financials = $order->financials()->first();
        $existing = $this->existingTransactionSnapshot($financials, $transactionCurrency);
        if (! $existing['available']) {
            return $existing + ['source_currency' => $sourceCurrency, 'transaction_currency' => $transactionCurrency];
        }

        $needed = [];
        if ($sourceCurrency !== 'USD') {
            $needed[] = $sourceCurrency;
            $needed[] = 'USD';
        }
        if ($transactionCurrency !== 'USD' && ! $existing['frozen']) {
            $needed[] = $transactionCurrency;
            $needed[] = 'USD';
        }
        $needed = array_values(array_unique($needed));

        $current = $this->currentRatesToCny($needed);
        if (! $current['available']) {
            return $current + ['source_currency' => $sourceCurrency, 'transaction_currency' => $transactionCurrency];
        }

        $postingDate = now()->toDateString();
        if ($sourceCurrency === 'USD') {
            $sourceToUsd = '1.00000000';
            $sourceDate = $postingDate;
            $sourceRateSource = 'identity:USD';
        } else {
            $sourceRate = $current['rates'][$sourceCurrency] ?? null;
            $usdRate = $current['rates']['USD'] ?? null;
            if ($sourceRate === null || $usdRate === null) {
                return ['available' => false, 'reason' => 'fx_rate_missing', 'source_currency' => $sourceCurrency, 'transaction_currency' => $transactionCurrency];
            }
            $sourceToUsd = $this->divideRate($sourceRate['rate_to_cny'], $usdRate['rate_to_cny']);
            $sourceDate = $this->rateDate($sourceRate['source'], $usdRate['source'], $current['reference_date'], $postingDate);
            $sourceRateSource = $this->rateSource($sourceCurrency, $sourceRate['source'], 'USD', $usdRate['source'], $current['stale']);
        }

        if ($transactionCurrency === 'USD') {
            $transactionToUsd = '1.00000000';
            $transactionDate = $postingDate;
            $transactionSource = 'identity:USD';
            $freezeTransaction = false;
        } elseif ($existing['frozen']) {
            $transactionToUsd = $existing['rate'];
            $transactionDate = $existing['date'];
            $transactionSource = $existing['source'];
            $freezeTransaction = false;
        } else {
            $transactionRate = $current['rates'][$transactionCurrency] ?? null;
            $usdRate = $current['rates']['USD'] ?? null;
            if ($transactionRate === null || $usdRate === null) {
                return ['available' => false, 'reason' => 'fx_rate_missing', 'source_currency' => $sourceCurrency, 'transaction_currency' => $transactionCurrency];
            }
            $transactionToUsd = $this->divideRate($transactionRate['rate_to_cny'], $usdRate['rate_to_cny']);
            $transactionDate = $this->rateDate($transactionRate['source'], $usdRate['source'], $current['reference_date'], $postingDate);
            $transactionSource = $this->rateSource($transactionCurrency, $transactionRate['source'], 'USD', $usdRate['source'], $current['stale']);
            $freezeTransaction = true;
        }

        // A cost already denominated in the transaction currency must use the
        // immutable transaction snapshot. Current reference-rate drift must not
        // revalue the same-currency source on later previews.
        if ($sourceCurrency === $transactionCurrency) {
            $sourceToUsd = $transactionToUsd;
            $sourceDate = $transactionDate;
            $sourceRateSource = $transactionSource;
        }

        return [
            'available' => true,
            'source_currency' => $sourceCurrency,
            'transaction_currency' => $transactionCurrency,
            'source_to_transaction_rate' => $this->divideRate($sourceToUsd, $transactionToUsd),
            'source_to_usd_rate' => $sourceToUsd,
            'transaction_to_usd_rate' => $transactionToUsd,
            'source_exchange_rate_date' => $sourceDate,
            'source_exchange_rate_source' => $sourceRateSource,
            'transaction_fx_rate_date' => $transactionDate,
            'transaction_fx_rate_source' => $transactionSource,
            'freeze_transaction_fx' => $freezeTransaction,
            'stale' => (bool) $current['stale'],
        ];
    }

    /** @param array<string,mixed> $snapshot */
    public function freezeTransactionSnapshot(Model $order, array $snapshot): void
    {
        if (! ($snapshot['freeze_transaction_fx'] ?? false)) {
            return;
        }

        $financials = $order->financials()->lockForUpdate()->first();
        if ($financials === null) {
            throw new RuntimeException('order_financials_missing');
        }

        $fields = [$financials->fx_rate, $financials->fx_rate_date, $financials->fx_rate_source];
        $present = count(array_filter($fields, static fn ($value): bool => $value !== null && $value !== ''));
        if ($present === 3) {
            $same = (string) $financials->fx_rate === (string) $snapshot['transaction_to_usd_rate']
                && optional($financials->fx_rate_date)->toDateString() === (string) $snapshot['transaction_fx_rate_date']
                && (string) $financials->fx_rate_source === (string) $snapshot['transaction_fx_rate_source'];
            if (! $same) {
                throw new RuntimeException('transaction_fx_snapshot_changed');
            }
            return;
        }
        if ($present !== 0) {
            throw new RuntimeException('transaction_fx_snapshot_partial');
        }

        $financials->forceFill([
            'fx_rate' => $snapshot['transaction_to_usd_rate'],
            'fx_rate_date' => $snapshot['transaction_fx_rate_date'],
            'fx_rate_source' => $snapshot['transaction_fx_rate_source'],
        ])->save();
    }

    /** @return array<string,mixed> */
    private function existingTransactionSnapshot(?Model $financials, string $transactionCurrency): array
    {
        if ($transactionCurrency === 'USD') {
            return ['available' => true, 'frozen' => false];
        }
        if ($financials === null) {
            return ['available' => false, 'reason' => 'order_financials_missing'];
        }

        $fields = [$financials->fx_rate, $financials->fx_rate_date, $financials->fx_rate_source];
        $present = count(array_filter($fields, static fn ($value): bool => $value !== null && $value !== ''));
        if ($present === 0) {
            return ['available' => true, 'frozen' => false];
        }
        if ($present !== 3) {
            return ['available' => false, 'reason' => 'transaction_fx_snapshot_partial'];
        }
        if (bccomp((string) $financials->fx_rate, '0', 8) <= 0) {
            return ['available' => false, 'reason' => 'transaction_fx_snapshot_invalid'];
        }

        return [
            'available' => true,
            'frozen' => true,
            'rate' => number_format((float) $financials->fx_rate, 8, '.', ''),
            'date' => $financials->fx_rate_date->toDateString(),
            'source' => (string) $financials->fx_rate_source,
        ];
    }

    /** @param list<string> $currencies @return array<string,mixed> */
    private function currentRatesToCny(array $currencies): array
    {
        if ($currencies === []) {
            return ['available' => true, 'rates' => [], 'reference_date' => null, 'stale' => false];
        }

        $resolved = $this->settings->convertToCny(array_map(
            static fn (string $currency): array => ['currency' => $currency, 'revenue' => 1],
            $currencies,
        ));
        if (! ($resolved['available'] ?? false)) {
            return ['available' => false, 'reason' => $resolved['reason'] ?? 'fx_unavailable'];
        }

        $rates = [];
        foreach ($resolved['conversions'] as $conversion) {
            $currency = strtoupper((string) $conversion['original_currency']);
            $rate = number_format((float) $conversion['rate_to_cny'], 8, '.', '');
            if (bccomp($rate, '0', 8) <= 0) {
                return ['available' => false, 'reason' => 'fx_rate_invalid'];
            }
            $rates[$currency] = ['rate_to_cny' => $rate, 'source' => (string) ($conversion['source'] ?? 'unknown')];
        }

        return [
            'available' => true,
            'rates' => $rates,
            'reference_date' => $resolved['reference_date'] ?? null,
            'stale' => (bool) ($resolved['stale'] ?? false),
        ];
    }

    private function divideRate(string $left, string $right): string
    {
        if (bccomp($right, '0', 8) <= 0) {
            throw new RuntimeException('fx_rate_divisor_invalid');
        }
        return number_format((float) bcdiv($left, $right, 12), 8, '.', '');
    }

    private function rateDate(string $leftSource, string $rightSource, ?string $referenceDate, string $postingDate): string
    {
        return ($leftSource === 'ecb' || $rightSource === 'ecb') && $referenceDate !== null ? $referenceDate : $postingDate;
    }

    private function rateSource(string $leftCurrency, string $leftSource, string $rightCurrency, string $rightSource, bool $stale): string
    {
        return 'settings_fx_posting:'.$leftCurrency.'('.$leftSource.')->'.$rightCurrency.'('.$rightSource.')'.($stale ? ':stale' : '');
    }
}
