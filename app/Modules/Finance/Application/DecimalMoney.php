<?php

namespace App\Modules\Finance\Application;

final class DecimalMoney
{
    public static function add(string $left, string $right, int $scale = 8): string
    {
        return bcadd($left, $right, $scale);
    }

    public static function multiply(string $left, string $right, int $scale = 8): string
    {
        return bcmul($left, $right, $scale);
    }

    public static function round(string $value, int $scale = 2): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = preg_replace('/\D/', '', $fraction) ?: '';
        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        if (strlen($fraction) > $scale && (int) $fraction[$scale] >= 5) {
            $increment = $scale === 0 ? '1' : '0.'.str_repeat('0', $scale - 1).'1';
            $rounded = bcadd($whole.'.'.$kept, $increment, $scale);
            [$whole, $kept] = array_pad(explode('.', $rounded, 2), 2, '');
        }
        $result = $whole.($scale > 0 ? '.'.str_pad($kept, $scale, '0') : '');
        return $negative && $result !== '0'.($scale > 0 ? '.'.str_repeat('0', $scale) : '') ? '-'.$result : $result;
    }
}
