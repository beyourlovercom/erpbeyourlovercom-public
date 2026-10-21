<?php

namespace App\Modules\Traffic\Application\Exceptions;

use RuntimeException;
use Throwable;

final class GoogleAdsReadException extends RuntimeException
{
    public function __construct(
        public readonly string $status,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
