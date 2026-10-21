<?php

namespace Database\Seeders;

use App\Modules\Finance\Infrastructure\Models\OrderCostEntry;
use App\Modules\Finance\Infrastructure\Models\OrderFinancial;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * P8.2 Owner-UAT fixture seeder.
 *
 * Scenarios seeded:
 *   P82-FRESH  — 3 days old, estimated costs → fresh_estimate_allowed
 *   P82-EST    — 20 days old, estimated costs → estimate_allowed, yellow profit card
 *   P82-DUE    — 35 days old, estimated costs → actual_reconciliation_due, amber banner
 *   P82-OVER   — 50 days old, estimated costs → actual_cost_overdue, red banner
 *   P82-FINAL  — 5 days old, ALL confirmed costs → canonical_final_ready = true
 *   P82-CHINA  — 8 days old, China-direct warehouse → no USD 10 fallback
 *   P82-RESTATE — actual replaced an estimate → audit trail
 *
 * Credentials: BYL_P82_UAT_EMAIL (synthetic @example.invalid) and
 * BYL_P82_UAT_PASSWORD (local-only, >= 16 characters). Never commit real values.
 */
final class P82UatSeeder extends Seeder
{
    private const UAT_EMAIL_ENV = 'BYL_P82_UAT_EMAIL';
    private const UAT_PASSWORD_ENV = 'BYL_P82_UAT_PASSWORD';

    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 500, 'P8.2 UAT seeding is restricted to local/testing environments.');
        [$uatEmail, $uatPassword] = self::credentials();
        abort_unless(Schema::hasTable('orders'), 500, 'Migrations must run first.');

        $user = $this->user($uatEmail, $uatPassword);

        // Warehouses (idempotent)
        $overseas = Warehouse::firstOrCreate(['slug' => 'us-la'], [
            'code' => 'USLA', 'name' => 'US-LA Overseas (UAT)',
            'country_code' => 'US', 'type' => 'overseas', 'ownership_type' => 'third_party',
            'is_domestic' => false, 'is_active' => true, 'priority' => 10,
        ]);
        $chinaFactory = Warehouse::firstOrCreate(['slug' => 'factory'], [
            'code' => 'FCTY', 'name' => 'Factory China-Direct (UAT)',
            'country_code' => 'CN', 'type' => 'factory', 'ownership_type' => 'owned',
            'is_domestic' => true, 'is_active' => true, 'priority' => 1,
        ]);

        // P82-FRESH: 3-day-old order, mix of estimated + confirmed costs
        $fresh = $this->order('P82-FRESH', 'P8.2 UAT Fresh (3d)', 3, 'US', 2, $user);
        $this->shipWith($fresh, $overseas, 'DHL', 'P82-TRACK-FRESH');
        $this->financial($fresh, '89.00');
        $this->costEntry($fresh, 'product_cost',           '30.00', 'actual');
        $this->costEntry($fresh, 'first_mile_cost',        '5.00',  'estimated_reference');
        $this->costEntry($fresh, 'overseas_handling_cost', '3.00',  'estimated_reference');
        $this->costEntry($fresh, 'last_mile_cost',         '12.00', 'estimated_reference');
        $this->costEntry($fresh, 'payment_fee',            '2.67',  'actual');
        // customs_tax missing → required missing

        // P82-EST: 20-day-old order, estimated costs visible
        $est = $this->order('P82-EST', 'P8.2 UAT Estimate-Allowed (20d)', 20, 'DE', 3, $user);
        $this->shipWith($est, $overseas, 'PostNL', 'P82-TRACK-EST');
        $this->financial($est, '120.00');
        $this->costEntry($est, 'product_cost',           '35.00', 'actual');
        $this->costEntry($est, 'first_mile_cost',        '8.00',  'estimated_reference');
        $this->costEntry($est, 'overseas_handling_cost', '4.50',  'estimated_reference');
        $this->costEntry($est, 'last_mile_cost',         '18.00', 'estimated_reference');
        $this->costEntry($est, 'payment_fee',            '3.60',  'actual');
        $this->costEntry($est, 'customs_tax',            '6.00',  'estimated_reference');

        // P82-DUE: 35-day-old order, reconciliation due → amber banner
        $due = $this->order('P82-DUE', 'P8.2 UAT Reconciliation Due (35d)', 35, 'US', 1, $user);
        $this->shipWith($due, $overseas, 'UPS', 'P82-TRACK-DUE');
        $this->financial($due, '55.00');
        $this->costEntry($due, 'product_cost',           '18.00', 'actual');
        $this->costEntry($due, 'first_mile_cost',        '4.00',  'estimated_reference');
        $this->costEntry($due, 'overseas_handling_cost', '2.00',  'estimated_reference');
        $this->costEntry($due, 'last_mile_cost',         '9.50',  'estimated_reference');
        $this->costEntry($due, 'payment_fee',            '1.65',  'actual');
        $this->costEntry($due, 'customs_tax',            '0.00',  'estimated_reference');

        // P82-OVER: 50-day-old order, actual cost overdue → red banner
        $over = $this->order('P82-OVER', 'P8.2 UAT Cost Overdue (50d)', 50, 'CA', 2, $user);
        $this->shipWith($over, $overseas, 'FedEx', 'P82-TRACK-OVER');
        $this->financial($over, '99.00');
        $this->costEntry($over, 'product_cost',           '28.00', 'actual');
        $this->costEntry($over, 'first_mile_cost',        '6.00',  'estimated_reference');
        $this->costEntry($over, 'overseas_handling_cost', '3.50',  'estimated_reference');
        $this->costEntry($over, 'last_mile_cost',         '14.00', 'estimated_reference');
        $this->costEntry($over, 'payment_fee',            '2.97',  'actual');
        $this->costEntry($over, 'customs_tax',            '4.00',  'estimated_reference');

        // P82-FINAL: 5-day-old order, ALL confirmed costs → canonical_final_ready = true
        $final = $this->order('P82-FINAL', 'P8.2 UAT All-Confirmed Final (5d)', 5, 'US', 2, $user);
        $this->shipWith($final, $overseas, 'DHL', 'P82-TRACK-FINAL');
        $this->financial($final, '110.00');
        foreach (['product_cost','first_mile_cost','overseas_handling_cost','last_mile_cost','payment_fee','customs_tax'] as $ct) {
            $this->costEntry($final, $ct, '8.00', 'actual');
        }

        // P82-CHINA: 8-day-old, China-direct warehouse → no USD 10 fallback
        $china = $this->order('P82-CHINA', 'P8.2 UAT China-Direct No Fallback (8d)', 8, 'US', 3, $user);
        $this->shipWith($china, $chinaFactory, 'SF Express', 'P82-TRACK-CHINA');
        $this->financial($china, '75.00');
        $this->costEntry($china, 'product_cost', '22.00', 'actual');
        $this->costEntry($china, 'payment_fee',  '2.25',  'actual');
        // last_mile_cost deliberately absent → should get no_projection_china_direct (not $10 fallback)

        // P82-RESTATE: 10-day-old order where actual replaced an estimate (audit trail demo)
        $restate = $this->order('P82-RESTATE', 'P8.2 UAT Estimate→Actual Restated (10d)', 10, 'US', 2, $user);
        $this->shipWith($restate, $overseas, 'DHL', 'P82-TRACK-RESTATE');
        $this->financial($restate, '80.00');
        $this->costEntry($restate, 'product_cost',           '25.00', 'actual');
        $this->costEntry($restate, 'first_mile_cost',        '5.00',  'actual');
        $this->costEntry($restate, 'overseas_handling_cost', '3.00',  'actual');
        // last_mile_cost: was estimated, now actual (simulated post-restatement state)
        $this->costEntry($restate, 'last_mile_cost',         '11.50', 'provider_import', 'DHL-BILL-2026-001');
        $this->costEntry($restate, 'payment_fee',            '2.40',  'actual');
        $this->costEntry($restate, 'customs_tax',            '3.00',  'actual');

        $this->command->line('');
        $this->command->line('P8.2 UAT fixture seeded successfully.');
        $this->command->line('');
        $this->command->line(sprintf('  Login: %s', $uatEmail));
        $this->command->line('  Password: supplied via BYL_P82_UAT_PASSWORD (not printed)');
        $this->command->line('');
        $this->command->line('  Order IDs:');
        $this->command->line(sprintf('    P82-FRESH  → id=%d  (fresh_estimate_allowed, 3d)',  $fresh->id));
        $this->command->line(sprintf('    P82-EST    → id=%d  (estimate_allowed, 20d)',        $est->id));
        $this->command->line(sprintf('    P82-DUE    → id=%d  (reconciliation_due, 35d, amber banner)', $due->id));
        $this->command->line(sprintf('    P82-OVER   → id=%d  (cost_overdue, 50d, red banner)', $over->id));
        $this->command->line(sprintf('    P82-FINAL  → id=%d  (all confirmed, final_ready)',   $final->id));
        $this->command->line(sprintf('    P82-CHINA  → id=%d  (china-direct, no USD10 fallback)', $china->id));
        $this->command->line(sprintf('    P82-RESTATE→ id=%d  (actual replaced estimate)',     $restate->id));
    }

    /**
     * No fixture credential values or public-domain accounts are shipped in code.
     * Validate both values before making any database changes.
     *
     * @return array{string,string}
     */
    private static function credentials(): array
    {
        $email = getenv(self::UAT_EMAIL_ENV);
        $password = getenv(self::UAT_PASSWORD_ENV);

        if (!is_string($email) || !preg_match('/^[A-Za-z0-9._%+-]+@example\\.invalid$/D', $email)) {
            throw new \RuntimeException('Set BYL_P82_UAT_EMAIL to a synthetic @example.invalid address.');
        }

        if (!is_string($password) || strlen($password) < 16) {
            throw new \RuntimeException('Set BYL_P82_UAT_PASSWORD to a local-only secret of at least 16 characters.');
        }

        return [$email, $password];
    }

    private function user(string $email, string $password): User
    {
        $perm = Permission::firstOrCreate(['key' => 'accounting.view'], ['label' => 'Accounting View']);
        $permOrders = Permission::firstOrCreate(['key' => 'orders.view'], ['label' => 'Orders View']);
        $permDash = Permission::firstOrCreate(['key' => 'dashboard.view'], ['label' => 'Dashboard View']);

        $role = Role::firstOrCreate(['key' => 'p82_uat_owner'], ['label' => 'P8.2 UAT Owner']);
        $role->permissions()->syncWithoutDetaching([$perm->id, $permOrders->id, $permDash->id]);

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'P8.2 UAT Owner', 'password' => Hash::make($password), 'is_active' => true],
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function order(string $number, string $recipient, int $ageDays, string $country, int $qty, User $owner): Order
    {
        $order = Order::create([
            'internal_order_number' => $number,
            'source_type'           => OrderSourceType::Website,
            'order_kind'            => OrderKind::Customer,
            'external_order_number' => $number,
            'order_date'            => now()->subDays($ageDays)->toDateString(),
            'order_note'            => 'P8.2 UAT synthetic fixture',
            'owner_id'              => $owner->id,
            'order_status'          => OrderStatus::Shipped,
        ]);
        $order->address()->create([
            'recipient_name' => $recipient, 'phone' => '+1 555 0199',
            'country' => $country, 'province_state' => 'CA',
            'city' => 'UAT City', 'address_line_1' => '1 P82 UAT St',
            'postal_code' => '00000',
        ]);
        $order->items()->create([
            'product_summary' => 'P8.2 UAT Product',
            'sku'             => 'P82-SKU-'.$number,
            'quantity'        => $qty,
            'unit_price'      => '29.99',
            'line_revenue'    => number_format(29.99 * $qty, 2, '.', ''),
        ]);

        return $order;
    }

    private function shipWith(Order $order, Warehouse $warehouse, string $carrier, string $tracking): void
    {
        Shipment::create([
            'order_id'         => $order->id,
            'warehouse_id'     => $warehouse->id,
            'shipment_status'  => 'shipped',
            'carrier'          => $carrier,
            'tracking_number'  => $tracking,
            'shipped_at'       => now()->subDay(),
            'handled_by'       => null,
        ]);
    }

    private function financial(Order $order, string $amountPaid): void
    {
        OrderFinancial::create([
            'order_id'      => $order->id,
            'amount_paid'   => $amountPaid,
            'refund_amount' => '0.00',
            'currency'      => 'USD',
        ]);
    }

    private function costEntry(Order $order, string $type, string $amount, string $provenance, ?string $sourceRef = null): void
    {
        OrderCostEntry::create([
            'order_id'         => $order->id,
            'cost_type'        => $type,
            'amount'           => $amount,
            'currency'         => 'USD',
            'provenance'       => $provenance,
            'source_reference' => $sourceRef ?? strtoupper($type).'-'.strtoupper($provenance).'-UAT',
            'effective_at'     => now(),
        ]);
    }
}
