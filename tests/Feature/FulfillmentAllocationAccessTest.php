<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Logistics\Application\FulfillmentAllocationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FulfillmentAllocationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocation_access_matches_business_roles_without_opening_logistics_execution(): void
    {
        $access = app(FulfillmentAllocationAccess::class);

        $this->assertTrue($access->allows($this->userWithRole('role_preset_administrator', ['orders.edit'])));
        $this->assertTrue($access->allows($this->userWithRole('role_preset_operations', ['orders.edit'])));
        $this->assertTrue($access->allows($this->userWithRole('role_preset_logistics', ['logistics.operate'])));
        $this->assertFalse($access->allows($this->userWithRole('role_preset_kol_specialist', ['orders.edit'])));
    }

    private function userWithRole(string $roleKey, array $permissions): User
    {
        $role = Role::query()->create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
