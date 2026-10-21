<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Application\OverseaManualShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OverseaManualShipmentController extends Controller
{
    public function __invoke(Request $request, Order $order, OverseaManualShipmentService $service): RedirectResponse
    {
        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:128'],
        ]);

        $result = $service->confirmUsSm($order, $data['tracking_number'], $request->user());
        $syncStatus = (string) data_get($result, 'sync.status', 'attention');

        $message = $syncStatus === 'completed'
            ? '世迈已确认发货，Tracking 已写入并完成 Woo 同步。'
            : '世迈已确认发货并写入 Tracking；Woo 同步需关注。';

        return redirect('/logistics/oversea/us-3sm?view=shipped')
            ->with($syncStatus === 'completed' ? 'success' : 'warning', $message);
    }
}
