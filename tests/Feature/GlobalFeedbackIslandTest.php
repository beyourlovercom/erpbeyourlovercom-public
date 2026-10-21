<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class GlobalFeedbackIslandTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_flash_is_shared_as_global_feedback_input(): void
    {
        $user = $this->userWith(['dashboard.view']);

        $this->actingAs($user)
            ->withSession(['success' => 'WooCommerce 连接成功'])
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('flash.success', 'WooCommerce 连接成功')
                ->where('flash.error', null)
                ->where('flash.warning', null)
                ->where('flash.info', null)
                ->where('flash.feedback_id', fn ($value) => is_string($value) && $value !== ''));
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'feedback-'.uniqid(), 'label' => 'Feedback Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
