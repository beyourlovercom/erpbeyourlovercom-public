<div class="order-detail-page" data-order-detail>
    <div class="detail-header"><a href="/kol/orders" class="back-link">‹ 返回订单</a><div class="detail-order-id"><strong>{{ $order['order_number'] }}</strong><span class="status-chip status-{{ $order['shipping_status'] }}">{{ $order['shipping_status'] }}</span><span>{{ $order['warehouse'] }}</span><span>负责人 {{ $order['owner'] }}</span></div><div class="detail-actions"><button class="more-button" type="button">•••</button><button class="icon-button" data-detail-settings aria-label="页面设置">⚙</button></div></div>
    <div class="detail-settings" data-detail-panel><strong>页面设置</strong><label><input type="checkbox" checked data-card-toggle="customer-card">客户详情</label><label><input type="checkbox" checked data-card-toggle="address-card">物流信息拆分</label><label><input type="checkbox" checked data-card-toggle="shipment-card">物流发货</label><label><input type="checkbox" checked data-card-toggle="activity-card">操作记录</label><button type="button" data-reset-layout>恢复默认布局</button></div>
    <div class="detail-grid">
        @include('erp.partials.workspace-card', ['id' => 'customer-card', 'title' => '客户详情', 'badge' => '网站同步', 'class' => 'source-card', 'content' => view('erp.partials.customer-details', ['customer' => $order['customer']])])
        @include('erp.partials.workspace-card', ['id' => 'address-card', 'title' => '物流信息拆分', 'badge' => '自动解析', 'class' => 'derived-card', 'content' => view('erp.partials.logistics-address', ['address' => $order['structured_address']])])
        @include('erp.partials.workspace-card', ['id' => 'shipment-card', 'title' => '物流发货', 'badge' => '可编辑', 'class' => 'editable-card', 'content' => view('erp.partials.shipment', ['order' => $order])])
        @include('erp.partials.workspace-card', ['id' => 'activity-card', 'title' => '操作记录', 'badge' => '7 条记录', 'class' => 'activity-card', 'content' => view('erp.partials.activity-timeline', ['timeline' => $order['timeline']])])
    </div>
</div>
