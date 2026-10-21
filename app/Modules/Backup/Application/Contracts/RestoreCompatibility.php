<?php

namespace App\Modules\Backup\Application\Contracts;

interface RestoreCompatibility
{
    public function gitSha(): string;
    public function databaseEngine(): string;
    public function latestMigration(): ?string;
    public function migrationCount(): int;
}
