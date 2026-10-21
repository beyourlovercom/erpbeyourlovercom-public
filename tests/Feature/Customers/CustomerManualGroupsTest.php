<?php

namespace Tests\Feature\Customers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CustomerManualGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_crud_groups_and_reader_can_only_view_them(): void
    {
        $operator = $this->userWith(['customers.view', 'customers.operate']);
        $reader = $this->userWith(['customers.view'], 'customer_reader');

        $this->actingAs($reader)->get('/customers/groups')->assertOk();
        $this->actingAs($reader)->post('/customers/groups', ['name' => 'Forbidden'])->assertForbidden();

        $this->actingAs($operator)->post('/customers/groups', ['name' => 'VIP Manual', 'description' => 'Selected customers'])
            ->assertRedirect();
        $group = CustomerGroup::query()->firstOrFail();
        $this->actingAs($operator)->patch('/customers/groups/'.$group->id, ['name' => 'VIP Updated', 'description' => null])
            ->assertRedirect();
        $this->assertDatabaseHas('customer_groups', ['id' => $group->id, 'name' => 'VIP Updated']);
        $this->actingAs($reader)->delete('/customers/groups/'.$group->id)->assertForbidden();
        $this->actingAs($operator)->delete('/customers/groups/'.$group->id)->assertRedirect();
        $this->assertDatabaseMissing('customer_groups', ['id' => $group->id]);
    }

    public function test_membership_add_remove_is_idempotent_and_group_delete_is_customer_safe(): void
    {
        $operator = $this->userWith(['customers.view', 'customers.operate']);
        $group = CustomerGroup::query()->create(['name' => 'Manual']);
        $customer = Customer::query()->create(['name' => 'Member', 'source_type' => 'manual']);

        $payload = ['customer_ids' => [$customer->id, $customer->id]];
        $this->actingAs($operator)->post('/customers/groups/'.$group->id.'/members', $payload)->assertSessionHasErrors('customer_ids.0');
        $this->actingAs($operator)->post('/customers/groups/'.$group->id.'/members', ['customer_ids' => [$customer->id]])->assertRedirect();
        $this->actingAs($operator)->post('/customers/groups/'.$group->id.'/members', ['customer_ids' => [$customer->id]])->assertRedirect();
        $this->assertDatabaseCount('customer_group_memberships', 1);

        $this->actingAs($operator)->delete('/customers/groups/'.$group->id.'/members/'.$customer->id)->assertRedirect();
        $this->actingAs($operator)->delete('/customers/groups/'.$group->id.'/members/'.$customer->id)->assertRedirect();
        $this->assertDatabaseCount('customer_group_memberships', 0);

        $this->actingAs($operator)->post('/customers/groups/'.$group->id.'/members', ['customer_ids' => [$customer->id]]);
        $group->delete();
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseCount('customer_group_memberships', 0);
    }

    public function test_customer_deletion_cascades_membership_and_manual_filter_is_persistent(): void
    {
        $operator = $this->userWith(['customers.view', 'customers.operate']);
        $group = CustomerGroup::query()->create(['name' => 'Stable Group']);
        $member = Customer::query()->create(['name' => 'Stable Member', 'source_type' => 'manual']);
        $other = Customer::query()->create(['name' => 'Other', 'source_type' => 'manual']);

        $this->actingAs($operator)->post('/customers/groups/'.$group->id.'/members', ['customer_ids' => [$member->id]])->assertRedirect();
        $this->actingAs($operator)->get('/customers/groups?group=manual:'.$group->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedManualGroup.name', 'Stable Group')
                ->where('customers.data.0.id', $member->id)
                ->where('customers.total', 1));

        DB::table('orders')->insert([
            'internal_order_number' => 'GROUP-ORDER', 'source_type' => 'manual', 'order_kind' => 'customer',
            'external_order_number' => 'GROUP-ORDER-1', 'order_date' => now()->toDateString(), 'order_status' => 'shipped',
            'customer_id' => $member->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($operator)->get('/customers/groups?group=manual:'.$group->id)
            ->assertInertia(fn (Assert $page) => $page->where('customers.data.0.id', $member->id));

        $member->delete();
        $this->assertDatabaseMissing('customer_group_memberships', ['customer_id' => $member->id]);
        $this->assertDatabaseHas('customers', ['id' => $other->id]);
    }

    public function test_archiving_manual_group_member_preserves_membership_and_listing_semantics(): void
    {
        $operator = $this->userWith(['customers.view', 'customers.operate']);
        $group = CustomerGroup::query()->create(['name' => 'Archive-safe Group']);
        $member = Customer::query()->create(['name' => 'Archive-safe Member', 'source_type' => 'manual']);

        $this->actingAs($operator)->post('/customers/groups/'.$group->id.'/members', ['customer_ids' => [$member->id]])
            ->assertRedirect();
        $this->assertDatabaseHas('customer_group_memberships', [
            'customer_group_id' => $group->id,
            'customer_id' => $member->id,
        ]);

        $this->actingAs($operator)->get('/customers/library')
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.id', $member->id)
                ->where('customers.total', 1));

        $this->actingAs($operator)->post('/customers/library/'.$member->id.'/archive')->assertRedirect();

        $this->assertDatabaseHas('customer_group_memberships', [
            'customer_group_id' => $group->id,
            'customer_id' => $member->id,
        ]);
        $this->actingAs($operator)->get('/customers/library')
            ->assertInertia(fn (Assert $page) => $page->where('customers.total', 0));
        $this->actingAs($operator)->get('/customers/library?archived=only')
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.id', $member->id)
                ->where('customers.total', 1));
    }

    public function test_system_segment_filter_remains_computed_and_manual_search_excludes_archived_customers(): void
    {
        $operator = $this->userWith(['customers.view', 'customers.operate']);
        $group = CustomerGroup::query()->create(['name' => 'Search Group']);
        $active = Customer::query()->create(['name' => 'Search Active', 'email' => 'active@example.test', 'source_type' => 'manual']);
        $archived = Customer::query()->create(['name' => 'Search Archived', 'email' => 'archived@example.test', 'source_type' => 'manual', 'archived_at' => now()]);

        $this->actingAs($operator)->get('/customers/groups/'.$group->id.'/customers/search?search=Search')
            ->assertOk()->assertJsonPath('customers.0.id', $active->id);
        $this->assertJsonMissingCustomer($this->actingAs($operator)->get('/customers/groups/'.$group->id.'/customers/search?search=Search')->json('customers'), $archived->id);

        $this->actingAs($operator)->get('/customers/groups?group=no_purchase')
            ->assertInertia(fn (Assert $page) => $page->where('filters.group', 'no_purchase'));
    }

    private function assertJsonMissingCustomer(array $customers, int $id): void
    {
        self::assertFalse(collect($customers)->contains('id', $id));
    }

    private function userWith(array $permissions, string $roleKey = 'customer_operator'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
