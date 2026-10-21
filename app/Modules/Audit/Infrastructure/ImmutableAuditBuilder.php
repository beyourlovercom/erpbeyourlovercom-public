<?php

namespace App\Modules\Audit\Infrastructure;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

final class ImmutableAuditBuilder extends Builder
{
    public function insert(array $values): never
    {
        throw new LogicException('Audit records must be created through AuditService.');
    }

    public function insertOrIgnore(array $values): never
    {
        throw new LogicException('Audit records must be created through AuditService.');
    }

    public function insertGetId(array $values, $sequence = null): never
    {
        throw new LogicException('Audit records must be created through AuditService.');
    }

    public function update(array $values): never
    {
        $this->rejectMutation();
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        $this->rejectMutation();
    }

    public function delete(): never
    {
        $this->rejectMutation();
    }

    public function forceDelete(): never
    {
        $this->rejectMutation();
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        $this->rejectMutation();
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        $this->rejectMutation();
    }

    public function incrementEach(array $columns, array $extra = []): never
    {
        $this->rejectMutation();
    }

    public function decrementEach(array $columns, array $extra = []): never
    {
        $this->rejectMutation();
    }

    private function rejectMutation(): never
    {
        throw new LogicException('Audit records are immutable.');
    }
}
