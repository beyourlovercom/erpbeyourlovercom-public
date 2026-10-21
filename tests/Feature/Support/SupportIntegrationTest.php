<?php

namespace Tests\Feature\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Support\Infrastructure\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SupportIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_schema_has_required_columns_indexes_and_foreign_keys(): void
    {
        self::assertTrue(Schema::hasTable('support_tickets'));
        self::assertTrue(Schema::hasColumns('support_tickets', [
            'id', 'customer_id', 'subject', 'description', 'status', 'priority', 'source_type',
            'source_provider', 'external_ticket_id', 'assigned_user_id', 'opened_at',
            'status_updated_at', 'resolved_at', 'closed_at', 'last_activity_at',
        ]));

        $indexes = collect(Schema::getIndexes('support_tickets'))->mapWithKeys(
            fn (array $index): array => [$index['name'] => $index['columns']]
        );
        self::assertContains(['customer_id', 'status'], $indexes->values()->all());
        self::assertContains(['assigned_user_id', 'status'], $indexes->values()->all());
        self::assertContains(['status', 'priority'], $indexes->values()->all());
        self::assertContains(['source_provider', 'external_ticket_id'], $indexes->values()->all());

        if (method_exists(Schema::getFacadeRoot(), 'getForeignKeys')) {
            $foreignKeys = collect(Schema::getForeignKeys('support_tickets'))->pluck('columns')->flatten()->all();
            self::assertContains('customer_id', $foreignKeys);
            self::assertContains('assigned_user_id', $foreignKeys);
        }
    }

    public function test_manual_create_is_explicitly_customer_scoped_and_audited(): void
    {
        $operator = $this->userWith(['customers.view', 'customers.operate']);
        $customer = Customer::query()->create(['name' => 'Support Customer', 'email' => 'person@example.com', 'source_type' => 'manual']);

        $this->actingAs($operator)->post("/customers/library/{$customer->id}/support-tickets", ['subject' => 'Need help', 'priority' => 'urgent', 'requester_email' => 'wrong@example.com'])->assertRedirect();
        $ticket = SupportTicket::query()->firstOrFail();
        self::assertSame($customer->id, $ticket->customer_id);
        self::assertSame('manual', $ticket->source_type);
        self::assertNull($ticket->source_provider);
        self::assertSame('open', $ticket->status);
        self::assertSame('TKT-'.str_pad((string) $ticket->id, 8, '0', STR_PAD_LEFT), $ticket->ticket_number);
        self::assertSame(1, AuditRecord::query()->where('action', 'support_ticket.created')->count());
    }

    public function test_pending_projection_is_batched_and_excludes_resolved_and_closed(): void
    {
        $user = $this->userWith(['customers.view']);
        $first = Customer::query()->create(['name' => 'First', 'source_type' => 'manual']);
        $second = Customer::query()->create(['name' => 'Second', 'source_type' => 'manual']);
        foreach ([[$first->id, 'open'], [$first->id, 'resolved'], [$second->id, 'waiting_internal'], [$second->id, 'closed'], [null, 'open']] as [$customerId, $status]) {
            SupportTicket::query()->create(['customer_id' => $customerId, 'subject' => 'Ticket', 'status' => $status, 'priority' => 'normal', 'source_type' => 'manual']);
        }

        $this->actingAs($user)->get('/customers/library')->assertInertia(fn ($page) => $page->where('stats.pending_support_tickets', 2));
        $this->actingAs($user)->get('/customers')->assertInertia(fn ($page) => $page->where('metrics.pending_support_tickets', 2));
        self::assertSame(1, DB::table('support_tickets')->where('customer_id', $first->id)->where('status', 'open')->count());
    }

    public function test_ticket_isolation_and_write_permission_are_enforced(): void
    {
        $reader = $this->userWith(['customers.view'], 'support_reader');
        $first = Customer::query()->create(['name' => 'First', 'source_type' => 'manual']);
        $second = Customer::query()->create(['name' => 'Second', 'source_type' => 'manual']);
        $ticket = SupportTicket::query()->create(['customer_id' => $first->id, 'subject' => 'Private', 'status' => 'open', 'priority' => 'normal', 'source_type' => 'manual']);

        $this->actingAs($reader)->get("/customers/library/{$second->id}/support-tickets/{$ticket->id}")->assertNotFound();
        $this->actingAs($reader)->post("/customers/library/{$first->id}/support-tickets", ['subject' => 'Denied'])->assertForbidden();
    }

    public function test_customer360_projects_only_linked_tickets_with_customer_pending_metric(): void
    {
        $reader = $this->userWith(['customers.view'], 'customer360_reader');
        $customer = Customer::query()->create(['name' => '360 Customer', 'source_type' => 'manual']);
        SupportTicket::query()->create(['customer_id' => $customer->id, 'subject' => 'Linked', 'status' => 'waiting_customer', 'priority' => 'high', 'source_type' => 'manual']);
        SupportTicket::query()->create(['customer_id' => null, 'subject' => 'Unlinked', 'status' => 'open', 'priority' => 'urgent', 'source_type' => 'manual']);

        $this->actingAs($reader)->get("/customers/library/{$customer->id}")->assertInertia(fn ($page) => $page
            ->where('pendingSupportTickets', 1)
            ->has('supportTickets', 1)
            ->where('supportTickets.0.subject', 'Linked')
            ->where('supportTickets.0.status', 'waiting_customer'));
    }

    public function test_status_change_preserves_resolution_until_reopen_and_direct_close_has_no_resolution(): void
    {
        $operator = $this->userWith(['customers.view', 'customers.operate'], 'support_operator');
        $customer = Customer::query()->create(['name' => 'Status Customer', 'source_type' => 'manual']);
        $ticket = SupportTicket::query()->create(['customer_id' => $customer->id, 'subject' => 'Status', 'status' => 'open', 'priority' => 'normal', 'source_type' => 'manual']);

        $this->actingAs($operator)->patch("/customers/library/{$customer->id}/support-tickets/{$ticket->id}", ['subject' => 'Status', 'priority' => 'normal', 'status' => 'resolved'])->assertRedirect();
        $ticket->refresh();
        self::assertNotNull($ticket->resolved_at);
        self::assertNotNull($ticket->status_updated_at);
        $resolvedAt = $ticket->resolved_at;
        $this->actingAs($operator)->patch("/customers/library/{$customer->id}/support-tickets/{$ticket->id}", ['subject' => 'Status', 'priority' => 'normal', 'status' => 'closed'])->assertRedirect();
        $ticket->refresh();
        self::assertSame($resolvedAt->toDateTimeString(), $ticket->resolved_at?->toDateTimeString());
        self::assertNotNull($ticket->closed_at);
        $this->actingAs($operator)->patch("/customers/library/{$customer->id}/support-tickets/{$ticket->id}", ['subject' => 'Status', 'priority' => 'normal', 'status' => 'open'])->assertRedirect();
        self::assertNull($ticket->fresh()->resolved_at);
        self::assertNull($ticket->fresh()->closed_at);
        self::assertGreaterThanOrEqual(1, AuditRecord::query()->where('action', 'support_ticket.status_changed')->count());

        $direct = SupportTicket::query()->create(['customer_id' => $customer->id, 'subject' => 'Direct close', 'status' => 'open', 'priority' => 'normal', 'source_type' => 'manual']);
        $this->actingAs($operator)->patch("/customers/library/{$customer->id}/support-tickets/{$direct->id}", ['subject' => 'Direct close', 'priority' => 'normal', 'status' => 'closed'])->assertRedirect();
        self::assertNotNull($direct->fresh()->closed_at);
        self::assertNull($direct->fresh()->resolved_at);
    }

    public function test_assignment_accepts_active_erp_users_and_records_initial_and_update_audits(): void
    {
        $operator = $this->userWith(['customers.view', 'customers.operate'], 'assignment_operator');
        $active = User::factory()->create(['is_active' => true]);
        $inactive = User::factory()->create(['is_active' => false]);
        $customer = Customer::query()->create(['name' => 'Assignment Customer', 'source_type' => 'manual']);

        $this->actingAs($operator)->post("/customers/library/{$customer->id}/support-tickets", ['subject' => 'Assigned', 'assigned_user_id' => $active->id])->assertRedirect();
        $ticket = SupportTicket::query()->firstOrFail();
        self::assertSame($active->id, $ticket->assigned_user_id);
        self::assertSame(1, AuditRecord::query()->where('action', 'support_ticket.assigned')->count());

        $this->actingAs($operator)->patch("/customers/library/{$customer->id}/support-tickets/{$ticket->id}", ['subject' => 'Assigned', 'priority' => 'normal', 'status' => 'open', 'assigned_user_id' => $inactive->id])
            ->assertSessionHasErrors('assigned_user_id');
        self::assertSame($active->id, $ticket->fresh()->assigned_user_id);

        $replacement = User::factory()->create(['is_active' => true]);
        $this->actingAs($operator)->patch("/customers/library/{$customer->id}/support-tickets/{$ticket->id}", ['subject' => 'Assigned', 'priority' => 'normal', 'status' => 'open', 'assigned_user_id' => $replacement->id])->assertRedirect();
        self::assertSame(2, AuditRecord::query()->where('action', 'support_ticket.assigned')->count());
    }

    private function userWith(array $permissions, string $roleKey = 'support_operator'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
