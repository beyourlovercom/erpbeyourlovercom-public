<?php

namespace App\Modules\Audit\Application;

use App\Modules\Identity\Application\UserDirectory;
use Illuminate\Support\Facades\DB;

/** Read-only Product projection of Product and Product-owned evidence/identity audit truth. */
final class ProductActivityProjection
{
    public function __construct(private readonly UserDirectory $users) {}

    /** @return list<array<string,mixed>> */
    public function forProduct(int $productId, int $limit = 30): array
    {
        $productTypes = ['App\\Modules\\Products\\Infrastructure\\Models\\Product', 'product'];
        $ownedTypes = [...$productTypes, 'product_weight_evidence',
            'App\\Modules\\Products\\Infrastructure\\Models\\ProductWeightEvidence',
            'App\\Modules\\Products\\Infrastructure\\Models\\ProductSkuAlias', 'product_external_reference'];

        $records = DB::table('audit_records')->whereIn('entity_type', $ownedTypes)
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->filter(function (object $record) use ($productId, $productTypes): bool {
                if (in_array((string) $record->entity_type, $productTypes, true)) {
                    return (string) $record->entity_id === (string) $productId;
                }
                $after = $this->jsonObject($record->after ?? null);
                $before = $this->jsonObject($record->before ?? null);
                return (int) ($after['product_id'] ?? $before['product_id'] ?? 0) === $productId;
            })->take($limit)->values();

        $names = $this->users->namesForIds($records->pluck('actor_id'));
        return $records->map(fn (object $record): array => [
            'action' => (string) $record->action,
            'action_label' => $this->label((string) $record->action),
            'actor_id' => $record->actor_id !== null ? (int) $record->actor_id : null,
            'actor_name' => $record->actor_id !== null ? ($names[(string) $record->actor_id] ?? null) : null,
            'actor_label' => $this->actorLabel($record, $names),
            'source' => $record->source,
            'created_at' => $record->created_at ? (string) $record->created_at : null,
            'reason' => $record->reason,
        ])->values()->all();
    }

    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function label(string $action): string
    {
        return match ($action) {
            'product.created' => '创建产品', 'product.updated' => '更新产品', 'product.deleted' => '删除产品',
            'product.weight_evidence.created' => '创建重量证据', 'product.weight_evidence.adopted' => '采用重量证据',
            'product.weight_evidence.rejected' => '拒绝重量证据', 'product.weight.cleared' => '清除产品重量',
            'product.external_reference.created' => '创建外部引用', 'product.sku_alias.created' => '创建 SKU 别名',
            'product.sku_alias.deleted' => '删除 SKU 别名', default => $action,
        };
    }

    private function actorLabel(object $record, array $names): ?string
    {
        if ($record->actor_id !== null) return $names[(string) $record->actor_id] ?? null;
        return in_array($record->source, ['system', 'sync:outbox', 'woocommerce:inbound', 'erp:user:create'], true) ? '系统' : null;
    }
}
