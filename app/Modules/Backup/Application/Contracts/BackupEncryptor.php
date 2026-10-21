<?php

namespace App\Modules\Backup\Application\Contracts;

interface BackupEncryptor
{
    public function encrypt(string $source, string $destination): void;
}
