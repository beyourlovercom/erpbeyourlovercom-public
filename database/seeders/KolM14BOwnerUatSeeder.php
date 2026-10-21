<?php

namespace Database\Seeders;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

final class KolM14BOwnerUatSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 500, 'KOL M1.4B UAT fixture is local/testing only.');
        abort_unless(Schema::hasTable('kols') && Schema::hasTable('kol_affiliate_accounts'), 500, 'KOL M1.4B UAT fixture requires migrated schema.');

        $manager = $this->user('kol.manager@local.test', 'UAT KOL Manager', 'UAT_KOL_MANAGER_PASSWORD', [
            'dashboard.view', 'kol.view', 'kol.operate', 'kol.views.manage', 'kol.approvals.manage', 'kol.affiliates.manage',
        ]);
        $specialist = $this->user('kol.specialist@local.test', 'UAT KOL Specialist', 'UAT_KOL_SPECIALIST_PASSWORD', [
            'dashboard.view', 'kol.view', 'kol.operate',
        ]);

        $ready = $this->kol($manager, 'UAT M1.4B Ready Success', 'uat-m14b-ready@example.test');
        $providerError = $this->kol($manager, 'UAT M1.4B Provider Error', 'uat-m14b-provider-error@example.test');
        $conflict = $this->kol($manager, 'UAT M1.4B Conflict', 'uat-m14b-conflict@example.test');
        $readback = $this->kol($manager, 'UAT M1.4B Readback', 'uat-m14b-readback@example.test');
        $active = $this->kol($manager, 'UAT M1.4B Active', 'uat-m14b-active@example.test');
        $external = $this->kol($manager, 'UAT M1.4B External Bound', 'uat-m14b-external@example.test');
        $inProgress = $this->kol($manager, 'UAT M1.4B In Progress', 'uat-m14b-progress@example.test');
        $this->kol($manager, 'UAT M1.4B Pending Approval', 'uat-m14b-pending@example.test', ['approval_status' => 'pending']);
        $this->kol($specialist, 'UAT M1.4B Specialist Own', 'uat-m14b-specialist@example.test');

        $fixtureIds = [$ready->id, $providerError->id, $conflict->id, $readback->id, $active->id, $external->id, $inProgress->id];
        KolAffiliateAccount::query()->whereIn('kol_id', $fixtureIds)->delete();

        $connectionKey = (string) config('services.andy_core.connection_key', 'beyourlover.com');
        KolAffiliateAccount::create([
            'connection_key' => $connectionKey, 'kol_id' => $active->id, 'affiliate_id' => 830001,
            'wordpress_user_id' => 730001, 'wordpress_user_email' => $active->contact_email,
            'display_name' => $active->display_name, 'email' => $active->contact_email, 'provider_status' => 'active',
            'origin' => 'erp_provisioned', 'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'active',
        ]);
        KolAffiliateAccount::create([
            'connection_key' => $connectionKey, 'kol_id' => $external->id, 'affiliate_id' => 830002,
            'wordpress_user_id' => 730002, 'wordpress_user_email' => $external->contact_email,
            'display_name' => $external->display_name, 'email' => $external->contact_email, 'provider_status' => 'pending',
            'origin' => 'external_sync', 'sync_status' => 'synced', 'reconciliation_status' => 'present', 'provisioning_status' => 'not_requested',
        ]);
        KolAffiliateAccount::create([
            'connection_key' => $connectionKey, 'kol_id' => $inProgress->id,
            'display_name' => $inProgress->display_name, 'email' => $inProgress->contact_email,
            'origin' => 'erp_provisioned', 'sync_status' => 'never_synced', 'reconciliation_status' => 'present',
            'provisioning_status' => 'in_progress', 'provision_idempotency_key' => 'uat-m14b-in-progress-key',
        ]);
    }

    private function user(string $email, string $name, string $passwordEnv, array $permissions): User
    {
        $permissionModels = collect($permissions)->map(fn (string $key) => Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        $role = Role::firstOrCreate(['key' => 'uat_'.str_replace(['@', '.'], '_', $email)], ['label' => $name]);
        $role->permissions()->sync($permissionModels->pluck('id')->all());
        $user = User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make((string) env($passwordEnv, 'password')),
            'is_active' => true,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function kol(User $owner, string $name, string $email, array $overrides = []): Kol
    {
        return Kol::updateOrCreate(['contact_email' => $email], array_merge([
            'display_name' => $name,
            'status' => 'active',
            'relationship_stage' => 'prospect',
            'owner_id' => $owner->id,
            'approval_status' => 'approved',
            'archived_at' => null,
        ], $overrides));
    }
}
