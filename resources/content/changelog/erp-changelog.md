# BYL ERP Changelog

## 2026-09-08

今天完成并合并到主线的 ERP 开发：

### Logistics / Carrier

- 完成中国工厂按目的国家/地区选择物流商：墨西哥、智利使用 Weship / 纬狮；阿根廷、乌克兰、俄罗斯使用 POSTNL / 佰事达；泰国、印度尼西亚、越南使用 KingTrans / 唐歌；其余继续沿用既有 4PX / 速达规则。
- 统一承运商中英文显示，并新增 `JD / 京东快递` 到承运商主数据。
- 将“亏损订单”从 Logistics 移到 Accounting，由 Finance 作为亏损订单的单一统计与查看入口，减少物流模块与财务逻辑耦合。

### Inventory / Packaging

- 新增物流包裹扫描 XLSX 的只读包装证据预览，可按订单与 SKU 匹配实际扫描重量/尺寸，并对单件、多件、多 SKU、无法匹配等情况安全分类。
- `1 × 1 × 1` 这类占位尺寸不会被当作正式产品包装尺寸；本阶段仅预览，不自动写入产品包装标准。

### Settings / Changelog

- 完成只读版本记录页 `/settings/changelog`：所有记录统一来自单一 Markdown 文件，按日期切换时不刷新整个页面。
- 移除版本记录的新建、编辑、发布入口，正式采用“人工确认后记录”的轻量发布方式。
- 版本记录页面已部署到正式 ERP。

## 2026-09-07

今天完成并合并到主线的 ERP 开发：

### Accounting / Order Profit

- 完成 Accounting A8 Profit Dashboard 与证据化订单成本物化。
- 完成 Woo 财务证据 bootstrap，并补齐 legacy refund 兼容。
- 完成 Accounting V1.1B UTC session、preview 与 Woo JSON 幂等性加固。

### Logistics / Fulfillment

- Historical Logistics R0–R7 完成最终收口，并补齐历史订单、Shipment Attempt 与 Owner UAT 控制中心。
- 修复人工选择仓库后的库存 Reservation 链路。
- 完成仓库路由策略集中化，以及美国承运商 / USPS Zone、加拿大、德国分层路由规则。
- 增加 Global Fulfillment Plan Preview，用于比较履约方案后再由人工决策。

### Inventory

- 增加 Warehouse Fulfillment Readiness Gate，用于判断仓库是否具备真实履约条件。

### 记录规则

- 这里只记录已经完成并进入主线的成果。
- 仍在开发、讨论或等待 UAT / Release Gate 的内容不写入正式版本记录。
