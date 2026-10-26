# ERP 订单商品修改 V1 · A8 发布门禁与 UAT

**状态：Draft PR #16 / NOT PRODUCTION APPROVED**

## 权威边界

- GitHub PR: https://github.com/beyourlovercom/erpbeyourlovercom-public/pull/16
- 目标：订单商品 SKU、颜色对应产品、数量、赠品的安全修改；按新产品/仓库重新规划库存预留和待履约分仓。
- Production ERP 不变，禁止在生产数据库中创建测试发货、修改真实客户 SKU、释放真实库存或生成 Woo/退款。
- GitHub main 不变，PR 保持 Draft，直至角色 UAT、数据库回滚和 Owner 放行。

## A5 A5-B 事务契约

1. 当前仅支持未发货、未财务记账且无需 Woo 双向同步的原始手工订单。
2. 预检带版本 SHA-256，包含父子订单商品、分仓、发货与库存预留。操作员必须提供确认原因并逐行选仓。
3. 单仓事务：锁订单/行/库存预留 → 释放旧 reserved → 修改 parent item → 更新 allocation → 建立新 reserved。
4. 多仓事务：锁父订单、每个原始子履约和库存事实 → 逐一释放旧 reserved → 更新 parent item → 重建 pending 子单 → 新子单逐仓 reserved。
5. 任一环节异常/缺货/仓库冲突全量回滚；保留原 held inventory 与订单商品。
6. 不自动发货、不扣减 committed 库存、不触发真实物流、不调用 Woo/支付、不执行退款。

## A6 历史不可变门禁

- 已发货、部分发货、已扣减库存、已生成物流尝试、补发、人工仓审核、财务结算的履约腿不得被重建或删除。
- 多仓减少原履约段数量时必须拒绝，直至实现不会删除释放预留历史的退休/归档契约。
- 原部分发货的待发货子单改仓接口，在已有 reserved/committed 库存的情况下返回 409，不允许只改 shipment.warehouse_id。

## A7 Woo 与财务

- Woo 来源或包含 provider=woocommerce 的外部引用：禁止在普通订单编辑及 A5 事务中修改产品。尚无正式 Woo 双向商品修订契约。
- 订单已有 OrderFinancial 或 OrderCostEntry：禁止修改产品、单价、数量等需要重算金额的字段。必须先定义差额、结算、退款和审计契约。
- 上述不属于“Woo 商品同步已经完成”，仅是防错门禁。Woo inbound 更新仍须另行进行覆盖风险审计。

## A8 测试与放行清单

- [x] GitHub Functional CI 中安全事务测试、Vue 测试、构建验证。
- [x] GitHub Source Checks。
- [x] 与正式站完全隔离的 US Mac mini UAT worktree，使用 **内存 SQLite**、无生产 .env、无真实 Woo 发货。
- [x] 本地订单商品及部分履约专项回归通过（25 tests, 108 assertions；无 warning）。
- [x] 全量 PHP 基线和差异分析归档：同一 US Mac mini、相同 vendor/构建、独立内存 SQLite；`main d7df4e16`：1831 passed / 46 failed / 6 skipped / 16839 assertions；`PR #16 1bfe4c8d`：1866 passed / 46 failed / 6 skipped / 17022 assertions。失败项目清单相同，没有新增失败；**46 项旧失败仍保持 OPEN，不算 full-suite PASS**。
- [ ] MySQL Dev 数据库的并发库存锁/事务回滚测试（SQLite 成功不等于生产 MySQL 并发证明）。
- [ ] 使用真实员工权限的 Dev Desktop UAT：修改颜色、增加赠品、改数量、单仓/多仓仓库选择、库存不足回滚、已发货拒绝、Woo/财务拒绝。
- [ ] CI 精确 HEAD 冻结；Dev 生产同版本差异检查、零迁移或明确迁移。
- [ ] Owner 明确授权发布、灰度单仓，再多仓，最后生产读回确认。
- [ ] 生产切换前只读快照、应用发布切换与独立数据库状态恢复预案。
- [ ] 真实 ERP 首次业务操作完成后核对库存预留、ERP 订单、分仓子单、Woo 状态与财务数据。

## 推荐发布序列

1. 独立 Dev/UAT 可用性与权限证据 PASS。
2. 允许单仓纯手工订单 Canary；以人工批准的小范围业务单使用。
3. 单仓写入后比对事务日志/库存，再扩大多仓；仍不对 Woo 来源或已财务记账订单开放。
4. 任一 409/422 不跳错误页；保留用户填单及 GlobalFeedbackIsland 字段说明。
5. 代码回滚不自动撤销已经提交的合法业务事务；如需业务恢复，应走经审计的反向调整，而不是回滚数据库中其它客户的已提交订单。

## 不属于本次上线放行

- 直接修改已发货商品、在已扣减库存下重建历史。
- WooCommerce 双向订单行修改、同步冲突/财务退款自动调整。
- 已有子履约合并/缩减并自动归档历史。
- UI 美化、Excel 风格数据表、全局 Tabs；均不在 Bug Fix First 主线上。

## A8 本地隔离 UAT 证据（2026-10-26）

- GitHub main SHA: `d7df4e16e912a86ac97d9840a1021a7eced359f4`；PR #16 被测业务代码 HEAD: `1bfe4c8d6955ef96beaaf5fc416c44658dedb9cd`，之后仅修改了本发行门禁文档路径。
- Candidate isolated worktree: `US Mac mini / AI/uat/order-item-a8-pr16-20261026`；baseline isolated worktree: `AI/uat/order-item-a8-main-baseline-20261026`。
- 两套代码均显式使用本地 `.env` 测试变量（APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, MAIL_MAILER=array），未复制生产密钥、未使用生产数据库。
- 初次 full-suite 候选缺失 Vite manifest，构建后重跑；同环境基线对照消除环境差异。
- 本地候选订单商品 + 部分履约专项：25 passed / 108 assertions / 0 warnings。
- GitHub Functional [PASS](https://github.com/beyourlovercom/erpbeyourlovercom-public/actions/runs/40303634972)，Source [PASS](https://github.com/beyourlovercom/erpbeyourlovercom-public/actions/runs/40303635001)。
- 需要单独记录的历史失败：日志中 Woo 产品编号约束、物流手工预留 409、部分菜单/架构冻结契约等；任何此类失败不得被“修改断言凑绿”掩盖。
- 放行判断：此证据支持 **功能候选进入 Dev 人工 UAT**，不代表生产 MySQL 并发、真实权限或 Owner 审批已经通过。
