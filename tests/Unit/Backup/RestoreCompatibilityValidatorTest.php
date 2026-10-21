<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\Contracts\RestoreCompatibility;
use App\Modules\Backup\Application\RestoreCompatibilityValidator;
use App\Modules\Backup\Application\RestoreFailure;
use PHPUnit\Framework\TestCase;

final class RestoreCompatibilityValidatorTest extends TestCase
{
    public function test_exact_compatibility_passes(): void
    {
        $validator = new RestoreCompatibilityValidator($this->current());
        self::assertSame([], $validator->reasonCodes($this->manifest()));
        $validator->assertCompatible($this->manifest());
        self::assertTrue(true);
    }

    public function test_each_material_mismatch_has_a_deterministic_reason_code(): void
    {
        $cases = [
            'format_mismatch' => ['backup_format_version' => 2],
            'git_sha_mismatch' => ['git_sha' => str_repeat('b', 40)],
            'database_engine_mismatch' => ['database_engine' => 'pgsql'],
            'latest_migration_mismatch' => ['latest_migration' => '2026_02'],
            'migration_count_mismatch' => ['migration_count' => 2],
        ];
        foreach ($cases as $reason => $change) {
            $manifest = array_replace($this->manifest(), $change);
            self::assertContains(
                $reason,
                (new RestoreCompatibilityValidator($this->current()))->reasonCodes($manifest),
            );
        }
    }

    public function test_unknown_current_git_sha_fails_closed(): void
    {
        $validator = new RestoreCompatibilityValidator($this->current(['git' => 'unknown']));
        self::assertContains('current_git_sha_unknown', $validator->reasonCodes($this->manifest()));
        $this->expectException(RestoreFailure::class);
        $validator->assertCompatible($this->manifest());
    }

    public function test_invalid_manifest_git_sha_and_migration_ledger_fail_closed(): void
    {
        $manifest = array_replace($this->manifest(), [
            'git_sha' => 'unknown',
            'migration_count' => '1',
        ]);
        $reasons = (new RestoreCompatibilityValidator($this->current()))->reasonCodes($manifest);
        self::assertContains('git_sha_invalid', $reasons);
        self::assertContains('migration_ledger_invalid', $reasons);
    }

    private function current(array $overrides = []): RestoreCompatibility
    {
        $values = array_replace([
            'git' => str_repeat('a', 40),
            'engine' => 'mysql',
            'latest' => '2026_01',
            'count' => 1,
        ], $overrides);

        return new class($values) implements RestoreCompatibility {
            public function __construct(private array $values) {}
            public function gitSha(): string { return $this->values['git']; }
            public function databaseEngine(): string { return $this->values['engine']; }
            public function latestMigration(): ?string { return $this->values['latest']; }
            public function migrationCount(): int { return $this->values['count']; }
        };
    }

    private function manifest(): array
    {
        return [
            'backup_format_version' => 1,
            'git_sha' => str_repeat('a', 40),
            'database_engine' => 'mysql',
            'latest_migration' => '2026_01',
            'migration_count' => 1,
        ];
    }
}
