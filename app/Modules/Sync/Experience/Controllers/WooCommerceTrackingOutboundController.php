<?php

namespace App\Modules\Sync\Experience\Controllers;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WooCommerceTrackingOutboundController
{
    public function __construct(private readonly WooCommerceTrackingOutboundService $tracking) {}

    public function push(Request $request, Order $order): RedirectResponse
    {
        try {
            $result = $this->tracking->pushOne($order, $request->user());
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return match ($result['status']) {
            'completed' => back()->with('success', 'WooCommerce Tracking 已写回并验证'),
            'processing' => back()->with('info', 'WooCommerce Tracking 正在处理'),
            'retry_wait' => back()->with('warning', 'WooCommerce Tracking 正在等待下一次重试时间'),
            'retry_scheduled' => back()->with('warning', 'WooCommerce Tracking 写回失败，已进入重试队列'),
            'conflict' => back()->with('error', 'WooCommerce 已存在不同 Tracking，ERP 未覆盖远端数据'),
            default => back()->with('error', 'WooCommerce Tracking 写回失败'),
        };
    }
}
