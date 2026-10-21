<?php

namespace App\Modules\Products\Application;

final class SkuMatchKey
{
    public static function compute(string $sku): ?string
    {
        $trimmed = trim($sku);
        if ($trimmed === '') {
            return null;
        }
        return mb_strtolower($trimmed, 'UTF-8');
    }
}
