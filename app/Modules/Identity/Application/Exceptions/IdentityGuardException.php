<?php

namespace App\Modules\Identity\Application\Exceptions;

use RuntimeException;

final class IdentityGuardException extends RuntimeException
{
    public static function selfDisable(): self
    {
        return new self('管理员不能停用自己的账号。');
    }

    public static function selfDelete(): self
    {
        return new self('管理员不能删除自己的账号。');
    }

    public static function hasBusinessReferences(): self
    {
        return new self('该用户存在历史业务记录，无法删除，请改为停用该账号。');
    }

    public static function lastPermissionsHolder(): self
    {
        return new self('操作被拒绝：系统中至少须保留一名拥有"管理系统权限"的活跃用户。');
    }
}
