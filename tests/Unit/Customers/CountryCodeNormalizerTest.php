<?php

namespace Tests\Unit\Customers;

use App\Modules\Customers\Domain\CountryCodeNormalizer;
use App\Modules\Customers\Domain\Exceptions\InvalidCompanyAttributeException;
use PHPUnit\Framework\TestCase;

final class CountryCodeNormalizerTest extends TestCase
{
    public function test_it_uppercases_a_lowercase_code(): void
    {
        $this->assertSame('US', CountryCodeNormalizer::normalize('us'));
    }

    public function test_it_trims_surrounding_whitespace(): void
    {
        $this->assertSame('GB', CountryCodeNormalizer::normalize(' gb '));
    }

    public function test_it_rejects_codes_that_are_not_exactly_two_letters(): void
    {
        $this->expectException(InvalidCompanyAttributeException::class);
        CountryCodeNormalizer::normalize('USA');
    }

    public function test_it_rejects_non_alphabetic_input(): void
    {
        $this->expectException(InvalidCompanyAttributeException::class);
        CountryCodeNormalizer::normalize('U1');
    }
}
