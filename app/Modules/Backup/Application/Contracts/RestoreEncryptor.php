<?php

namespace App\Modules\Backup\Application\Contracts;

interface RestoreEncryptor
{
    /** @return array{header: array<string,mixed>, chunks: int} */
    public function inspect(string $source): array;
    public function decrypt(string $source, string $destination): void;
}
