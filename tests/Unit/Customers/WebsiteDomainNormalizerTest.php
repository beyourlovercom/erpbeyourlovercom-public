<?php

namespace Tests\Unit\Customers;

use App\Modules\Customers\Domain\Exceptions\InvalidCompanyAttributeException;
use App\Modules\Customers\Domain\WebsiteDomainNormalizer;
use PHPUnit\Framework\TestCase;

final class WebsiteDomainNormalizerTest extends TestCase
{
    public function test_it_lowercases_the_host(): void
    {
        $this->assertSame('example.com', WebsiteDomainNormalizer::normalize('https://EXAMPLE.com/path'));
    }

    public function test_it_removes_exactly_one_trailing_dot(): void
    {
        $this->assertSame('example.com', WebsiteDomainNormalizer::normalize('https://example.com./path'));
    }

    public function test_it_does_not_strip_subdomains(): void
    {
        $this->assertSame('shop.example.co.uk', WebsiteDomainNormalizer::normalize('https://Shop.Example.Co.UK'));
    }

    public function test_it_preserves_ports_out_of_the_host(): void
    {
        $this->assertSame('example.com', WebsiteDomainNormalizer::normalize('https://example.com:8443/path'));
    }

    public function test_invalid_url_fails_instead_of_being_guessed(): void
    {
        $this->expectException(InvalidCompanyAttributeException::class);
        WebsiteDomainNormalizer::normalize('example.com');
    }

    public function test_garbage_input_fails(): void
    {
        $this->expectException(InvalidCompanyAttributeException::class);
        WebsiteDomainNormalizer::normalize('not a url at all');
    }
}
