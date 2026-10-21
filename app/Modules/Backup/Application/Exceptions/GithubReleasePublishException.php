<?php

namespace App\Modules\Backup\Application\Exceptions;

use RuntimeException;

final class GithubReleasePublishException extends RuntimeException
{
    public static function failed(): self
    {
        return new self('GitHub backup publication failed.');
    }
}
