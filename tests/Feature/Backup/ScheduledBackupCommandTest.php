<?php

namespace Tests\Feature\Backup;

use App\Modules\Backup\Application\BackupScheduleCadence;
use App\Modules\Backup\Application\BackupScheduleSettings;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Backup\Experience\Jobs\ApplyBackupRetentionJob;
use App\Modules\Backup\Experience\Jobs\CreateBackupJob;
use App\Modules\Backup\Experience\Jobs\PublishBackupToGithubJob;
use App\Modules\Backup\Infrastructure\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class ScheduledBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_matching_cadence_creates_full_scheduled_run_and_dispatches_chain(): void
    {
        Bus::fake();
        $this->app->make(BackupScheduleSettings::class)->update(true, BackupScheduleCadence::Daily);

        $this->assertSame(0, Artisan::call('backup:scheduled-run', ['cadence' => 'daily']));

        $run = BackupRun::query()->sole();
        $this->assertSame(BackupType::Full, $run->backup_type);
        $this->assertSame(BackupState::Queued, $run->state);
        $this->assertSame('scheduled', $run->metadata['origin']);
        $this->assertSame('daily', $run->metadata['cadence']);
        $this->assertNotEmpty($run->metadata['scheduled_at']);
        Bus::assertChained([
            CreateBackupJob::class,
            PublishBackupToGithubJob::class,
            ApplyBackupRetentionJob::class,
        ]);
    }

    public function test_disabled_or_non_matching_cadence_is_a_noop(): void
    {
        Bus::fake();
        $settings = $this->app->make(BackupScheduleSettings::class);
        $settings->update(false, BackupScheduleCadence::Daily);
        $this->assertSame(0, Artisan::call('backup:scheduled-run', ['cadence' => 'daily']));
        $this->assertDatabaseCount('backup_runs', 0);

        $settings->update(true, BackupScheduleCadence::Weekly);
        $this->assertSame(0, Artisan::call('backup:scheduled-run', ['cadence' => 'daily']));
        $this->assertDatabaseCount('backup_runs', 0);
        Bus::assertNothingDispatched();
    }

    public function test_invalid_cadence_fails_without_creating_a_run(): void
    {
        Bus::fake();
        $this->assertSame(2, Artisan::call('backup:scheduled-run', ['cadence' => 'hourly']));
        $this->assertDatabaseCount('backup_runs', 0);
        Bus::assertNothingDispatched();
    }
}
