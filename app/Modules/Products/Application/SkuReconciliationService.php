<?php

namespace App\Modules\Products\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Products\Application\Contracts\SkuReconciliationRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SkuReconciliationService
{
    public function __construct(
        private readonly SkuReconciliationRepository $reconciliations,
        private readonly SkuAliasService $aliasService,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Resolve a reconciliation item: create the alias and mark the item resolved — in one transaction.
     */
    public function resolve(
        Model $item,
        int $productId,
        string $aliasSku,
        ?string $note,
        ?Authenticatable $actor,
    ): void {
        DB::transaction(function () use ($item, $productId, $aliasSku, $note, $actor): void {
            $alias = $this->aliasService->createAlias(
                productId: $productId,
                aliasSku: $aliasSku,
                sourceType: 'reconciliation',
                sourceReference: 'reconciliation:'.$item->getKey(),
                notes: $note,
                actor: $actor,
            );

            $this->reconciliations->resolve(
                $item,
                $productId,
                (int) $alias->getKey(),
                (int) $actor?->getAuthIdentifier(),
                $note,
            );

            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::Web) : AuditActor::system(AuditSource::InternalSystem),
                'product.sku_reconciliation.resolved',
                $item::class,
                $item->getKey(),
                before: ['status' => $item->getAttribute('status')],
                after: ['status' => 'resolved', 'product_id' => $productId, 'alias_sku' => $aliasSku],
                reason: 'sku_reconciliation_resolved',
            );
        });
    }

    /**
     * Mark item ignored without creating any alias or Product.
     */
    public function ignore(Model $item, ?string $note, ?Authenticatable $actor): void
    {
        DB::transaction(function () use ($item, $note, $actor): void {
            $this->reconciliations->ignore($item, (int) $actor?->getAuthIdentifier(), $note);

            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::Web) : AuditActor::system(AuditSource::InternalSystem),
                'product.sku_reconciliation.ignored',
                $item::class,
                $item->getKey(),
                before: ['status' => $item->getAttribute('status')],
                after: ['status' => 'ignored'],
                reason: 'sku_reconciliation_ignored',
            );
        });
    }

    /**
     * Reopen an ignored or resolved item back to open.
     */
    public function reopen(Model $item, ?Authenticatable $actor): void
    {
        DB::transaction(function () use ($item, $actor): void {
            $before = ['status' => $item->getAttribute('status')];
            $this->reconciliations->reopen($item);

            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::Web) : AuditActor::system(AuditSource::InternalSystem),
                'product.sku_reconciliation.reopened',
                $item::class,
                $item->getKey(),
                before: $before,
                after: ['status' => 'open'],
                reason: 'sku_reconciliation_reopened',
            );
        });
    }

    public function list(array $filters = [], int $perPage = 50): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->reconciliations->list($filters, $perPage);
    }

    public function findByMatchKey(string $matchKey): ?Model
    {
        return $this->reconciliations->findByMatchKey($matchKey);
    }
}
