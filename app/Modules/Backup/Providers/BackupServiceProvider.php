<?php

namespace App\Modules\Backup\Providers;

use App\Modules\Backup\Application\Contracts\BackupRepository;
use App\Modules\Backup\Application\Contracts\AutomaticBackupPolicyRepository;
use App\Modules\Backup\Application\Contracts\GithubReleaseDeletionGateway;
use App\Modules\Backup\Application\Contracts\GithubReleaseGateway;
use App\Modules\Backup\Application\Contracts\DatabaseRestorer;
use App\Modules\Backup\Application\Contracts\PreRestoreSnapshotter;
use App\Modules\Backup\Application\Contracts\RestoreHealthChecker;
use App\Modules\Backup\Application\Contracts\PostRestoreSchemaUpdater;
use App\Modules\Backup\Application\Contracts\RestoreRollbackExecutor;
use App\Modules\Backup\Application\Contracts\StorageRestorer;
use App\Modules\Backup\Application\Contracts\RestoreCompatibility;
use App\Modules\Backup\Application\Contracts\RestoreEncryptor;
use App\Modules\Backup\Application\Contracts\RestorePackageExtractor;
use App\Modules\Backup\Application\StreamingAesGcmEncryptor;
use App\Modules\Backup\Infrastructure\CurrentRestoreCompatibility;
use App\Modules\Backup\Infrastructure\ArtisanPostRestoreSchemaUpdater;
use App\Modules\Backup\Infrastructure\LocalPreRestoreSnapshotter;
use App\Modules\Backup\Infrastructure\MysqlDatabaseRestorer;
use App\Modules\Backup\Infrastructure\RuntimeRestoreHealthChecker;
use App\Modules\Backup\Infrastructure\SnapshotRestoreRollbackExecutor;
use App\Modules\Backup\Infrastructure\ZstdTarStorageRestorer;
use App\Modules\Backup\Infrastructure\EloquentBackupRepository;
use App\Modules\Backup\Infrastructure\EloquentAutomaticBackupPolicyRepository;
use App\Modules\Backup\Infrastructure\HttpGithubReleaseGateway;
use App\Modules\Backup\Infrastructure\HttpGithubReleaseDeletionGateway;
use App\Modules\Backup\Infrastructure\ZstdTarRestorePackageExtractor;
use App\Modules\Backup\Experience\Console\Commands\RunAutomaticBackupCommand;
use Illuminate\Support\ServiceProvider;

final class BackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BackupRepository::class, EloquentBackupRepository::class);
        $this->app->bind(AutomaticBackupPolicyRepository::class, EloquentAutomaticBackupPolicyRepository::class);
        $this->app->bind(GithubReleaseDeletionGateway::class, HttpGithubReleaseDeletionGateway::class);
        $this->app->bind(GithubReleaseGateway::class, HttpGithubReleaseGateway::class);
        $this->app->bind(RestoreEncryptor::class, StreamingAesGcmEncryptor::class);
        $this->app->bind(RestorePackageExtractor::class, ZstdTarRestorePackageExtractor::class);
        $this->app->bind(RestoreCompatibility::class, CurrentRestoreCompatibility::class);
        $this->app->bind(DatabaseRestorer::class, MysqlDatabaseRestorer::class);
        $this->app->bind(StorageRestorer::class, ZstdTarStorageRestorer::class);
        $this->app->bind(PreRestoreSnapshotter::class, LocalPreRestoreSnapshotter::class);
        $this->app->bind(PostRestoreSchemaUpdater::class, ArtisanPostRestoreSchemaUpdater::class);
        $this->app->bind(RestoreHealthChecker::class, RuntimeRestoreHealthChecker::class);
        $this->app->bind(RestoreRollbackExecutor::class, SnapshotRestoreRollbackExecutor::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
        $this->commands([RunAutomaticBackupCommand::class]);
    }
}
