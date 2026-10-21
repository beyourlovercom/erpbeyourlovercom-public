<?php

namespace App\Modules\Products\Application;

use Illuminate\Database\Eloquent\Model;

final class SkuResolveResult
{
    public const CANONICAL  = 'canonical';
    public const ALIAS      = 'alias';
    public const UNMATCHED  = 'unmatched';

    private function __construct(
        public readonly string $type,
        public readonly ?Model $product,
        public readonly ?string $canonicalSku,
    ) {}

    public static function canonical(Model $product): self
    {
        return new self(self::CANONICAL, $product, (string) $product->getAttribute('sku'));
    }

    public static function alias(Model $product): self
    {
        return new self(self::ALIAS, $product, (string) $product->getAttribute('sku'));
    }

    public static function unmatched(): self
    {
        return new self(self::UNMATCHED, null, null);
    }

    public function isResolved(): bool
    {
        return $this->product !== null;
    }
}
