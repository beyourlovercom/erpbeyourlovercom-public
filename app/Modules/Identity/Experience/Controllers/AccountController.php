<?php

namespace App\Modules\Identity\Experience\Controllers;

use App\Modules\Identity\Application\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

final class AccountController
{
    public function __construct(private readonly UserService $users) {}

    public function profile(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Identity/Account/Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'contact_name' => $user->contact_name,
                'mobile' => $user->mobile,
                'phone' => $user->phone,
                'qq' => $user->qq,
            ],
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:64'],
            'qq' => ['nullable', 'string', 'max:64'],
        ]);

        $this->users->updateSelfProfile($user, $data, $user);

        return back()->with('success', '个人信息已更新');
    }

    public function password(): Response
    {
        return Inertia::render('Identity/Account/Password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ], [
            'current_password.current_password' => '原密码不正确。',
            'password.confirmed' => '两次输入的新密码不一致。',
        ]);

        $this->users->setOwnPassword($request->user(), $data['password'], $request->user());

        return back()->with('success', '密码已更新');
    }
}
