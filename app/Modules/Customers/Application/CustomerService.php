<?php

namespace App\Modules\Customers\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Customers\Domain\CountryCodeNormalizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CustomerService
{
    private const CUSTOMER_CLASS = 'App\\Modules\\Customers\\Infrastructure\\Models\\Customer';

    private const SEGMENTS = ['no_purchase', 'new_customer', 'repeat_customer', 'multi_product_single_order', 'has_shipment', 'no_shipment_yet', 'active_customer', 'churn_risk', 'dormant_customer', 'high_frequency', 'high_value', 'vip'];

    private const SEGMENT_LABELS = [
        'no_purchase' => '无购买客户', 'new_customer' => '新客户', 'repeat_customer' => '复购客户',
        'multi_product_single_order' => '单次多产品客户', 'has_shipment' => '已发货客户', 'no_shipment_yet' => '尚未发货客户',
        'active_customer' => '活跃客户', 'churn_risk' => '流失风险客户', 'dormant_customer' => '沉睡客户',
        'high_frequency' => '高频客户', 'high_value' => '高价值客户', 'vip' => 'VIP客户',
    ];

    private const AUDIT_FIELDS = ['name', 'email', 'phone', 'country_code', 'language_code', 'source_type', 'owner_id', 'notes'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CustomerSegmentationQuery $segmentation,
        private readonly CustomerGroupService $groups,
    ) {}

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->query($filters)
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function count(array $filters = []): int
    {
        return (int) $this->query($filters)->count();
    }

    /** @return list<array{key:string,label:string}> */
    public function systemSegmentsFor(Model $customer): array
    {
        $customerClass = self::CUSTOMER_CLASS;
        return collect(self::SEGMENTS)
            ->filter(function (string $segment) use ($customerClass, $customer): bool {
                $query = $customerClass::query()->whereKey($customer->getKey());
                $this->applySegment($query, $segment);
                return $query->exists();
            })
            ->map(fn (string $segment): array => ['key' => $segment, 'label' => self::SEGMENT_LABELS[$segment]])
            ->values()->all();
    }

    public function allForExport(array $filters = [])
    {
        return $this->query($filters)->with('groups')->orderBy('id')->get();
    }

