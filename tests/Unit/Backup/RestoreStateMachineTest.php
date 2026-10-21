<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\RestoreState;
use App\Modules\Backup\Application\RestoreStateMachine;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RestoreStateMachineTest extends TestCase
{
    public function test_happy_path_requires_every_verification_and_snapshot_phase(): void
    {
        $machine = new RestoreStateMachine();
        $states = [
            RestoreState::Uploaded,
            RestoreState::Downloading,
            RestoreState::Verifying,
            RestoreState::Decrypting,
            RestoreState::Compatible,
            RestoreState::Snapshotting,
            RestoreState::Restoring,
            RestoreState::Migrating,
            RestoreState::HealthChecking,
            RestoreState::Completed,
        ];
        for ($i = 0; $i < count($states) - 1; $i++) {
            self::assertTrue($machine->canTransition($states[$i], $states[$i + 1]));
        }
    }

    public function test_skipping_snapshot_or_verification_is_rejected(): void
    {
        $machine = new RestoreStateMachine();
        self::assertFalse($machine->canTransition(RestoreState::Downloading, RestoreState::Decrypting));
        self::assertFalse($machine->canTransition(RestoreState::Compatible, RestoreState::Restoring));
        $this->expectException(LogicException::class);
        $machine->transition(RestoreState::Compatible, RestoreState::Restoring);
    }

    public function test_rollback_is_only_available_after_destructive_restore_begins(): void
    {
        $machine = new RestoreStateMachine();
        self::assertFalse($machine->canTransition(RestoreState::Snapshotting, RestoreState::RolledBack));
        self::assertTrue($machine->canTransition(RestoreState::Restoring, RestoreState::RolledBack));
        self::assertTrue($machine->canTransition(RestoreState::Migrating, RestoreState::RolledBack));
        self::assertTrue($machine->canTransition(RestoreState::HealthChecking, RestoreState::RolledBack));
    }
}
