<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FxSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_admin_sees_explicit_auto_and_manual_modes(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($admin)->get('/settings/fx')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Fx')
            ->where('reference.source', 'ECB')
            ->where('reference.referenceDate', '2026-09-07')
            ->where('reference.policy', 'explicit_mode')
            ->where('rows.0.currency', 'USD')
            ->where('rows.0.automatic', true)
            ->where('rows.0.ecbRate', 7.1)
            ->where('rows.0.source', 'ecb'));

        $this->actingAs($admin)->put('/settings/fx', $this->payload(
            rates: ['USD' => 7.12, 'PHP' => 0.125],
            automatic: ['USD' => false, 'PHP' => true],
        ))->assertRedirect();

        $this->assertDatabaseHas('finance_fx_manual_rates', [
            'currency' => 'USD', 'manual_rate_to_cny' => 7.12, 'use_auto' => 0, 'updated_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('finance_fx_manual_rates', [
            'currency' => 'PHP', 'manual_rate_to_cny' => 0.125, 'use_auto' => 1, 'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get('/settings/fx')->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.manualRate', 7.12)
            ->where('rows.0.automatic', false)
            ->where('rows.0.effectiveRate', 7.12)
            ->where('rows.0.source', 'manual')
            ->where('rows.5.currency', 'PHP')
            ->where('rows.5.manualRate', 0.125)
            ->where('rows.5.automatic', true)
            ->where('rows.5.effectiveRate', 0.12203125)
            ->where('rows.5.source', 'ecb'));
    }

    public function test_switching_to_auto_preserves_manual_value_but_uses_ecb(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($admin)->put('/settings/fx', $this->payload(
            rates: ['USD' => 7.12],
            automatic: ['USD' => false],
        ))->assertRedirect();

        $this->actingAs($admin)->put('/settings/fx', $this->payload(
            rates: ['USD' => 7.12],
            automatic: ['USD' => true],
        ))->assertRedirect();

        $this->assertDatabaseHas('finance_fx_manual_rates', [
            'currency' => 'USD', 'manual_rate_to_cny' => 7.12, 'use_auto' => 1,
        ]);
        $this->actingAs($admin)->get('/settings/fx')->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.manualRate', 7.12)
            ->where('rows.0.automatic', true)
            ->where('rows.0.effectiveRate', 7.1)
            ->where('rows.0.source', 'ecb'));
    }

    public function test_global_current_conversion_uses_manual_auto_and_fixed_rates_together(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($admin)->put('/settings/fx', $this->payload(
            rates: ['USD' => 6.78],
            automatic: ['USD' => false],
        ))->assertRedirect();

        $result = app(FxReferenceSettingsService::class)->convertToCny([
            ['currency' => 'USD', 'revenue' => 100],
            ['currency' => 'EUR', 'revenue' => 10],
            ['currency' => 'CNY', 'revenue' => 5],
        ]);

        $this->assertTrue($result['available']);
        $this->assertSame('SETTINGS_FX', $result['source']);
        $this->assertEqualsWithDelta(761.1, $result['amount_cny'], 0.00001);
        $this->assertSame('manual', $result['conversions'][0]['source']);
        $this->assertEquals(6.78, $result['conversions'][0]['rate_to_cny']);
        $this->assertSame('ecb', $result['conversions'][1]['source']);
        $this->assertEquals(7.81, $result['conversions'][1]['rate_to_cny']);
        $this->assertSame('fixed', $result['conversions'][2]['source']);
        $this->assertSame('2026-09-07', $result['reference_date']);
    }

    public function test_global_current_conversion_keeps_saved_manual_rate_but_uses_ecb_in_auto_mode(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($admin)->put('/settings/fx', $this->payload(
            rates: ['USD' => 6.78],
            automatic: ['USD' => true],
        ))->assertRedirect();

        $result = app(FxReferenceSettingsService::class)->convertToCny([
            ['currency' => 'USD', 'revenue' => 100],
        ]);

        $this->assertTrue($result['available']);
        $this->assertEqualsWithDelta(710.0, $result['amount_cny'], 0.00001);
        $this->assertSame('ecb', $result['conversions'][0]['source']);
        $this->assertDatabaseHas('finance_fx_manual_rates', [
            'currency' => 'USD', 'manual_rate_to_cny' => 6.78, 'use_auto' => 1,
        ]);
    }

    public function test_manual_current_conversion_does_not_require_ecb_availability(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response('unavailable', 503)]);
        $admin = $this->userWith(['system.permissions.manage']);
        $this->actingAs($admin)->put('/settings/fx', $this->payload(
            rates: ['USD' => 6.78],
            automatic: ['USD' => false],
        ))->assertRedirect();

        $result = app(FxReferenceSettingsService::class)->convertToCny([
            ['currency' => 'USD', 'revenue' => 100],
        ]);

        $this->assertTrue($result['available']);
        $this->assertEqualsWithDelta(678.0, $result['amount_cny'], 0.00001);
        $this->assertNull($result['reference_date']);
        $this->assertFalse($result['stale']);
        $this->assertSame('manual', $result['conversions'][0]['source']);
    }

    public function test_manual_mode_requires_a_manual_rate(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($admin)->from('/settings/fx')->put('/settings/fx', $this->payload(
            rates: ['USD' => null],
            automatic: ['USD' => false],
        ))->assertRedirect('/settings/fx')->assertSessionHasErrors('rates.USD');
    }

    public function test_settings_permission_and_rate_validation_are_enforced(): void
    {
        Http::fake(['ecb.europa.eu/*' => Http::response($this->ecbXml(), 200)]);
        $plainUser = $this->userWith([]);
        $admin = $this->userWith(['system.permissions.manage']);

        $this->actingAs($plainUser)->get('/settings/fx')->assertForbidden();
        $this->actingAs($admin)->from('/settings/fx')->put('/settings/fx', $this->payload(
            rates: ['USD' => -1],
            automatic: ['USD' => false],
        ))->assertRedirect('/settings/fx')->assertSessionHasErrors('rates.USD');
        $this->assertDatabaseMissing('finance_fx_manual_rates', ['currency' => 'USD']);
    }

    private function payload(array $rates = [], array $automatic = []): array
    {
        $currencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'PHP'];
        return [
            'rates' => collect($currencies)->mapWithKeys(fn (string $currency): array => [$currency => $rates[$currency] ?? null])->all(),
            'automatic' => collect($currencies)->mapWithKeys(fn (string $currency): array => [$currency => $automatic[$currency] ?? true])->all(),
        ];
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create(['key' => 'fx_'.uniqid(), 'label' => 'FX test role']);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['key' => $permissionName], ['label' => $permissionName]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role->id);
        return $user;
    }

    private function ecbXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
  <Cube><Cube time="2026-09-07"><Cube currency="USD" rate="1.10"/><Cube currency="GBP" rate="0.85"/><Cube currency="CAD" rate="1.50"/><Cube currency="AUD" rate="1.70"/><Cube currency="PHP" rate="64.00"/><Cube currency="CNY" rate="7.81"/></Cube></Cube>
</gesmes:Envelope>
XML;
    }
}
