<?php

namespace App\Modules\Sync\Infrastructure\Models;

use App\Modules\Sync\Application\SyncOutboxStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

class SyncOutbox extends Model
{
    protected $table = 'sync_outbox';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => SyncOutboxStatus::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function newEloquentBuilder($query): Builder
    {
        /** @var QueryBuilder $query */
        return new SyncOutboxBuilder($query);
    }

    public function fill(array $attributes)
    {
        if ($attributes !== []) {
            throw new LogicException('Sync outbox records must be changed through SyncOutboxService.');
        }

        return parent::fill($attributes);
    }

    public function save(array $options = []): never
    {
        throw new LogicException($this->exists
            ? 'Sync outbox records must be changed through SyncOutboxService.'
            : 'Sync outbox records must be created through SyncOutboxService.');
    }

    public function delete(): never
    {
        throw new LogicException('Sync outbox records must be changed through SyncOutboxService.');
    }

    protected static function booted(): void
    {
        static::creating(fn () => throw new LogicException('Sync outbox records must be created through SyncOutboxService.'));
        static::updating(fn () => throw new LogicException('Sync outbox records must be changed through SyncOutboxService.'));
        static::deleting(fn () => throw new LogicException('Sync outbox records must be changed through SyncOutboxService.'));
    }
}

final class SyncOutboxBuilder extends Builder
{
    public function insert(array $values): never
    {
        $this->rejectCreate();
    }

    public function insertOrIgnore(array $values): never
    {
        $this->rejectCreate();
    }

    public function insertGetId(array $values, $sequence = null): never
    {
        $this->rejectCreate();
    }

    public function insertUsing(array $columns, $query): never
    {
        $this->rejectCreate();
    }

    public function insertOrIgnoreUsing(array $columns, $query): never
    {
        $this->rejectCreate();
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        $this->rejectMutation();
    }

    public function update(array $values): never
    {
        $this->rejectMutation();
    }

    public function updateFrom(array $values): never
    {
        $this->rejectMutation();
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): never
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

    public function truncate(): never
    {
        $this->rejectMutation();
    }

    private function rejectCreate(): never
    {
        throw new LogicException('Sync outbox records must be created through SyncOutboxService.');
    }

    private function rejectMutation(): never
    {
        throw new LogicException('Sync outbox records must be changed through SyncOutboxService.');
    }
}
