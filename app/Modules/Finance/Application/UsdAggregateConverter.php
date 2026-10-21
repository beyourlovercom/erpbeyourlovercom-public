<?php

namespace App\Modules\Finance\Application;

use Illuminate\Database\Eloquent\Model;

final class UsdAggregateConverter
{
    public function toUsdCents(string $amount, Model $order): int
    {
        if ($this->transactionCurrency($order) === 'USD') {
            return $this->decimalToCents($amount);
        }

        $rate = trim((string) $order->financials?->fx_rate);
        if ($rate === '' || bccomp($rate, '0', 8) <= 0) {
            throw new \UnexpectedValueException('Aggregate-eligible non-USD order requires a positive frozen FX rate.');
        }

        return $this->decimalToCents(bcmul($amount, $rate, 10), round: true);
    }

    public function transactionCurrency(Model $order): string
    {
        return strtoupper((string) ($order->financials?->currency ?? $order->currency ?? 'USD'));
    }

    public function decimalToCents(string $value, bool $round = false): int
    {
        $value = trim($value);
        if (! preg_match('/^(?<sign>-)?(?<whole>\d+)(?:\.(?<fraction>\d+))?$/D', $value, $matches)) {
            throw new \UnexpectedValueException('Financial amounts must be decimal values.');
        }

        $fraction = str_pad($matches['fraction'] ?? '', 3, '0');
        $cents = ((int) $matches['whole'] * 100) + (int) substr($fraction, 0, 2);
        if ($round && (int) $fraction[2] >= 5) {
            $cents++;
        }

        return ($matches['sign'] ?? '') === '-' ? -$cents : $cents;
    }

    public function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
