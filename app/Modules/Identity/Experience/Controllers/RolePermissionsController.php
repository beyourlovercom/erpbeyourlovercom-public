<?php

namespace App\Modules\Identity\Experience\Controllers;

use App\Modules\Identity\Application\Exceptions\IdentityGuardException;
use App\Modules\Identity\Application\PermissionCatalog;
use App\Modules\Identity\Application\RolePermissionsService;
use App\Modules\Identity\Application\RolePreset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class RolePermissionsController
{
    public function __construct(private readonly RolePermissionsService $rolePermissions) {}

    public function index(): Response
    {
        return Inertia::render('Identity/RolesPermissions/Index', [
            'presets' => $this->rolePermissions->allPresets(),
            'permissionCatalog' => PermissionCatalog::groups(),
        ]);
    }

    public function update(Request $request, string $preset): RedirectResponse
    {
        $presetEnum = RolePreset::tryFrom($preset);
        if ($presetEnum === null) {
            throw ValidationException::withMessages(['preset' => '无效的角色预设。']);
        }

        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        $keys = $data['permissions'];

        if (! PermissionCatalog::validate($keys)) {
            throw ValidationException::withMessages(['permissions' => '包含无效的权限键。']);
        }

        try {
            $this->rolePermissions->updatePreset($presetEnum, $keys, $request->user());
        } catch (IdentityGuardException $exception) {
            throw ValidationException::withMessages(['permissions' => $exception->getMessage()]);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['permissions' => $exception->getMessage()]);
        }

        return redirect()->route('settings.roles-permissions')->with('success', '角色权限已更新');
    }
}
