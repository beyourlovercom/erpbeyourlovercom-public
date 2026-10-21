<?php

namespace App\Modules\Audit\Infrastructure\Models;

use App\Modules\Audit\Infrastructure\ImmutableAuditBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

class AuditRecord extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'source', 'request_id', 'ip_address', 'user_agent', 'reason', 'created_at'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    public function newEloquentBuilder($query): Builder
    {
        /** @var QueryBuilder $query */
        return new ImmutableAuditBuilder($query);
    }

    public function save(array $options = []): never
    {
        throw new LogicException($this->exists
            ? 'Audit records are immutable.'
            : 'Audit records must be created through AuditService.');
    }

    public function delete(): never
    {
        throw new LogicException('Audit records are immutable.');
    }

    protected static function booted(): void
    {
        static::creating(fn () => throw new LogicException('Audit records must be created through AuditService.'));
        static::updating(fn () => throw new LogicException('Audit records are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit records are immutable.'));
    }
}
