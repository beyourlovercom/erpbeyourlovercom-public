<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Application\Exceptions\IdentityGuardException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class UserService
{
    private const USER_CLASS = 'App\\Modules\\Identity\\Infrastructure\\Models\\User';

    private const ROLE_CLASS = 'App\\Modules\\Identity\\Infrastructure\\Models\\Role';

    private const PERMISSION_CLASS = 'App\\Modules\\Identity\\Infrastructure\\Models\\Permission';

    private const ORDER_CLASS = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';

    private const SHIPMENT_CLASS = 'App\\Modules\\Logistics\\Infrastructure\\Models\\Shipment';

    private const MANUAL_SYNC_CLASS = 'App\\Modules\\Sync\\Infrastructure\\Models\\ManualSync';

    private const COMPANY_CLASS = 'App\\Modules\\Customers\\Infrastructure\\Models\\Company';

    private const GUARD_KEY = 'system.permissions.manage';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function list(): Collection
    {
        $userClass = self::USER_CLASS;

        return $userClass::query()->with('roles')->orderBy('name')->get();
    }

    public function find(int|string $id): Model
    {
        $userClass = self::USER_CLASS;

        return $userClass::query()->with(['roles', 'customPermissions'])->findOrFail($id);
    }

    public function create(array $attributes, RolePreset $preset, Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($attributes, $preset, $actor): Model {
            $userClass = self::USER_CLASS;
            $user = $userClass::create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => Hash::make($attributes['password']),
                'is_active' => true,
            ]);
            $role = $this->ensureRole($preset);
            $user->roles()->sync([$role->id]);
            $this->record($actor, 'user.created', $user, after: [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role' => $preset->value,
            ], reason: 'user_provisioned');

            return $user->fresh('roles');
        });
    }

    public function updateProfile(Model $user, array $attributes, Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($user, $attributes, $actor): Model {
            $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $before = ['name' => $locked->name, 'email' => $locked->email];
            $locked->fill(['name' => $attributes['name'], 'email' => $attributes['email']])->save();
            $after = ['name' => $locked->name, 'email' => $locked->email];
            $this->record(
                $actor,
                'user.updated',
                $locked,
                before: $before,
                after: $after,
                reason: $before === $after ? 'no_material_change' : 'user_profile_corrected',
            );

            return $locked->fresh('roles');
        });
    }

    public function updateSelfProfile(Model $user, array $attributes, Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($user, $attributes, $actor): Model {
            $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $fields = ['name', 'email', 'contact_name', 'mobile', 'phone', 'qq'];
            $before = collect($fields)->mapWithKeys(fn (string $field): array => [$field => $locked->{$field}])->all();
            $locked->fill(collect($attributes)->only($fields)->all())->save();
            $after = collect($fields)->mapWithKeys(fn (string $field): array => [$field => $locked->{$field}])->all();
            $this->record(
                $actor,
                'user.self_profile_updated',
                $locked,
                before: $before,
                after: $after,
                reason: $before === $after ? 'no_material_change' : 'self_profile_updated',
            );

            return $locked->fresh('roles');
        });
    }

    public function setOwnPassword(Model $user, string $password, Authenticatable $actor): void
    {
        DB::transaction(function () use ($user, $password, $actor): void {
            $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['password' => Hash::make($password)])->save();
            $this->record($actor, 'user.password_changed', $locked, reason: 'password_changed_by_user');
        });
    }

    public function setPassword(Model $user, string $password, Authenticatable $actor): void
    {
        DB::transaction(function () use ($user, $password, $actor): void {
            $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['password' => Hash::make($password)])->save();
            $this->record($actor, 'user.password_reset', $locked, reason: 'password_reset_by_administrator');
        });
    }

    public function setActive(Model $user, bool $active, Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($user, $active, $actor): Model {
            $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $active && $this->isSameUser($locked, $actor)) {
                throw IdentityGuardException::selfDisable();
            }

            if ($locked->is_active === $active) {
                return $locked;
            }

            if (! $active) {
                // guardLastPermissionsHolder checks other active users (before this one is deactivated)
                $this->guardLastPermissionsHolder($locked);
            }

            $before = ['is_active' => $locked->is_active];
            $locked->forceFill(['is_active' => $active])->save();
            $after = ['is_active' => $locked->is_active];
            $this->record($actor, $active ? 'user.enabled' : 'user.disabled', $locked, before: $before, after: $after, reason: $active ? 'user_enabled' : 'user_disabled');

            return $locked->fresh('roles');
        });
    }

    public function setRolePreset(Model $user, RolePreset $preset, Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($user, $preset, $actor): Model {
            $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->rolePresetValue($locked);
            $role = $this->ensureRole($preset);
            $locked->roles()->sync([$role->id]);
            $this->guardTotalPermissionsHolders($locked);
            $after = $preset->value;
            $this->record($actor, 'user.role_changed', $locked, before: ['role' => $before], after: ['role' => $after], reason: 'role_preset_assigned');

            return $locked->fresh('roles');
        });
    }

    /**
     * @param list<string> $permissionKeys
     */
    public function setPermissions(Model $user, string $mode, array $permissionKeys, Authenticatable $actor): Model
    {
        if (! in_array($mode, ['role', 'custom'], true)) {
            throw new \InvalidArgumentException('Invalid permission mode.');
        }

        if ($mode === 'custom' && ! PermissionCatalog::validate($permissionKeys)) {
            throw new \InvalidArgumentException('Invalid permission key(s).');
        }

        return DB::transaction(function () use ($user, $mode, $permissionKeys, $actor): Model {
            $userClass = self::USER_CLASS;
            $permissionClass = self::PERMISSION_CLASS;

            $locked = $userClass::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $beforeMode = $locked->permission_mode;
            $beforeKeys = $locked->customPermissions()->pluck('key')->sort()->values()->all();

            $locked->forceFill(['permission_mode' => $mode])->save();

            if ($mode === 'custom') {
                $ids = collect($permissionKeys)
                    ->map(fn (string $key): int => $permissionClass::query()->firstOrCreate(['key' => $key], ['label' => $key])->id)
                    ->all();
                $locked->customPermissions()->sync($ids);
            } else {
                $locked->customPermissions()->detach();
            }

            $this->guardTotalPermissionsHolders($locked);

            $afterKeys = $mode === 'custom' ? collect($permissionKeys)->sort()->values()->all() : [];

            $this->record($actor, 'user.permissions_updated', $locked,
                before: ['mode' => $beforeMode, 'permissions' => $beforeKeys],
                after: ['mode' => $mode, 'permissions' => $afterKeys],
                reason: 'user_permissions_updated',
            );

            return $locked->fresh(['roles', 'customPermissions']);
        });
    }

    public function delete(Model $user, Authenticatable $actor): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($this->isSameUser($locked, $actor)) {
                throw IdentityGuardException::selfDelete();
            }

            if ($this->hasBusinessReferences($locked)) {
                throw IdentityGuardException::hasBusinessReferences();
            }

            $this->guardLastPermissionsHolder($locked);

            $before = ['name' => $locked->name, 'email' => $locked->email];
            $id = $locked->getKey();
            $locked->roles()->detach();
            $locked->customPermissions()->detach();
            $locked->delete();
            $this->record($actor, 'user.deleted', $locked, before: $before, reason: 'user_deleted', entityId: $id);
        });
    }

    public function hasBusinessReferences(Model $user): bool
    {
        $orderClass = self::ORDER_CLASS;
        $shipmentClass = self::SHIPMENT_CLASS;
        $manualSyncClass = self::MANUAL_SYNC_CLASS;
        $companyClass = self::COMPANY_CLASS;
        $id = $user->getKey();

        return $orderClass::query()->where('owner_id', $id)->exists()
            || $shipmentClass::query()->where('handled_by', $id)->exists()
            || $manualSyncClass::query()->where('synced_by', $id)->exists()
            || $companyClass::query()->where('owner_id', $id)->exists();
    }

    /** Seed permissions only for newly created roles; leave existing role templates untouched. */
    private function ensureRole(RolePreset $preset): Model
    {
        $roleClass = self::ROLE_CLASS;
        $permissionClass = self::PERMISSION_CLASS;

        $role = $roleClass::query()->firstOrCreate(['key' => $preset->roleKey()], ['label' => $preset->label()]);
        $role->forceFill(['label' => $preset->label()])->save();

        if ($role->wasRecentlyCreated) {
            $permissionIds = collect($preset->permissions())
                ->map(fn (string $key): int => $permissionClass::query()->firstOrCreate(['key' => $key], ['label' => $key])->id)
                ->all();
            $role->permissions()->sync($permissionIds);
        }

        return $role;
    }

    /**
     * Called BEFORE deactivating/deleting this user.
     * Ensures at least one OTHER active user retains system.permissions.manage.
     */
    private function guardLastPermissionsHolder(Model $user): void
    {
        if (! $user->is_active) {
            return;
        }

        if (! $user->hasPermission(self::GUARD_KEY)) {
            return;
        }

        $userClass = self::USER_CLASS;

        $otherHolders = $userClass::query()
            ->where('is_active', true)
            ->where('id', '!=', $user->getKey())
            ->get()
            ->filter(fn (Model $u): bool => $u->hasPermission(self::GUARD_KEY))
            ->count();

        if ($otherHolders === 0) {
            throw IdentityGuardException::lastPermissionsHolder();
        }
    }

    /**
     * Called AFTER permissions/roles have been mutated in the current transaction.
     * Ensures at least one active user (including this one, with new permissions) retains the guard key.
     */
    private function guardTotalPermissionsHolders(Model $user): void
    {
        $userClass = self::USER_CLASS;
        $user->unsetRelation('roles');
        $user->unsetRelation('customPermissions');

        $total = $userClass::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Model $u): bool => $u->hasPermission(self::GUARD_KEY))
            ->count();

        if ($total === 0) {
            throw IdentityGuardException::lastPermissionsHolder();
        }
    }

    private function rolePresetValue(Model $user): ?string
    {
        $role = $user->roles()->first();

        return $role ? RolePreset::fromRoleKey($role->key)?->value ?? $role->key : null;
    }

    private function isSameUser(Model $user, Authenticatable $actor): bool
    {
        return (string) $user->getKey() === (string) $actor->getAuthIdentifier();
    }

    private function record(Authenticatable $actor, string $action, Model $user, ?array $before = null, ?array $after = null, ?string $reason = null, int|string|null $entityId = null): void
    {
        $this->audit->record(
            AuditActor::user($actor, AuditSource::InternalSystem),
            $action,
            get_class($user),
            $entityId ?? $user->getKey(),
            before: $before,
            after: $after,
            reason: $reason,
        );
    }
}
