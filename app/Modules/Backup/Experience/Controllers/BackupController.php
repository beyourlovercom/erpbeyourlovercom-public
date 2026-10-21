<?php

namespace App\Modules\Backup\Experience\Controllers;

use App\Modules\Backup\Application\BackupRunData;
use App\Modules\Backup\Application\BackupScheduleCadence;
use App\Modules\Backup\Application\BackupScheduleSettings;
use App\Modules\Backup\Application\Contracts\BackupRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BackupController
{
    public function __construct(
        private readonly BackupScheduleSettings $settings,
        private readonly BackupRepository $repository,
    ) {}

    public function index(Request $request): Response
    {
        $schedule = $this->settings->current();
        $time = (string) config('backup.automatic.time', '02:00');
        $timezone = (string) config('backup.automatic.timezone', 'UTC');

        return Inertia::render('Backup/Index', [
            'actions' => [
                ['key' => 'create', 'label' => '立即备份', 'available' => false],
                ['key' => 'download', 'label' => '下载备份', 'available' => false],
                ['key' => 'encrypted_download', 'label' => '加密并下载', 'available' => false],
                ['key' => 'publish_github', 'label' => '加密并发布到 GitHub', 'available' => false],
            ],
            'history' => array_map(fn (BackupRunData $run): array => $this->historyRow($run), $this->repository->recentRuns(5)),
            'storageUsage' => null,
            'policy' => [
                'format' => config('backup.format.name'),
                'includedRoots' => config('backup.storage.included_roots'),
                'workspace' => config('backup.storage.workspace'),
                'status' => '备份引擎已接入；手动操作入口暂未启用',
            ],
            'automaticBackup' => [
                'enabled' => $schedule->enabled,
                'cadence' => $schedule->cadence->value,
                'canManage' => (bool) $request->user()?->can('backup.manage'),
                'retentionCount' => (int) config('backup.automatic.retention_count', 5),
                'options' => [
                    ['value' => 'daily', 'label' => '每天', 'detail' => "每天 {$time} {$timezone}"],
                    ['value' => 'weekly', 'label' => '每周', 'detail' => "每周一 {$time} {$timezone}"],
                    ['value' => 'monthly', 'label' => '每月', 'detail' => "每月 1 日 {$time} {$timezone}"],
                ],
            ],
            'restore' => [
                'local' => ['available' => false, 'status' => '恢复基础已就绪，操作入口尚未启用'],
                'github' => ['available' => false, 'status' => 'GitHub 恢复基础已就绪，操作入口尚未启用'],
            ],
        ]);
    }

    public function updateAutomaticBackup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'cadence' => ['required', 'string', 'in:daily,weekly,monthly'],
        ]);

        $this->settings->update(
            (bool) $validated['enabled'],
            BackupScheduleCadence::from($validated['cadence']),
        );

        return redirect()->route('backup.index')->with('success', '自动备份设置已保存。');
    }

    private function historyRow(BackupRunData $run): array
    {
        return [
            'id' => $run->id,
            'runUuid' => $run->runUuid,
            'type' => $run->backupType->value,
            'state' => $run->state->value,
            'source' => ($run->metadata['origin'] ?? null) === 'scheduled' ? '自动备份' : '手动/系统',
            'cadence' => $run->metadata['cadence'] ?? null,
            'createdAt' => $run->createdAt?->toIso8601String(),
        ];
    }
}
