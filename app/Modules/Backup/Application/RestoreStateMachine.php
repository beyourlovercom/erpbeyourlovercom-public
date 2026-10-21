<?php

namespace App\Modules\Backup\Application;

use LogicException;

final class RestoreStateMachine
{
    /** @var array<string, list<RestoreState>> */
    private const TRANSITIONS = [
        'uploaded' => [RestoreState::Downloading, RestoreState::Failed],
        'downloading' => [RestoreState::Verifying, RestoreState::Failed],
        'verifying' => [RestoreState::Decrypting, RestoreState::Failed],
        'decrypting' => [RestoreState::Compatible, RestoreState::Failed],
        'compatible' => [RestoreState::Snapshotting, RestoreState::Failed],
        'snapshotting' => [RestoreState::Restoring, RestoreState::Failed],
        'restoring' => [RestoreState::Migrating, RestoreState::RolledBack, RestoreState::Failed],
        'migrating' => [RestoreState::HealthChecking, RestoreState::RolledBack, RestoreState::Failed],
        'health_checking' => [RestoreState::Completed, RestoreState::RolledBack, RestoreState::Failed],
        'completed' => [],
        'failed' => [],
        'rolled_back' => [],
    ];

    public function canTransition(RestoreState $from, RestoreState $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function transition(RestoreState $from, RestoreState $to): RestoreState
    {
        if (! $this->canTransition($from, $to)) {
            throw new LogicException("Illegal restore transition {$from->value} -> {$to->value}.");
        }

        return $to;
    }
}
