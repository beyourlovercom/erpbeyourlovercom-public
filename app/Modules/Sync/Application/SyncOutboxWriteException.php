<?php

namespace App\Modules\Sync\Application;

use RuntimeException;

final class SyncOutboxWriteException extends RuntimeException
{
    public static function invalid(string $field): self
    {
        return new self("Invalid sync outbox {$field}.");
    }

    public static function idempotencyConflict(): self
    {
        return new self('The idempotency key already belongs to a different sync intent.');
    }

    public static function transitionNotAllowed(): self
    {
        return new self('The requested sync outbox transition is not allowed.');
    }

    public static function lockMismatch(): self
    {
        return new self('The sync outbox lock does not match.');
    }

    public static function concurrentMutation(): self
    {
        return new self('The sync outbox record changed concurrently.');
    }
}
