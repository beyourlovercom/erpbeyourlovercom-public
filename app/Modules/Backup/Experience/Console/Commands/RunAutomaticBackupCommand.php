<?php

namespace App\Modules\Backup\Experience\Console\Commands;

use App\Modules\Backup\Application\BackupEngineService;
use App\Modules\Backup\Application\BackupScheduleCadence;
use App\Modules\Backup\Application\BackupScheduleSettings;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use App\Modules\Backup\Experience\Jobs\ApplyBackupRetentionJob;
use App\Modules\Backup\Experience\Jobs\CreateBackupJob;
use App\Modules\Backup\Experience\Jobs\PublishBackupToGithubJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

final class RunAutomaticBackupCommand extends Command
{
    protected $signature = 'backup:scheduled-run {cadence}';
    protected $description = 'Queue a configured automatic BYL ERP backup.';

    public function handle(
        BackupScheduleSettings $settings,
        BackupEngineService $engine,
        BackupRepository $repository,
    ): int {
        $cadence = BackupScheduleCadence::tryFrom((string) $this->argument('cadence'));
        if ($cadence === null) {
            $this->error('Unsupported backup cadence.');
            return self::INVALID;
        }

        $setting = $settings->current();
        if (! $setting->enabled || $setting->cadence !== $cadence) {
            $this->line('Automatic backup is disabled or this cadence is not active.');
            return self::SUCCESS;
        }

        $run = $engine->create(BackupType::Full);
        $run = $repository->updateRun($run->id, [
            'metadata' => array_merge($run->metadata, [
                'origin' => 'scheduled',
                'cadence' => $cadence->value,
                'scheduled_at' => now()->toIso8601String(),
            ]),
        ]);

        Bus::chain([
            new CreateBackupJob($run->id),
            new PublishBackupToGithubJob($run->id),
            new ApplyBackupRetentionJob($run->id),
        ])->dispatch();

        $this->info('Automatic backup queued.');
        return self::SUCCESS;
    }
}
