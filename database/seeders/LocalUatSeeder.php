<?php

namespace Database\Seeders;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Finance\Infrastructure\Models\OrderCostEntry;
use App\Modules\Finance\Infrastructure\Models\OrderFinancial;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\ManualSyncStatus;
use App\Modules\Sync\Infrastructure\Models\ManualSync;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

final class LocalUatSeeder extends Seeder
{
    private const PASSWORDS = [
        'admin@local.test' => 'UAT_ADMIN_PASSWORD',
        'owner@example.test' => 'UAT_OWNER_PASSWORD',
        'logistics@example.test' => 'UAT_LOGISTICS_PASSWORD',
        'restricted@example.test' => 'UAT_RESTRICTED_PASSWORD',
        'zhang@example.test' => 'UAT_PROCUREMENT_PASSWORD',
    ];

    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 500, 'Local UAT seeding is restricted to local/testing environments.');
        abort_unless(Schema::hasTable('orders') && Schema::hasTable('roles'), 500, 'Local UAT seeding requires the migrated UAT schema.');

        $permissions = $this->permissions();
        $roles = $this->roles($permissions);
        $users = $this->users($roles);
        $this->call(PackagingProfileUatSeeder::class);

        $websitePending = $this->order($users['owner'], OrderSourceType::Website, OrderKind::Customer, 'UAT-WEB-001', 'UAT Website Pending', 'pending@example.test', [
            ['product_summary' => 'Synthetic Website Bundle A', 'sku' => 'UAT-WEB-A', 'quantity' => 2, 'unit_price' => '24.50'],
            ['product_summary' => 'Synthetic Website Bundle B', 'sku' => 'UAT-WEB-B', 'quantity' => 1, 'unit_price' => '12.00'],
        ]);
        $kolIncomplete = $this->order($users['owner'], OrderSourceType::Kol, OrderKind::KolFulfillment, 'UAT-KOL-001', 'UAT KOL Incomplete', 'kol@example.test', [
            ['product_summary' => 'Synthetic KOL Product', 'sku' => 'UAT-KOL-A', 'quantity' => 1, 'unit_price' => '75.00'],
        ]);
        $manualPending = $this->order($users['owner'], OrderSourceType::ManualOther, OrderKind::Customer, 'UAT-MAN-001', 'UAT Manual Pending Sync', 'manual@example.test', [
            ['product_summary' => 'Synthetic Manual Product', 'sku' => 'UAT-MAN-A', 'quantity' => 3, 'unit_price' => '10.00'],
        ]);
        $completed = $this->order($users['owner'], OrderSourceType::Website, OrderKind::Customer, 'UAT-WEB-002', 'UAT Completed Sync', 'completed@example.test', [
            ['product_summary' => 'Synthetic Completed Product', 'sku' => 'UAT-WEB-C', 'quantity' => 1, 'unit_price' => '100.00'],
        ]);
        $returned = $this->order($users['owner'], OrderSourceType::ManualOther, OrderKind::KolFulfillment, 'UAT-MAN-002', 'UAT Returned Sync', 'returned@example.test', [
            ['product_summary' => 'Synthetic Returned Product', 'sku' => 'UAT-MAN-R', 'quantity' => 1, 'unit_price' => '35.00'],
        ]);
        $confirmed = $this->order($users['owner'], OrderSourceType::Website, OrderKind::Customer, 'UAT-WEB-003', 'UAT Confirmed Profit', 'profit@example.test', [
            ['product_summary' => 'Synthetic Profit Product', 'sku' => 'UAT-WEB-P', 'quantity' => 1, 'unit_price' => '120.00'],
        ]);
        $missingCost = $this->order($users['owner'], OrderSourceType::Kol, OrderKind::KolFulfillment, 'UAT-KOL-002', 'UAT Missing Cost', 'missing@example.test', [
            ['product_summary' => 'Synthetic Missing Cost Product', 'sku' => 'UAT-KOL-M', 'quantity' => 1, 'unit_price' => '55.00'],
        ]);

        $this->ship($manualPending, $users['logistics'], 'UAT Carrier', 'UAT-TRACK-PENDING');
        $this->ship($completed, $users['logistics'], 'UAT Carrier', 'UAT-TRACK-COMPLETE');
        $this->ship($returned, $users['logistics'], 'UAT Carrier', 'UAT-TRACK-RETURNED');
        $this->ship($confirmed, $users['logistics'], 'UAT Carrier', 'UAT-TRACK-PROFIT');
        $this->sync($manualPending, ManualSyncStatus::Pending, null, null, 'Ready for manual website update');
        $this->sync($completed, ManualSyncStatus::Completed, $users['owner'], now()->subHours(2), 'Synthetic completed manual update');
        $this->sync($returned, ManualSyncStatus::Returned, null, null, 'Synthetic correction required');
        $this->costs($confirmed, '120.00', true);
        $this->costs($missingCost, null, false);

        app(AuditRecorder::class)->record(
            AuditActor::system(AuditSource::InternalSystem),
            'uat.dataset.seeded',
            self::class,
            null,
            after: ['dataset' => 'MVP1_LOCAL_UAT', 'synthetic' => true],
            reason: 'local_uat_seed',
        );
    }

    private function permissions(): array
    {
        $keys = [
            'dashboard.view', 'orders.view', 'orders.create', 'orders.edit', 'logistics.view', 'logistics.operate',
            'sync.view', 'sync.operate', 'analysis.view', 'customers.view', 'customers.operate', 'customers.import', 'customers.export',
            'kol.view', 'kol.operate', 'kol.views.manage', 'inventory.view', 'inventory.operate',
            'procurement.view', 'procurement.create', 'procurement.operate', 'products.view', 'products.operate', 'guides.view', 'guides.manage', 'accounting.view', 'system.permissions.manage', 'inventory.packaging.submit', 'inventory.packaging.review',
        ];

        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => Permission::create(['key' => $key, 'label' => $key])])->all();
    }

    private function roles(array $permissions): array
    {
        $owner = Role::create(['key' => 'uat_owner_admin', 'label' => 'UAT Owner/Admin']);
        $owner->permissions()->attach(array_map(fn (Permission $permission): int => $permission->id, $permissions));
        $logistics = Role::create(['key' => 'uat_logistics_operator', 'label' => 'UAT Logistics Operator']);
        $logistics->permissions()->attach(array_map(fn (string $key): int => $permissions[$key]->id, ['dashboard.view', 'orders.view', 'logistics.view', 'logistics.operate', 'sync.view', 'sync.operate']));
        $restricted = Role::create(['key' => 'uat_restricted_operator', 'label' => 'UAT Restricted Operator']);
        $restricted->permissions()->attach([$permissions['dashboard.view']->id, $permissions['orders.view']->id]);
        $factory = Role::create(['key' => 'uat_factory_packaging_operator', 'label' => 'UAT Factory Packaging Operator']);
        $factory->permissions()->attach([$permissions['inventory.packaging.submit']->id]);
        $procurement = Role::create(['key' => 'uat_procurement_operator', 'label' => 'UAT Procurement Operator']);
        $procurement->permissions()->attach([$permissions['procurement.view']->id, $permissions['procurement.operate']->id]);

        return compact('owner', 'logistics', 'restricted', 'factory', 'procurement');
    }

    private function users(array $roles): array
    {
        $users = [
            'admin' => User::create(['name' => 'UAT Admin', 'email' => 'admin@local.test', 'password' => Hash::make((string) env(self::PASSWORDS['admin@local.test'], 'password')), 'is_active' => true]),
            'owner' => User::create(['name' => 'UAT Owner/Admin', 'email' => 'owner@example.test', 'password' => Hash::make((string) env(self::PASSWORDS['owner@example.test'], '')), 'is_active' => true]),
            'logistics' => User::create(['name' => 'UAT Logistics Operator', 'email' => 'logistics@example.test', 'password' => Hash::make((string) env(self::PASSWORDS['logistics@example.test'], '')), 'is_active' => true]),
            'restricted' => User::create(['name' => 'UAT Restricted Operator', 'email' => 'restricted@example.test', 'password' => Hash::make((string) env(self::PASSWORDS['restricted@example.test'], '')), 'is_active' => true]),
            'factory' => User::create(['name' => 'UAT Factory Operator', 'email' => 'factory@example.test', 'password' => Hash::make('password'), 'is_active' => true]),
            'zhang' => User::create(['name' => 'Zhang', 'email' => 'zhang@example.test', 'password' => Hash::make((string) env(self::PASSWORDS['zhang@example.test'], 'password')), 'is_active' => true]),
        ];
        $users['admin']->roles()->attach($roles['owner']);
        $users['owner']->roles()->attach($roles['owner']);
        $users['logistics']->roles()->attach($roles['logistics']);
        $users['restricted']->roles()->attach($roles['restricted']);
        $users['factory']->roles()->attach($roles['factory']);
        $users['zhang']->roles()->attach($roles['procurement']);

        return $users;
    }

    private function order(User $owner, OrderSourceType $source, OrderKind $kind, string $externalNumber, string $recipient, string $email, array $items): Order
    {
        $order = Order::create([
            'internal_order_number' => $externalNumber,
            'source_type' => $source,
            'order_kind' => $kind,
            'external_order_number' => $externalNumber,
            'order_date' => now()->toDateString(),
            'order_note' => 'Synthetic local UAT data only',
            'owner_id' => $owner->id,
            'order_status' => OrderStatus::PendingShipment,
        ]);
        $order->address()->create([
            'recipient_name' => $recipient, 'phone' => '+1 555 0100', 'country' => 'US', 'province_state' => 'CA',
            'city' => 'Los Angeles', 'address_line_1' => '100 Synthetic Street', 'address_line_2' => $email, 'postal_code' => '90001',
        ]);
        foreach ($items as $item) {
            $order->items()->create([...$item, 'line_revenue' => number_format((float) $item['unit_price'] * $item['quantity'], 2, '.', '')]);
        }

        return $order;
    }

    private function ship(Order $order, User $operator, string $carrier, string $tracking): void
    {
        $order->forceFill(['order_status' => OrderStatus::Shipped])->save();
        Shipment::create(['order_id' => $order->id, 'shipment_status' => 'shipped', 'carrier' => $carrier, 'tracking_number' => $tracking, 'shipped_at' => now()->subHour(), 'handled_by' => $operator->id]);
    }

    private function sync(Order $order, ManualSyncStatus $status, ?User $operator, $syncedAt, string $note): void
    {
        ManualSync::create(['order_id' => $order->id, 'website_sync_status' => $status, 'synced_at' => $syncedAt, 'synced_by' => $operator?->id, 'sync_note' => $note]);
    }

    private function costs(Order $order, ?string $amountPaid, bool $complete): void
    {
        if ($amountPaid === null) {
            return;
        }
        OrderFinancial::create(['order_id' => $order->id, 'amount_paid' => $amountPaid, 'refund_amount' => '0.00', 'currency' => 'USD']);
        foreach (['product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost', 'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost'] as $field) {
            if ($complete) {
                OrderCostEntry::create(['order_id' => $order->id, 'cost_type' => $field, 'amount' => '2.00', 'currency' => 'USD']);
            }
        }
    }
}
