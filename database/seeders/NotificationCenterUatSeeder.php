<?php

namespace Database\Seeders;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\NotificationCenter\Application\NotificationPublisher;
use Illuminate\Database\Seeder;

final class NotificationCenterUatSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'admin@local.test')->first();
        if (! $user) return;
        $user->notifications()->delete();
        $publisher = app(NotificationPublisher::class);
        foreach ([
            ['category'=>'procurement','title'=>'订单生成采购需求','message'=>'订单 #1001 包含缺货产品（2 个 SKU），已生成采购需求。','action_url'=>'/procurement','actor_name'=>'System','related_links'=>[['label'=>'相关订单','value'=>'#1001','url'=>'/customers/orders'],['label'=>'相关采购需求','value'=>'PR#PR-2026-001','url'=>'/procurement']]],
            ['category'=>'inventory','title'=>'库存低于安全库存','message'=>'产品 PKG-UAT-001 当前库存 5，低于安全库存 20。','action_url'=>'/inventory','actor_name'=>'System','related_links'=>[['label'=>'相关产品','value'=>'PKG-UAT-001','url'=>'/products']]],
            ['category'=>'procurement','title'=>'采购单已创建','message'=>'PO#PO-2026-001 已创建，包含 3 个产品。','action_url'=>'/procurement','actor_name'=>'System','related_links'=>[['label'=>'相关采购单','value'=>'PO#PO-2026-001','url'=>'/procurement']]],
            ['category'=>'shipment','title'=>'订单已发货','message'=>'订单 #998 已发货，可进入订单查看物流信息。','action_url'=>'/customers/orders','actor_name'=>'System','related_links'=>[['label'=>'相关订单','value'=>'#998','url'=>'/customers/orders']]],
            ['category'=>'customer','title'=>'新客户注册','message'=>'一位新客户已进入 ERP 客户主数据。','action_url'=>'/customers','actor_name'=>'System','related_links'=>[['label'=>'相关客户','value'=>'客户列表','url'=>'/customers']]],
        ] as $payload) $publisher->publishToUser($user, $payload);
    }
}
