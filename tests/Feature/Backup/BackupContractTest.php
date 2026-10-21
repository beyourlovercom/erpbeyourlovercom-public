<?php

namespace Tests\Feature\Backup;

use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupType;
use App\Modules\Backup\Application\ManifestContract;
use App\Modules\Backup\Application\RestoreState;
use App\Modules\Identity\Application\AuthorizationKeys;
use App\Modules\Identity\Application\PermissionCatalog;
use Tests\TestCase;

final class BackupContractTest extends TestCase
{
    public function test_canonical_enums_match_the_backup_contract(): void
    {
        $this->assertSame(['database', 'full', 'pre_restore'], array_column(BackupType::cases(), 'value'));
        $this->assertSame(['queued', 'dumping', 'packaging', 'checksumming', 'encrypting', 'ready', 'publishing', 'verified', 'failed', 'deleted'], array_column(BackupState::cases(), 'value'));
        $this->assertSame(['uploaded', 'downloading', 'verifying', 'decrypting', 'compatible', 'snapshotting', 'restoring', 'migrating', 'health_checking', 'completed', 'failed', 'rolled_back'], array_column(RestoreState::cases(), 'value'));
    }

    public function test_config_contains_only_non_secret_contract_defaults_and_runtime_secret_injection_points(): void
    {
        $this->assertSame('backup-format-v1', config('backup.format.name'));
        $this->assertSame(1, config('backup.format.version'));
        $this->assertSame('.tar.zst.enc', config('backup.format.asset_suffix'));
        $this->assertSame(['storage/app/private', 'storage/app/public'], config('backup.storage.included_roots'));
        $this->assertSame('storage/app/backups', config('backup.storage.workspace'));
        $this->assertSame('AES-256-GCM', config('backup.encryption.algorithm'));
        $this->assertSame('beyourlovercom/erp-backups', config('backup.github.repository'));
        $this->assertSame('private', config('backup.github.visibility'));
        $this->assertArrayHasKey('key', config('backup.encryption'));
        $this->assertArrayHasKey('key_id', config('backup.encryption'));
        $this->assertArrayHasKey('token', config('backup.github'));
    }

    public function test_manifest_contract_requires_private_fields_and_filters_public_fields(): void
    {
        $manifest = array_fill_keys(ManifestContract::REQUIRED_FIELDS, 'value');
        $manifest['format_version'] = 1;
        $manifest['secret'] = 'must-not-be-public';

        $this->assertTrue(ManifestContract::isComplete($manifest));
        $this->assertSame(ManifestContract::PUBLIC_FIELDS, array_keys(ManifestContract::public($manifest)));
        $this->assertArrayNotHasKey('secret', ManifestContract::public($manifest));
        $this->assertSame(['storage/app/private', 'storage/app/public'], config('backup.storage.included_roots'));
        $this->assertNotContains(config('backup.storage.workspace'), config('backup.storage.included_roots'));
    }

    public function test_backup_permissions_are_in_the_authorization_enum_and_catalog(): void
    {
        $keys = [
            AuthorizationKeys::BackupView->value,
            AuthorizationKeys::BackupCreate->value,
            AuthorizationKeys::BackupDownload->value,
            AuthorizationKeys::BackupPublishGithub->value,
            AuthorizationKeys::BackupDelete->value,
            AuthorizationKeys::BackupRestore->value,
            AuthorizationKeys::BackupManage->value,
        ];

        $this->assertSame($keys, array_values(array_filter(PermissionCatalog::allKeys(), fn (string $key): bool => str_starts_with($key, 'backup.'))));
    }
}
