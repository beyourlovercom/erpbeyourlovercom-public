<?php

namespace App\Modules\Customers\Domain;

use App\Modules\Customers\Domain\Exceptions\InvalidCompanyAttributeException;

final class WebsiteDomainNormalizer
{
    /**
     * parse host -> lowercase -> remove one trailing dot -> store exact host.
     * Does not strip subdomains and does not guess/correct invalid input.
     */
    public static function normalize(string $websiteUrl): string
    {
        if (filter_var($websiteUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidCompanyAttributeException('website_url is not a valid URL.');
        }

        $host = parse_url($websiteUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new InvalidCompanyAttributeException('website_url does not contain a parseable host.');
        }

        $host = strtolower($host);

        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if ($host === '') {
            throw new InvalidCompanyAttributeException('website_url normalized to an empty host.');
        }

        return $host;
    }
}
