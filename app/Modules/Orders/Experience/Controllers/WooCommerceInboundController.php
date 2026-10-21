<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Orders\Application\Inbound\WooCommerceInboundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class WooCommerceInboundController
{
    public function __construct(private readonly WooCommerceInboundService $inbound) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Sync/WooCommerceInbound', [
            'connection' => $this->inbound->descriptor(),
            'summary' => $request->session()->get('woocommerce_import_summary'),
        ]);
    }

    public function probe(): RedirectResponse
    {
        try {
            $result = $this->inbound->probe();
            return back()->with($result['ok'] ? 'success' : 'error', $result['ok'] ? 'WooCommerce 连接成功' : 'WooCommerce 连接失败');
        } catch (Throwable $e) {
            return back()->with('error', 'WooCommerce 连接失败：'.$e->getMessage());
        }
    }

    public function single(Request $request): RedirectResponse
    {
        $data = $request->validate(['woo_order_id' => ['required', 'integer', 'min:1']]);
        return $this->run(fn () => $this->inbound->importOne((int) $data['woo_order_id'], $request->user()));
    }

    public function incremental(Request $request): RedirectResponse
    {
        return $this->run(fn () => $this->inbound->importToday($request->user()));
    }

    public function historical(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        return $this->run(fn () => $this->inbound->importHistorical($data['from'], $data['to'], $request->user()));
    }

    private function run(callable $callback): RedirectResponse
    {
        try {
            $summary = $callback();
            return back()->with('success', sprintf('导入完成：新建 %d，更新 %d，跳过 %d，失败 %d', $summary['created'], $summary['updated'], $summary['skipped'] ?? 0, $summary['failed']))
                ->with('woocommerce_import_summary', $summary);
        } catch (Throwable $e) {
            return back()->with('error', 'WooCommerce 导入失败：'.$e->getMessage());
        }
    }
}
