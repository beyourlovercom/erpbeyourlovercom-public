<?php

namespace App\Modules\Identity\Experience\Console\Commands;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateErpUser extends Command
{
    protected $signature = 'erp:user:create';

    protected $description = 'Create an internal ERP user with an approved role';

    public function handle(AuditRecorder $audit): int
    {
        $name = $this->ask('Name');
        $email = strtolower(trim($this->ask('Email')));
        $password = $this->secret('Password');
        $password_confirmation = $this->secret('Confirm password');
        $roleChoices = array_map(fn (RolePreset $preset): string => $preset->value, RolePreset::cases());
        $roleKey = $this->choice('Role', $roleChoices);
        $validator = Validator::make(compact('name', 'email', 'password', 'password_confirmation', 'roleKey'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'password_confirmation' => ['required', 'same:password'],
            'roleKey' => ['required', Rule::in($roleChoices)],
        ], ['password.confirmed' => 'Password confirmation does not match.']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($audit, $name, $email, $password, $roleKey): void {
            $preset = RolePreset::from($roleKey);
            $role = Role::firstOrCreate(['key' => $preset->roleKey()], ['label' => $preset->label()]);
            $role->forceFill(['label' => $preset->label()])->save();

            if ($role->wasRecentlyCreated) {
                $permissionIds = collect($preset->permissions())
                    ->map(fn (string $key): int => Permission::firstOrCreate(['key' => $key], ['label' => $key])->id)
                    ->all();
                $role->permissions()->sync($permissionIds);
            }

            $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'is_active' => true]);
            $user->roles()->attach($role);
            $audit->record(
                AuditActor::system(AuditSource::UserProvisioning),
                'user.created',
                \App\Models\User::class,
                $user->id,
                after: ['name' => $name, 'email' => $email, 'role' => $roleKey],
                reason: 'administrator_provisioning',
            );
        });
        $this->info('ERP user created.');

        return self::SUCCESS;
    }
}
