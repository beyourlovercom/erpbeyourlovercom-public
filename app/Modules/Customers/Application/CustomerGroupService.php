<?php

namespace App\Modules\Customers\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CustomerGroupService
{
    public const MANUAL_FILTER_PREFIX = 'manual:';
    private const GROUP_CLASS = 'App\\Modules\\Customers\\Infrastructure\\Models\\CustomerGroup';
    private const CUSTOMER_CLASS = 'App\\Modules\\Customers\\Infrastructure\\Models\\Customer';

    public function all(): Collection
    {
        $groupClass = self::GROUP_CLASS;
        return $groupClass::query()
            ->withCount('memberships')
            ->orderBy('name')->orderBy('id')->get();
    }

    public function findForFilter(?string $filter): ?Model
    {
        if (! is_string($filter) || ! str_starts_with($filter, self::MANUAL_FILTER_PREFIX)) {
            return null;
        }

        $id = substr($filter, strlen(self::MANUAL_FILTER_PREFIX));
        $groupClass = self::GROUP_CLASS;
        return ctype_digit($id) && (int) $id > 0 ? $groupClass::find((int) $id) : null;
    }

    public function applyFilter(Builder $query, Model $group): void
    {
        $query->whereHas('groupMemberships', fn (Builder $membership) => $membership->where('customer_group_id', $group->id));
    }

    public function create(array $attributes): Model
    {
        $groupClass = self::GROUP_CLASS;
        return $groupClass::query()->create($attributes);
    }

    public function update(Model $group, array $attributes): Model
    {
        $group->fill($attributes)->save();
        return $group->fresh()->loadCount('memberships');
    }

    public function delete(Model $group): void
    {
        DB::transaction(function () use ($group): void {
            $group->memberships()->delete();
            $group->delete();
        });
    }

    public function addMembers(Model $group, array $customerIds, ?Authenticatable $actor): int
    {
        $customerClass = self::CUSTOMER_CLASS;
        $ids = $customerClass::query()->whereIn('id', $customerIds)->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }

        $now = now();
        $rows = array_map(fn (int $customerId): array => [
            'customer_group_id' => $group->id,
            'customer_id' => $customerId,
            'added_by' => $actor?->getAuthIdentifier(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids);

        return DB::table('customer_group_memberships')->insertOrIgnore($rows);
    }

    public function removeMember(Model $group, Model $customer): void
    {
        $group->memberships()->where('customer_id', $customer->id)->delete();
    }

    public function searchAvailableCustomers(Model $group, string $search, int $limit = 25): Collection
    {
        $customerClass = self::CUSTOMER_CLASS;
        return $customerClass::query()
            ->whereNull('archived_at')
            ->whereDoesntHave('groupMemberships', fn (Builder $query) => $query->where('customer_group_id', $group->id))
            ->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            })
            ->orderBy('name')->orderBy('id')->limit($limit)->get(['id', 'name', 'email', 'phone']);
    }
}
