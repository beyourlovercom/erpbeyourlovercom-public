<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\BackupEngineService;
use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\BackupType;
use LogicException;
use Tests\TestCase;

final class BackupEngineStateTest extends TestCase
{
    public function test_terminal_state_cannot_be_reentered(): void
    {
        $run = new BackupRunData(1, 'run', BackupType::Database, BackupState::Ready);
        $engine = (new \ReflectionClass(BackupEngineService::class))->newInstanceWithoutConstructor();
        $this->expectException(LogicException::class); $engine->transition($run, BackupState::Encrypting);
    }

    public function test_ready_can_transition_to_publishing_for_m1_3(): void
    {
        $this->assertTrue(BackupEngineService::canTransition(BackupState::Ready, BackupState::Publishing));
        $this->assertFalse(BackupEngineService::canTransition(BackupState::Ready, BackupState::Encrypting));
    }

    public function test_publishing_can_return_to_ready_for_retry(): void
    {
        $this->assertTrue(BackupEngineService::canTransition(BackupState::Publishing, BackupState::Ready));
    }
}
