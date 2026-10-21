<?php

namespace App\Modules\Backup\Application\Contracts;

interface PostRestoreSchemaUpdater
{
    public function run(): void;
}
