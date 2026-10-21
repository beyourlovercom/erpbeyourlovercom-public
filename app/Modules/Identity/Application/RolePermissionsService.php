<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Application\Exceptions\IdentityGuardException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class RolePermissionsService
{
    private const ROLE_CLASS = 'App\\Modules\\Identity\\Infrastructure\\Models\\Role';

    private const PERMISSION_CLASS = 'App\\Modules\\Identity\\Infrastructure\\Models\\Permission';

    private const USER_CLASS = 'App\\Modules\\Identity\\Infrastructure\\Models\\User';

    private const GUARD_KEY = 'system.permissions.manage';

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return list<array{preset: string, label: string, permissions: list<string>}> */
    public function allPresets(): array
    {
        return array_map(function (RolePreset $preset): array {
            $roleClass = self::ROLE_CLASS;
            $role = $roleClass::query()->where('key', $preset->roleKey())->first();
            $permissions = $role ? $role->permissions()->pluck('key')->sort()->values()->all() : $preset->permissions();

            return [
                'preset' => $preset->value,
                'label' => $preset->label(),
                'permissions' => $permissions,
            ];
        }, RolePreset::cases());
    }

    /**
     * @param list<string> $permissionKeys
     */
    public function updatePreset(RolePreset $preset, array $permissionKeys, Authenticatable $actor): void
    {
        if (! PermissionCatalog::validate($permissionKeys)) {
            throw new \InvalidArgumentException('Invalid permission key(s).');
        }

        DB::transaction(function () use ($preset, $permissionKeys, $actor): void {
            $roleClass = self::ROLE_CLASS;
            $permissionClass = self::PERMISSION_CLASS;

            $role = $roleClass::query()->firstOrCreate(['key' => $preset->roleKey()], ['label' => $preset->label()]);
            $role->forceFill(['label' => $preset->label()])->save();

            $beforeKeys = $role->permissions()->pluck('key')->sort()->values()->all();

            $ids = collect($permissionKeys)
                ->map(fn (string $key): int => $permissionClass::query()->firstOrCreate(['key' => $key], ['label' => $key])->id)
                ->all();
            $role->permissions()->sync($ids);

            $this->guardLastPermissionsHolder($role);

            $afterKeys = collect($permissionKeys)->sort()->values()->all();

            $this->audit->record(
                AuditActor::user($actor, AuditSource::InternalSystem),
                'role_template.permissions_updated',
                get_class($role),
                $role->getKey(),
                before: ['role' => $preset->value, 'permissions' => $beforeKeys],
                after: ['role' => $preset->value, 'permissions' => $afterKeys],
                reason: 'role_template_permissions_updated',
            );
        });
    }

    /** Ensure at least one active user retains system.permissions.manage after this role-template change. */
    private function guardLastPermissionsHolder(Model $role): void
    {
        $userClass = self::USER_CLASS;

        $holderCount = $userClass::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Model $u): bool => $u->hasPermission(self::GUARD_KEY))
            ->count();

        if ($holderCount === 0) {
            throw IdentityGuardException::lastPermissionsHolder();
        }
    }
}
