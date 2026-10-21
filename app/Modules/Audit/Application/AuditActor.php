<?php

namespace App\Modules\Audit\Application;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class AuditActor
{
    private function __construct(
        public ?int $userId,
        public AuditSource $source,
    ) {}

    public static function user(Authenticatable $user, AuditSource $source): self
    {
        return new self((int) $user->getAuthIdentifier(), $source);
    }

    public static function system(AuditSource $source): self
    {
        return new self(null, $source);
    }
}
