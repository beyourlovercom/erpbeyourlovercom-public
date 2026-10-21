<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /** @var array{super_admin:bool,permissions:array<string,true>}|null */
    private ?array $permissionStateCache = null;

    protected $fillable = ['name', 'email', 'contact_name', 'mobile', 'phone', 'qq', 'password', 'is_active', 'permission_mode'];

    protected $hidden = ['password', 'remember_token'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function customPermissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_user');
    }

    public function hasPermission(string $permission): bool
    {
        $state = $this->permissionState();

        return $state['super_admin'] || isset($state['permissions'][$permission]);
    }

    /** @return array{super_admin:bool,permissions:array<string,true>} */
    private function permissionState(): array
    {
        if ($this->permissionStateCache !== null) {
            return $this->permissionStateCache;
        }

        if ($this->permission_mode === 'custom') {
            $roles = $this->roles()->get();
            $superAdmin = $roles->contains('key', 'super_admin');
            $keys = $superAdmin ? [] : $this->customPermissions()->pluck('key')->all();
        } else {
            $roles = $this->roles()->with('permissions:id,key')->get();
            $superAdmin = $roles->contains('key', 'super_admin');
            $keys = $superAdmin
                ? []
                : $roles->flatMap(fn (Role $role) => $role->permissions->pluck('key'))->unique()->values()->all();
        }

        return $this->permissionStateCache = [
            'super_admin' => $superAdmin,
            'permissions' => array_fill_keys($keys, true),
        ];
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
