<?php

namespace App\Modules\Inventory\Application\Sync;

final class RowNormalizer
{
    private const STATUS_MAP = [
        '正品'      => 'sellable',
        'sellable'  => 'sellable',
        '次品'      => 'defective',
        'defective' => 'defective',
    ];

    public static function normalizeStockStatus(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') return null;
        foreach (self::STATUS_MAP as $label => $value) {
            if (mb_strtolower(trim($raw)) === mb_strtolower($label)) return $value;
        }
        return null;
    }

    public static function normalizeDate(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') return null;
        $raw = trim($raw);
        foreach (['Y-m-d', 'Y/m/d', 'm/d/Y', 'd/m/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $raw);
            if ($dt !== false) return $dt->format('Y-m-d');
        }
        $ts = strtotime($raw);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    public static function normalizeQuantity(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') return null;
        $value = trim($raw);
        if (!preg_match('/^[+-]?\d+$/', $value)) return null;
        return (int) $value;
    }

    public static function normalizeSku(?string $raw): ?string
    {
        if ($raw === null) return null;
        $trimmed = trim($raw);
        return $trimmed !== '' ? $trimmed : null;
    }
}
