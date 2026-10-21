<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\Contracts\PostRestoreSchemaUpdater;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ArtisanPostRestoreSchemaUpdater implements PostRestoreSchemaUpdater
{
    public function run(): void
    {
        $connection = (string) config('database.default');
        DB::purge($connection);
        $exit = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        DB::purge($connection);
        if ($exit !== 0) {
            throw new RuntimeException('Post-restore migrations failed.');
        }
    }
}
