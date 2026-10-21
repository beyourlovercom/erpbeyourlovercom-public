<?php

namespace App\Modules\Shell\Experience\Controllers;

use App\Modules\Shell\Application\ErpShellNavigation;
use App\Modules\Shell\Application\LegacyErpNavigationPaths;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class ShellPlaceholderController
{
    public function __construct(
        private readonly LegacyErpNavigationPaths $legacyPaths,
        private readonly ErpShellNavigation $navigation,
    ) {}

    public function __invoke(array $module, Request $request): InertiaResponse|Response|View
    {
        if (Gate::allows($module['permission'])) {
            $formal = collect($this->navigation->for($request->user()))->firstWhere('key', $module['key']);
            abort_unless($formal, 403);

            return Inertia::render('Shell/ModuleOverview', [
                'module' => $formal,
                'description' => $this->description($module['key']),
            ]);
        }

        if (Gate::allows('prototype.view') && in_array($this->legacyPaths->normalize($module['path']), $this->legacyPaths->all(config('erp_navigation')), true)) {
            return app(ErpShellController::class)->show($module['key'], trim($module['path'], '/'));
        }

        abort(403);
    }
    private function description(string $key): string
    {
        return match ($key) {
            'customers' => '客户资料与客户订单的统一入口。',
            'kol' => '网红履约订单与相关操作的统一入口。',
            'procurement' => '采购记录、文件与后续采购工作的统一入口。',
            'guides' => 'ERP 操作教程、视频与手册的统一入口。',
            'accounting' => '订单成本、费用分析与会计相关工作的统一入口。',
            'settings' => '用户、权限、仓库与系统配置的统一入口。',
            default => $key.' 模块入口。',
        };
    }

}
