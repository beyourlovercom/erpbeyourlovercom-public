<?php

namespace Tests\Feature\Customers;

use App\Modules\Customers\Application\CustomerImportService;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class CustomerImportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_preview_is_persistent_and_does_not_mutate_customer_truth_then_confirm_is_exactly_once(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        $csv = "\xEF\xBB\xBFname,email,phone,source_type\nNew Customer,new@example.test,+1 555 0100,website\n";
        $preview = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('customers.csv', $csv)]);
        $preview->assertOk()->assertJsonPath('stats.create', 1);
        $this->assertDatabaseCount('customers', 0);
        $batchId = $preview->json('batch.id');
        $this->assertDatabaseHas('customer_import_batches', ['id' => $batchId, 'status' => 'preview']);
        $this->actingAs($user)->post('/customers/import/'.$batchId.'/confirm')->assertOk();
        $this->assertDatabaseHas('customers', ['email' => 'new@example.test', 'source_type' => 'website']);
        $this->actingAs($user)->post('/customers/import/'.$batchId.'/confirm')->assertStatus(422);
    }

    public function test_xlsx_round_trip_uses_first_sheet_and_export_schema_is_canonical(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'customers.export', 'system.permissions.manage']);
        $service = app(CustomerImportService::class);
        $bytes = $service->xlsx([['name' => 'Xlsx Customer', 'email' => 'xlsx@example.test', 'source_type' => 'csv_import', 'status' => 'active']]);
        $preview = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('customers.xlsx', $bytes)]);
        $preview->assertOk()->assertJsonPath('stats.create', 1)->assertJsonPath('stats.error', 0);
        $this->actingAs($user)->post('/customers/import/'.$preview->json('batch.id').'/confirm')->assertOk();
        $csvExport = $this->actingAs($user)->get('/customers/library/export?format=csv');
        $csvExport->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringNotContainsString('orders_count', $csvExport->streamedContent());
        $xlsxExport = $this->actingAs($user)->get('/customers/library/export?format=xlsx');
        $xlsxExport->assertOk();
        $roundTrip = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('round-trip.xlsx', $xlsxExport->getContent())]);
        $roundTrip->assertOk()->assertJsonPath('stats.create', 0)->assertJsonPath('stats.unchanged', 1)->assertJsonPath('stats.error', 0);
    }

    public function test_unknown_column_warns_without_double_counting_total(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        $csv = "name,email,mystery\nWarn Customer,warn@example.test,ignored\n";
        $response = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('warn.csv', $csv)]);
        $response->assertOk()->assertJsonPath('stats.total', 1)->assertJsonPath('stats.create', 1)->assertJsonPath('stats.warning', 1)->assertJsonPath('stats.error', 0);
    }

    public function test_present_blank_name_on_existing_customer_is_blocker(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        $customer = Customer::create(['name' => 'Keep Name', 'email' => 'keep@example.test', 'source_type' => 'manual']);
        $csv = "customer_number,name\n{$customer->customer_number},\n";
        $response = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('blank-name.csv', $csv)]);
        $response->assertOk()->assertJsonPath('stats.error', 1);
        $this->assertSame('Keep Name', $customer->fresh()->name);
    }

    public function test_status_alias_address_and_manual_group_changes_are_updates_and_confirm(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        $customer = Customer::create(['name' => 'Existing', 'email' => 'existing@example.test', 'source_type' => 'manual']);
        $group = CustomerGroup::create(['name' => 'VIP Import']);
        $csv = "customer_number,status,billing_city,manual_groups\n{$customer->customer_number},归档,Shenzhen,VIP Import\n";
        $preview = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('update.csv', $csv)]);
        $preview->assertOk()->assertJsonPath('stats.update', 1)->assertJsonPath('stats.error', 0);
        $this->actingAs($user)->post('/customers/import/'.$preview->json('batch.id').'/confirm')->assertOk();
        $fresh = $customer->fresh();
        $this->assertNotNull($fresh->archived_at);
        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'billing', 'city' => 'Shenzhen']);
        $this->assertDatabaseHas('customer_group_memberships', ['customer_id' => $customer->id, 'customer_group_id' => $group->id]);
    }

    public function test_invalid_or_missing_customer_number_is_blocker_not_fallback_create(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        $csv = "customer_number,name,email\nBAD-1,Bad One,bad1@example.test\nCUS-99999999,Bad Two,bad2@example.test\n";
        $response = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('bad-number.csv', $csv)]);
        $response->assertOk()->assertJsonPath('stats.error', 2);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_duplicate_phone_in_new_rows_blocks_even_when_emails_differ(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        $csv = "name,email,phone\nOne,one@example.test,+15550100\nTwo,two@example.test,+15550100\n";
        $response = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('duplicate-phone.csv', $csv)]);
        $response->assertOk()->assertJsonPath('stats.error', 1);
    }

    public function test_partial_update_preserves_absent_fields_and_blank_clears_nullable(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        $customer = Customer::create(['name' => 'Partial', 'email' => 'partial@example.test', 'phone' => '+15550000', 'language_code' => 'en', 'source_type' => 'manual']);
        $csv = "customer_number,phone\n{$customer->customer_number},\n";
        $preview = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('partial.csv', $csv)]);
        $preview->assertOk()->assertJsonPath('stats.update', 1)->assertJsonPath('stats.error', 0);
        $this->actingAs($user)->post('/customers/import/'.$preview->json('batch.id').'/confirm')->assertOk();
        $fresh = $customer->fresh();
        $this->assertNull($fresh->phone);
        $this->assertSame('en', $fresh->language_code);
    }

    public function test_malformed_xlsx_is_rejected_as_validation_error(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'not-an-xlsx')])->assertStatus(422);
    }

    public function test_owner_must_be_active_and_unknown_manual_group_blocks(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        User::factory()->create(['email' => 'inactive-owner@example.test', 'is_active' => false]);
        $csv = "name,email,owner_email,manual_groups\nBlocked,blocked@example.test,inactive-owner@example.test,Missing Group\n";
        $response = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('owner-group.csv', $csv)]);
        $response->assertOk()->assertJsonPath('stats.error', 1);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_blank_manual_groups_clears_memberships_and_blank_address_deletes_address(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        $customer = Customer::create(['name' => 'Clearable', 'email' => 'clearable@example.test', 'source_type' => 'manual']);
        $group = CustomerGroup::create(['name' => 'Old Group']);
        $customer->groups()->attach($group->id, ['added_by' => $user->id]);
        $customer->addresses()->create(['address_type' => 'shipping', 'city' => 'Old City']);
        $csv = "customer_number,manual_groups,shipping_city\n{$customer->customer_number},,\n";
        $preview = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('clear.csv', $csv)]);
        $preview->assertOk()->assertJsonPath('stats.update', 1)->assertJsonPath('stats.error', 0);
        $this->actingAs($user)->post('/customers/import/'.$preview->json('batch.id').'/confirm')->assertOk();
        $this->assertDatabaseMissing('customer_group_memberships', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('customer_addresses', ['customer_id' => $customer->id, 'address_type' => 'shipping']);
    }

    public function test_repreview_after_confirm_resolves_same_customer_instead_of_duplicate_create(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        $csv = "name,email\nStable,stable@example.test\n";
        $first = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('stable.csv', $csv)]);
        $this->actingAs($user)->post('/customers/import/'.$first->json('batch.id').'/confirm')->assertOk();
        $second = $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('stable.csv', $csv)]);
        $second->assertOk()->assertJsonPath('stats.create', 0)->assertJsonPath('stats.unchanged', 1)->assertJsonPath('stats.error', 0);
        $this->actingAs($user)->post('/customers/import/'.$second->json('batch.id').'/confirm')->assertOk();
        $this->assertSame(1, Customer::where('email', 'stable@example.test')->count());
    }

    public function test_more_than_five_thousand_non_empty_rows_is_rejected(): void
    {
        $user = $this->userWith(['customers.view', 'customers.import', 'system.permissions.manage']);
        $rows = ["name,email"];
        for ($i = 1; $i <= 5001; $i++) $rows[] = "Customer {$i},row{$i}@example.test";
        $this->actingAs($user)->post('/customers/library/import', ['file' => UploadedFile::fake()->createWithContent('too-many.csv', implode("\n", $rows)."\n")])->assertStatus(422);
        $this->assertDatabaseCount('customer_import_batches', 0);
    }

    public function test_filtered_csv_export_is_not_paginated_and_preserves_manual_group_filter(): void
    {
        $user = $this->userWith(['customers.view', 'customers.export', 'system.permissions.manage']);
        $group = CustomerGroup::create(['name' => 'Export Group']);
        for ($i = 1; $i <= 55; $i++) {
            $customer = Customer::create(['name' => 'Export '.$i, 'email' => 'export'.$i.'@example.test', 'country_code' => 'US', 'source_type' => 'manual']);
            $customer->groups()->attach($group->id, ['added_by' => $user->id]);
        }
        Customer::create(['name' => 'Outside', 'email' => 'outside@example.test', 'country_code' => 'CA', 'source_type' => 'manual']);
        $response = $this->actingAs($user)->get('/customers/library/export?format=csv&group=manual:'.$group->id.'&country_code=US');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFcustomer_number,", $content);
        $this->assertSame(56, count(array_filter(preg_split('/\r\n|\n|\r/', trim($content)))));
        $this->assertStringNotContainsString('outside@example.test', $content);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'customer_import_test', 'label' => 'Customer Import Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
