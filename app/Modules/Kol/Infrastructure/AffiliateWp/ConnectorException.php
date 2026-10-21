<?php

namespace App\Modules\Kol\Infrastructure\AffiliateWp;

use RuntimeException;

final class ConnectorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly bool $retryable,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($message);
    }

    public static function nonRetryable(string $message, string $errorCode, int $httpStatus = 0): self
    {
        return new self($message, $errorCode, false, $httpStatus);
    }

    public static function retryable(string $message, string $errorCode, int $httpStatus = 0): self
    {
        return new self($message, $errorCode, true, $httpStatus);
    }
}
