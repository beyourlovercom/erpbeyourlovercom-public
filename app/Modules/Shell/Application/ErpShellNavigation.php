<?php

namespace App\Modules\Shell\Application;

use App\Modules\Inventory\Application\Contracts\InventoryRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

final class ErpShellNavigation
{
    public function __construct(private readonly InventoryRepository $inventory) {}

    public function for(Authenticatable $user): array
    {
        return collect(config('erp_shell_navigation'))
            ->filter(fn (array $item) => $this->allowed($user, $item))
            ->map(function (array $item) use ($user): array {
                $serialized = $this->serialize($user, $item);
                if ($item['key'] === 'inventory') {
                    $serialized['children'] = $this->withWarehouseShortcuts($serialized['children']);
                }

                return $serialized;
            })
            ->values()->all();
    }

    private function withWarehouseShortcuts(array $children): array
    {
        $shortcuts = collect($this->inventory->warehouseList())
            ->filter(fn (array $warehouse): bool => (bool) ($warehouse['is_active'] ?? false))
            ->map(fn (array $warehouse): array => [
                'key' => 'inventory-warehouse-'.$warehouse['id'],
                'label' => $warehouse['name'],
                'emoji' => null,
                'path' => '/inventory/'.$warehouse['slug'],
                'route' => 'inventory.warehouse.show',
                'placeholder' => false,
                'group' => false,
                'children' => [],
            ])
            ->values()->all();

        $result = [];
        foreach ($children as $child) {
            $result[] = $child;
            if ($child['key'] === 'inventory-list') {
                $result = [...$result, ...$shortcuts];
            }
        }

        return $result;
    }

    private function allowed(Authenticatable $user, array $item): bool
    {
        $permissionsAny = array_values(array_filter([
            $item['permission'] ?? null,
            ...($item['permissions_any'] ?? []),
        ]));

        if ($permissionsAny !== [] && ! collect($permissionsAny)->contains(fn (string $permission) => Gate::forUser($user)->allows($permission))) {
            // Only explicitly opted-in modules may be exposed through a narrower
            // authorized child. This avoids leaking unrelated prototype-backed
            // modules when a user lacks the parent module permission.
            if (! ($item['allow_child_entry'] ?? false)
                || empty($item['children'])
                || ! collect($item['children'])->contains(fn (array $child) => $this->allowed($user, $child))) {
                return false;
            }
        }

        foreach ($item['permissions_all'] ?? [] as $permission) {
            if (! Gate::forUser($user)->allows($permission)) {
                return false;
            }
        }

        return true;
    }

    private function serialize(Authenticatable $user, array $item): array
    {
        $children = collect($item['children'] ?? [])
            ->filter(fn (array $child) => empty($child['permission']) || $this->allowed($user, $child))
            ->map(fn (array $child) => $this->serialize($user, $child))
            ->values()->all();

        return [
            'key' => $item['key'],
            'label' => $item['label'],
            'emoji' => $item['emoji'] ?? null,
            'path' => $item['path'],
            'route' => $item['route'],
            'placeholder' => $item['placeholder'],
            'group' => $item['group'] ?? false,
            'children' => $children,
        ];
    }
}
