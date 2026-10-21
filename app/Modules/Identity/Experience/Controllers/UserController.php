<?php

namespace App\Modules\Identity\Experience\Controllers;

use App\Modules\Identity\Application\Exceptions\IdentityGuardException;
use App\Modules\Identity\Application\PermissionCatalog;
use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Application\UserService;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class UserController
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): Response
    {
        $users = $this->users->list();

        return Inertia::render('Identity/Users/Index', [
            'users' => $users->map(fn (User $user): array => $this->userData($user))->values()->all(),
            'rolePresets' => RolePreset::options(),
            'selfId' => $request->user()?->getAuthIdentifier(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Identity/Users/Create', [
            'rolePresets' => RolePreset::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->createRules(), $this->messages());
        $preset = RolePreset::from($data['role']);
        $this->users->create($data, $preset, $request->user());

        return redirect()->route('user.index')->with('success', '用户已创建');
    }

    public function edit(Request $request, User $user): Response
    {
        $user->loadMissing(['roles', 'customPermissions']);

        return Inertia::render('Identity/Users/Edit', [
            'user' => $this->userData($user),
            'rolePresets' => RolePreset::options(),
            'isSelf' => $request->user()?->is($user) ?? false,
            'permissionCatalog' => PermissionCatalog::groups(),
            'rolePermissions' => $this->effectiveRolePermissions($user),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate($this->updateRules($user), $this->messages());
        $this->users->updateProfile($user, $data, $request->user());

        return redirect()->route('user.index')->with('success', '用户信息已更新');
    }

    public function setPassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate($this->passwordRules(), $this->messages());
        $this->users->setPassword($user, $data['password'], $request->user());

        return redirect()->route('user.index')->with('success', '密码已更新');
    }

    public function setRole(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate($this->roleRules(), $this->messages());
        try {
            $this->users->setRolePreset($user, RolePreset::from($data['role']), $request->user());
        } catch (IdentityGuardException $exception) {
            throw ValidationException::withMessages(['user' => $exception->getMessage()]);
        }

        return redirect()->route('user.index')->with('success', '角色已更新');
    }

    public function updatePermissions(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['role', 'custom'])],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $mode = $data['mode'];
        $keys = $data['permissions'] ?? [];

        if ($mode === 'custom' && ! PermissionCatalog::validate($keys)) {
            throw ValidationException::withMessages(['permissions' => '包含无效的权限键。']);
        }

        try {
            $this->users->setPermissions($user, $mode, $keys, $request->user());
        } catch (IdentityGuardException $exception) {
            throw ValidationException::withMessages(['user' => $exception->getMessage()]);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['permissions' => $exception->getMessage()]);
        }

        return redirect()->route('user.edit', $user)->with('success', '权限已更新');
    }

    public function enable(Request $request, User $user): RedirectResponse
    {
        $this->users->setActive($user, true, $request->user());

        return redirect()->route('user.index')->with('success', '用户已启用');
    }

    public function disable(Request $request, User $user): RedirectResponse
    {
        try {
            $this->users->setActive($user, false, $request->user());
        } catch (IdentityGuardException $exception) {
            throw ValidationException::withMessages(['user' => $exception->getMessage()]);
        }

        return redirect()->route('user.index')->with('success', '用户已停用');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        try {
            $this->users->delete($user, $request->user());
        } catch (IdentityGuardException $exception) {
            throw ValidationException::withMessages(['user' => $exception->getMessage()]);
        }

        return redirect()->route('user.index')->with('success', '用户已删除');
    }

    private function userData(User $user): array
    {
        $role = $user->roles->first();
        $preset = $role ? RolePreset::fromRoleKey($role->key) : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'permission_mode' => $user->permission_mode ?? 'role',
            'custom_permissions' => $user->relationLoaded('customPermissions')
                ? $user->customPermissions->pluck('key')->values()->all()
                : [],
            'role' => $role ? ['key' => $role->key, 'label' => $role->label, 'preset' => $preset?->value] : null,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function effectiveRolePermissions(User $user): array
    {
        $role = $user->roles->first();
        if (! $role) {
            return [];
        }

        return $role->permissions()->pluck('key')->values()->all();
    }

    private function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role' => ['required', Rule::in(array_column(RolePreset::options(), 'value'))],
        ];
    }

    private function updateRules(User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ];
    }

    private function passwordRules(): array
    {
        return [
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ];
    }

    private function roleRules(): array
    {
        return [
            'role' => ['required', Rule::in(array_column(RolePreset::options(), 'value'))],
        ];
    }

    private function messages(): array
    {
        return [
            'name.required' => '姓名为必填项。',
            'name.string' => '姓名格式不正确。',
            'name.max' => '姓名不能超过 255 个字符。',
            'email.required' => '邮箱为必填项。',
            'email.email' => '请输入有效的邮箱地址。',
            'email.max' => '邮箱不能超过 255 个字符。',
            'email.unique' => '该邮箱已被使用。',
            'password.required' => '密码为必填项。',
            'password.string' => '密码格式不正确。',
            'password.min' => '密码至少需要 12 个字符。',
            'password.confirmed' => '两次输入的密码不一致。',
            'role.required' => '请选择角色。',
            'role.in' => '所选角色无效。',
        ];
    }
}
