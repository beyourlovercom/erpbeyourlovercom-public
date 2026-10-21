<?php

namespace Tests\Feature\Customers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Customers\Infrastructure\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_view_edit_and_archive_a_company(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);

        $response = $this->actingAs($user)->post('/customers/companies', [
            'name' => 'Acme Trading Co',
            'legal_name' => 'Acme Trading Company Ltd',
            'website_url' => 'https://Shop.ACME.com./storefront',
            'country_code' => 'us',
            'registration_number' => '  REG-001  ',
            'industry' => 'Wholesale',
        ]);

        $company = Company::query()->firstOrFail();
        $response->assertRedirect('/customers/companies/'.$company->id);
        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Acme Trading Co',
            'website_domain_normalized' => 'shop.acme.com',
            'country_code' => 'US',
            'registration_number' => 'REG-001',
            'status' => 'prospect',
            'archived_at' => null,
        ]);
        $this->assertDatabaseHas('audit_records', ['action' => 'company.created', 'entity_id' => (string) $company->id]);

        $this->actingAs($user)->get('/customers/companies/'.$company->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Customers/Companies/Show')->where('company.website_domain_normalized', 'shop.acme.com'));

        $this->actingAs($user)->patch('/customers/companies/'.$company->id, ['name' => 'Acme Trading Co (Renamed)'])
            ->assertRedirect('/customers/companies/'.$company->id);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Acme Trading Co (Renamed)']);
        $this->assertDatabaseHas('audit_records', ['action' => 'company.updated', 'entity_id' => (string) $company->id]);

        $this->actingAs($user)->post('/customers/companies/'.$company->id.'/archive')->assertRedirect('/customers/companies');
        $company->refresh();
        $this->assertNotNull($company->archived_at);
        $this->assertDatabaseHas('audit_records', ['action' => 'company.archived', 'entity_id' => (string) $company->id]);
    }

    public function test_name_is_required(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);

        $this->actingAs($user)->post('/customers/companies', ['name' => ''])
            ->assertSessionHasErrors('name');
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_invalid_non_empty_website_url_fails_validation_instead_of_being_guessed(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);

        $this->actingAs($user)->post('/customers/companies', ['name' => 'Bad URL Co', 'website_url' => 'not-a-url'])
            ->assertSessionHasErrors('website_url');
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_invalid_country_code_fails_validation(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);

        $this->actingAs($user)->post('/customers/companies', ['name' => 'Bad Country Co', 'country_code' => 'USA'])
            ->assertSessionHasErrors('country_code');
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_owner_deletion_does_not_delete_the_company(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $owner = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->post('/customers/companies', ['name' => 'Owned Co', 'owner_id' => $owner->id])->assertRedirect();
        $company = Company::query()->firstOrFail();
        $this->assertSame($owner->id, $company->owner_id);

        $owner->delete();

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
        $this->assertNull($company->fresh()->owner_id);
    }

    public function test_default_list_excludes_archived_and_explicit_filter_includes_them(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $this->actingAs($user)->post('/customers/companies', ['name' => 'Active Co'])->assertRedirect();
        $this->actingAs($user)->post('/customers/companies', ['name' => 'Archived Co'])->assertRedirect();
        $archived = Company::query()->where('name', 'Archived Co')->firstOrFail();
        $this->actingAs($user)->post('/customers/companies/'.$archived->id.'/archive')->assertRedirect();

        $this->actingAs($user)->get('/customers/companies')->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Companies/Index')
            ->where('companies', fn ($companies): bool => collect($companies)->pluck('name')->all() === ['Active Co']));

        $this->actingAs($user)->get('/customers/companies?archived=only')->assertInertia(fn (Assert $page) => $page
            ->where('companies', fn ($companies): bool => collect($companies)->pluck('name')->all() === ['Archived Co']));
    }

    public function test_authorized_user_can_restore_an_archived_company(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $this->actingAs($user)->post('/customers/companies', ['name' => 'Restorable Co'])->assertRedirect();
        $company = Company::query()->firstOrFail();
        $this->actingAs($user)->post('/customers/companies/'.$company->id.'/archive')->assertRedirect();
        $this->assertNotNull($company->fresh()->archived_at);

        $response = $this->actingAs($user)->post('/customers/companies/'.$company->id.'/restore');
        $response->assertRedirect('/customers/companies/'.$company->id);
        $company->refresh();
        $this->assertNull($company->archived_at);
        $this->assertDatabaseHas('audit_records', ['action' => 'company.restored', 'entity_id' => (string) $company->id]);

        $this->actingAs($user)->get('/customers/companies')->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Companies/Index')
            ->where('companies', fn ($companies): bool => collect($companies)->pluck('name')->all() === ['Restorable Co']));

        $this->actingAs($user)->get('/customers/companies?archived=only')->assertInertia(fn (Assert $page) => $page
            ->where('companies', fn ($companies): bool => collect($companies)->pluck('name')->all() === []));
    }

    public function test_restoring_an_already_active_company_is_idempotent(): void
    {
        $user = $this->userWith(['customers.view', 'customers.operate']);
        $this->actingAs($user)->post('/customers/companies', ['name' => 'Already Active Co'])->assertRedirect();
        $company = Company::query()->firstOrFail();

        $this->actingAs($user)->post('/customers/companies/'.$company->id.'/restore')->assertRedirect('/customers/companies/'.$company->id);

        $this->assertNull($company->fresh()->archived_at);
        $this->assertDatabaseCount('audit_records', 1);
        $this->assertDatabaseMissing('audit_records', ['action' => 'company.restored']);
    }

    public function test_read_only_user_can_list_and_view_but_cannot_mutate(): void
    {
        $operator = $this->userWith(['customers.view', 'customers.operate']);
        $this->actingAs($operator)->post('/customers/companies', ['name' => 'Read Only Target'])->assertRedirect();
        $company = Company::query()->firstOrFail();

        $reader = $this->userWith(['customers.view'], 'customers_reader');
        $this->actingAs($reader)->get('/customers/companies')->assertOk();
        $this->actingAs($reader)->get('/customers/companies/'.$company->id)->assertOk();
        $this->actingAs($reader)->post('/customers/companies', ['name' => 'Should Fail'])->assertForbidden();
        $this->actingAs($reader)->patch('/customers/companies/'.$company->id, ['name' => 'Should Fail'])->assertForbidden();
        $this->actingAs($reader)->post('/customers/companies/'.$company->id.'/archive')->assertForbidden();
        $this->actingAs($reader)->post('/customers/companies/'.$company->id.'/restore')->assertForbidden();
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_unauthenticated_and_unauthorized_access_is_rejected_server_side(): void
    {
        $this->get('/customers/companies')->assertRedirect('/login');

        $stranger = $this->userWith(['prototype.view'], 'stranger');
        $this->actingAs($stranger)->get('/customers/companies')->assertForbidden();
        $this->actingAs($stranger)->post('/customers/companies', ['name' => 'Nope'])->assertForbidden();
    }

    private function userWith(array $permissions, string $roleKey = 'customers_operator'): User
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
