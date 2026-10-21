<?php

namespace Tests\Unit\Products;

use App\Modules\Products\Application\SkuMatchKey;
use PHPUnit\Framework\TestCase;

final class SkuMatchKeyTest extends TestCase
{
    public function test_blank_returns_null(): void
    {
        $this->assertNull(SkuMatchKey::compute(''));
        $this->assertNull(SkuMatchKey::compute('   '));
    }

    public function test_trims_whitespace(): void
    {
        $this->assertSame('vm467a', SkuMatchKey::compute(' VM467A '));
    }

    public function test_case_insensitive(): void
    {
        $this->assertSame(SkuMatchKey::compute('VM467A'), SkuMatchKey::compute('vm467a'));
    }

    public function test_preserves_internal_whitespace(): void
    {
        $key = SkuMatchKey::compute('VM467A DARK GREEN');
        $this->assertSame('vm467a dark green', $key);
    }

    public function test_does_not_strip_color_words(): void
    {
        $this->assertNotSame(SkuMatchKey::compute('VM467A'), SkuMatchKey::compute('VM467A DARK GREEN'));
    }

    public function test_preserves_hyphens(): void
    {
        $this->assertSame('vx610a-s', SkuMatchKey::compute('VX610A-S'));
    }

    public function test_does_not_strip_suffix(): void
    {
        $this->assertNotSame(SkuMatchKey::compute('VX610'), SkuMatchKey::compute('VX610A'));
    }
}