    private function query(array $filters = [])
    {
        $customerClass = self::CUSTOMER_CLASS;
        $archived = $filters['archived'] ?? '';
        $groupFilter = (string) ($filters['group'] ?? '');
        $segment = in_array($groupFilter, self::SEGMENTS, true) ? $groupFilter : '';
        $manualGroup = $this->groups->findForFilter($groupFilter);

        return $customerClass::query()
            ->with(['owner', 'billingAddress', 'shippingAddress'])
            ->withCount(['orders' => fn ($query) => $query->where('order_kind', 'customer')])
            ->when($archived === '', fn ($query) => $query->whereNull('archived_at'))
            ->when($archived === 'only', fn ($query) => $query->whereNotNull('archived_at'))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filters['country_code'] ?? null, fn ($query, string $country) => $query->where('country_code', strtoupper($country)))
            ->when($filters['source_type'] ?? null, fn ($query, string $source) => $query->where('source_type', $source))
            ->when($segment !== '', function ($query) use ($segment): void {
                $this->applySegment($query, $segment);
            })
            ->when($manualGroup !== null, function ($query) use ($manualGroup): void {
                $this->groups->applyFilter($query, $manualGroup);
            });
    }

    private function applySegment($query, string $segment): void
    {
        if ($this->segmentation->supports($segment)) {
            $this->segmentation->apply($query, $segment);
            return;
        }

        $validOrder = fn ($orderQuery) => $orderQuery
            ->where('order_kind', 'customer')
            ->where('order_status', '!=', 'cancelled');

        if ($segment === 'no_purchase') {
            $query->whereDoesntHave('orders', $validOrder);
            return;
        }

        if ($segment === 'new_customer') {
            $query->whereHas('orders', $validOrder, '=', 1);
            return;
        }

        if ($segment === 'repeat_customer') {
            $query->whereHas('orders', $validOrder, '>=', 2);
            return;
        }

        if ($segment === 'multi_product_single_order') {
            $query->whereExists(function ($subquery): void {
                $subquery->selectRaw('1')
                    ->from('orders')
                    ->join('order_items', 'order_items.order_id', '=', 'orders.id')
                    ->whereColumn('orders.customer_id', 'customers.id')
                    ->where('orders.order_kind', 'customer')
                    ->where('orders.order_status', '!=', 'cancelled')
                    ->whereNotNull('order_items.sku')
                    ->where('order_items.sku', '!=', '')
                    ->groupBy('orders.id')
                    ->havingRaw('COUNT(DISTINCT order_items.sku) >= 3');
            });
            return;
        }

        if ($segment === 'has_shipment') {
            $query->whereHas('orders', fn ($orderQuery) => $validOrder($orderQuery)->whereHas('shipment'));
            return;
        }

        if ($segment === 'no_shipment_yet') {
            $query->whereDoesntHave('orders', fn ($orderQuery) => $validOrder($orderQuery)->whereHas('shipment'));
        }
    }

    public function create(array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($attributes, $actor): Model {
            $customerClass = self::CUSTOMER_CLASS;
            $customer = $customerClass::create($this->normalize($attributes));
            $this->record($actor, 'customer.created', $customer, after: $this->auditData($customer), reason: 'customer_created');
            return $customer->load('owner');
        });
    }

    public function update(Model $customer, array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($customer, $attributes, $actor): Model {
            $locked = $customer->newQuery()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->auditData($locked);
            $locked->fill($this->normalize($attributes))->save();
            $after = $this->auditData($locked);
            $this->record($actor, 'customer.updated', $locked, before: $before, after: $after, reason: 'customer_corrected');
            return $locked->fresh('owner');
        });
    }

    public function archive(Model $customer, ?Authenticatable $actor): Model
    {
        return $this->setArchived($customer, $actor, true);
    }

    public function restore(Model $customer, ?Authenticatable $actor): Model
    {
        return $this->setArchived($customer, $actor, false);
    }

    private function setArchived(Model $customer, ?Authenticatable $actor, bool $archive): Model
    {
        return DB::transaction(function () use ($customer, $actor, $archive): Model {
            $locked = $customer->newQuery()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            if (($locked->archived_at !== null) === $archive) {
                return $locked;
            }
            $before = $this->auditData($locked);
            $locked->forceFill(['archived_at' => $archive ? now() : null])->save();
            $after = $this->auditData($locked);
            $action = $archive ? 'customer.archived' : 'customer.restored';
            $this->record($actor, $action, $locked, before: $before, after: $after, reason: $action);
            return $locked->fresh('owner');
        });
    }

    private function normalize(array $attributes): array
    {
        foreach (['name', 'email', 'phone', 'language_code', 'notes'] as $field) {
            if (array_key_exists($field, $attributes) && is_string($attributes[$field])) {
                $attributes[$field] = trim($attributes[$field]);
            }
        }
        if (array_key_exists('email', $attributes)) {
            $attributes['email'] = $attributes['email'] === '' ? null : strtolower($attributes['email']);
        }
        if (array_key_exists('phone', $attributes) && $attributes['phone'] === '') {
            $attributes['phone'] = null;
        }
        if (array_key_exists('country_code', $attributes)) {
            $code = $attributes['country_code'];
            $attributes['country_code'] = ($code === null || $code === '') ? null : CountryCodeNormalizer::normalize($code);
        }
        if (array_key_exists('language_code', $attributes) && $attributes['language_code'] === '') {
            $attributes['language_code'] = null;
        }
        return $attributes;
    }

    private function auditData(Model $customer): array
    {
        $data = [];
        foreach (self::AUDIT_FIELDS as $field) {
            $data[$field] = $customer->{$field};
        }
        $data['archived_at'] = $customer->archived_at?->toIso8601String();
        return $data;
    }

    private function record(?Authenticatable $actor, string $action, Model $customer, ?array $before = null, ?array $after = null, ?string $reason = null): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            $action,
            get_class($customer),
            $customer->getKey(),
            before: $before,
            after: $after,
            reason: $reason,
        );
    }
}
