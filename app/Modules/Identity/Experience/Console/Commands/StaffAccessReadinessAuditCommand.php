<?php

declare(strict_types=1);

namespace App\Modules\Identity\Experience\Console\Commands;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\Role;
use Illuminate\Console\Command;

final class StaffAccessReadinessAuditCommand extends Command
{
    protected $signature = 'identity:staff-access-audit
        {--expected-environment= : Required exact environment (local, testing, or staging)}
        {--json : Emit aggregate diagnostics without names, emails, IDs or secrets}';

    protected $description = 'Read-only staff Product/KOL/Logistics permissions preflight; never changes user or role grants';

    /** @var array<string, list<string>> */
    private const REQUIREMENTS = [
        'kol_specialist' => ['kol.view', 'orders.view', 'orders.create', 'products.view'],
        'kol_manager' => ['kol.view', 'kol.views.manage', 'orders.view', 'orders.create', 'products.view'],
        'logistics' => ['logistics.view', 'products.view'],
        'operations' => ['logistics.view', 'kol.view', 'kol.views.manage', 'products.view'],
    ];

    public function handle(): int
    {
        $current = (string) app()->environment();
        $expected = (string) $this->option('expected-environment');

        // A normal production runtime is never an acceptable test target, even
        // for diagnostic reads. Require an explicit positive environment match.
        if (! in_array($current, ['local', 'testing', 'staging'], true) || $expected !== $current) {
            $this->emit([
                'status' => 'blocked',
                'code' => 'DEV_ENVIRONMENT_GATE_CLOSED',
                'roles' => [],
            ]);

            return self::FAILURE;
        }

        $roles = [];
        $ready = true;

        foreach (self::REQUIREMENTS as $key => $required) {
            $preset = RolePreset::from($key);
            $role = Role::query()->where('key', $preset->roleKey())->first();
            $actual = $role?->permissions()->pluck('key')->all() ?? [];
            $missing = array_values(array_diff($required, $actual));
            sort($missing);
            $activeUsers = 0;
            $deficientUsers = 0;
            $customUsers = 0;
            $missingForUsers = array_fill_keys($required, 0);

            // Loading only minimal user columns; no PII goes to output or logs.
            foreach ($role?->users()->where('users.is_active', true)
                ->get(['users.id', 'users.permission_mode', 'users.is_active']) ?? [] as $user) {
                $activeUsers++;
                if ($user->permission_mode === 'custom') {
                    $customUsers++;
                }
                $deficient = false;
                foreach ($required as $permission) {
                    if (! $user->hasPermission($permission)) {
                        $missingForUsers[$permission]++;
                        $deficient = true;
                    }
                }
                if ($deficient) {
                    $deficientUsers++;
                }
            }

            $missingForUsers = array_filter($missingForUsers, fn (int $count): bool => $count > 0);
            if ($role === null || $missing !== [] || $deficientUsers > 0) {
                $ready = false;
            }
            $roles[$key] = [
                'template_present' => $role !== null,
                'missing_template_permissions' => $missing,
                'active_assignees' => $activeUsers,
                'custom_permission_mode_assignees' => $customUsers,
                'users_missing_any_required_permission' => $deficientUsers,
                'missing_permission_user_counts' => $missingForUsers,
            ];
        }

        $this->emit([
            'status' => $ready ? 'pass' : 'review_required',
            'code' => $ready ? 'ROLE_ACCESS_READY' : 'PERSISTED_ROLE_ACCESS_DRIFT',
            'roles' => $roles,
        ]);

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $report */
    private function emit(array $report): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line('Staff role audit: '.strtoupper((string) $report['status']));
        $this->line('Code: '.(string) $report['code']);
        foreach ($report['roles'] as $key => $result) {
            $this->line(sprintf(
                '%s: template=%s missing=%s active=%d missing_users=%d custom_mode=%d',
                $key,
                $result['template_present'] ? 'yes' : 'no',
                implode(',', $result['missing_template_permissions']) ?: 'none',
                $result['active_assignees'],
                $result['users_missing_any_required_permission'],
                $result['custom_permission_mode_assignees'],
            ));
        }
    }
}
