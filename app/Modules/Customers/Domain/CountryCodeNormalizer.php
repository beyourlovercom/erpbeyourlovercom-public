<?php

namespace App\Modules\Customers\Domain;

use App\Modules\Customers\Domain\Exceptions\InvalidCompanyAttributeException;

final class CountryCodeNormalizer
{
    /**
     * Uppercase exactly two-character country codes only; no semantic validation beyond shape.
     */
    public static function normalize(string $countryCode): string
    {
        $normalized = strtoupper(trim($countryCode));

        if (! preg_match('/^[A-Z]{2}$/', $normalized)) {
            throw new InvalidCompanyAttributeException('country_code must be exactly two letters.');
        }

        return $normalized;
    }
}
