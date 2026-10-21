<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type DomainCard = {
  key: string; title: string; subtitle: string; href: string; last_sync_at: string | null
  status: string; succeeded_items: number; failed_items: number; conflict_items: number
}
type SyncRun = {
  id: number; domain: string; run_type: string; provider_key: string | null; status: string
  initiator: string | null; started_at: string | null; completed_at: string | null
  requested_items: number; succeeded_items: number; failed_items: number; conflict_items: number
}
type QueueSummary = { retry_due: number; retry_waiting: number; processing: number; failed: number; conflicts: number }
type WooOrderSync = {
  enabled: boolean; frequency: string; can_operate: boolean; manual_action: string; advanced_href: string
  latest_run: SyncRun | null
}

const props = defineProps<{
  domains: DomainCard[]
  manualRuns: SyncRun[]
  automaticRuns: SyncRun[]
  queueSummary: QueueSummary
  wooOrderSync: WooOrderSync
}>()

const formatTime = (value: string | null) => value
  ? new Date(value).toLocaleString('zh-CN', { hour12: false })
  : '尚未同步'
const manualSyncToday = () => router.post(props.wooOrderSync.manual_action, {}, { preserveScroll: true })
</script>
<template>
  <Head title="同步总览 · BYL ERP" />
  <AuthenticatedLayout title="同步" :breadcrumbs="['BYL ERP', '同步']">
    <section class="mvp-page sync-overview">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">SYNC CENTER</p>
          <h2>同步总览</h2>
          <p>统一查看订单、仓库、网红与订单导出的同步状态和执行记录。</p>
        </div>
      </div>

      <div class="sync-domain-grid">
        <a v-for="domain in props.domains" :key="domain.key" class="sync-domain-card" :href="domain.href">
          <div class="sync-card-heading"><h3>{{ domain.title }}</h3><span>进入 →</span></div>
          <p>{{ domain.subtitle }}</p>
          <dl>
            <div><dt>最后同步</dt><dd>{{ formatTime(domain.last_sync_at) }}</dd></div>
            <div><dt>最近状态</dt><dd><span class="mvp-status">{{ domain.status }}</span></dd></div>
            <div><dt>成功</dt><dd>{{ domain.succeeded_items }}</dd></div>
            <div><dt>失败 / 冲突</dt><dd>{{ domain.failed_items }} / {{ domain.conflict_items }}</dd></div>
          </dl>
        </a>
      </div>

      <section class="mvp-panel woo-order-sync-panel">
        <div class="mvp-page-heading compact-heading">
          <div>
            <p class="mvp-kicker">WooCommerce Orders</p>
            <h3>WooCommerce 订单同步</h3>
            <p>自动同步每 1 小时执行一次；需要立即拉取时，可手动同步今天 00:00 至当前创建的订单。</p>
          </div>
          <div class="mvp-form-actions">
            <a class="mvp-button" :href="props.wooOrderSync.advanced_href">高级设置</a>
            <button class="mvp-button mvp-button-primary" type="button" :disabled="!props.wooOrderSync.can_operate" @click="manualSyncToday">手动同步今日订单</button>
          </div>
        </div>
        <div class="sync-metrics woo-order-sync-metrics">
          <div><strong>{{ props.wooOrderSync.enabled ? '正常' : '未启用' }}</strong><span>自动同步</span></div>
          <div><strong>{{ props.wooOrderSync.frequency }}</strong><span>执行频率</span></div>
          <div><strong>{{ formatTime(props.wooOrderSync.latest_run?.completed_at ?? props.wooOrderSync.latest_run?.started_at ?? null) }}</strong><span>上次自动同步</span></div>
          <div><strong>{{ props.wooOrderSync.latest_run ? `${props.wooOrderSync.latest_run.succeeded_items} 成功 / ${props.wooOrderSync.latest_run.failed_items} 失败` : '—' }}</strong><span>最近结果</span></div>
        </div>
        <p v-if="!props.wooOrderSync.can_operate" class="mvp-empty">当前账号只有查看权限，不能执行手动同步。</p>
      </section>

      <section class="mvp-panel">
        <div class="mvp-page-heading compact-heading"><div><h3>订单同步队列</h3></div><a class="mvp-button" href="/sync/orders">进入同步订单</a></div>
        <div class="sync-metrics">
          <div><strong>{{ props.queueSummary.retry_due }}</strong><span>到期重试</span></div>
          <div><strong>{{ props.queueSummary.retry_waiting }}</strong><span>等待重试</span></div>
          <div><strong>{{ props.queueSummary.processing }}</strong><span>处理中</span></div>
          <div><strong>{{ props.queueSummary.failed }}</strong><span>失败</span></div>
          <div><strong>{{ props.queueSummary.conflicts }}</strong><span>冲突</span></div>
        </div>
      </section>

      <section class="mvp-panel">
        <h3>手动同步记录</h3>
        <div class="mvp-table-wrap"><table class="mvp-table">
          <thead><tr><th>时间</th><th>执行人</th><th>同步域</th><th>平台</th><th>操作</th><th>结果</th><th>状态</th></tr></thead>
          <tbody>
            <tr v-for="run in props.manualRuns" :key="run.id">
              <td>{{ formatTime(run.started_at) }}</td><td>{{ run.initiator ?? '—' }}</td><td>{{ run.domain }}</td>
              <td>{{ run.provider_key ?? 'system' }}</td><td><a :href="`/sync/runs/${run.id}`">{{ run.run_type }}</a></td>
              <td>{{ run.succeeded_items }} 成功 / {{ run.failed_items }} 失败 / {{ run.conflict_items }} 冲突</td><td><span class="mvp-status">{{ run.status }}</span></td>
            </tr>
            <tr v-if="props.manualRuns.length === 0"><td colspan="7" class="mvp-empty">暂无手动同步记录</td></tr>
          </tbody>
        </table></div>
      </section>
      <section class="mvp-panel">
        <h3>自动同步记录</h3>
        <div class="mvp-table-wrap"><table class="mvp-table">
          <thead><tr><th>时间</th><th>同步域</th><th>平台</th><th>操作</th><th>结果</th><th>状态</th></tr></thead>
          <tbody>
            <tr v-for="run in props.automaticRuns" :key="run.id">
              <td>{{ formatTime(run.started_at) }}</td><td>{{ run.domain }}</td><td>{{ run.provider_key ?? 'system' }}</td>
              <td><a :href="`/sync/runs/${run.id}`">{{ run.run_type }}</a></td>
              <td>{{ run.succeeded_items }} 成功 / {{ run.failed_items }} 失败 / {{ run.conflict_items }} 冲突</td><td><span class="mvp-status">{{ run.status }}</span></td>
            </tr>
            <tr v-if="props.automaticRuns.length === 0"><td colspan="6" class="mvp-empty">暂无自动同步记录</td></tr>
          </tbody>
        </table></div>
      </section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.sync-overview { display: grid; gap: var(--byl-space-20); }
.sync-domain-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--byl-space-16); }
.sync-domain-card { display: grid; gap: var(--byl-space-12); padding: var(--byl-space-20); border: 1px solid var(--byl-border-subtle); border-radius: var(--byl-radius-lg); background: var(--byl-surface); color: var(--byl-text); text-decoration: none; box-shadow: var(--byl-shadow-card); }
.sync-card-heading { display: flex; justify-content: space-between; gap: var(--byl-space-12); align-items: center; }
.sync-card-heading h3, .sync-domain-card p { margin: 0; }
.sync-domain-card p, dt { color: var(--byl-text-muted); }
dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--byl-space-10); margin: 0; }
dl div { display: grid; gap: var(--byl-space-4); }
dd { margin: 0; font-weight: 600; }
.mvp-panel { display: grid; gap: var(--byl-space-12); padding: var(--byl-space-20); border: 1px solid var(--byl-border-subtle); border-radius: var(--byl-radius-lg); background: var(--byl-surface); }
.mvp-panel h3 { margin: 0; }
.compact-heading { margin: 0; }
.sync-metrics { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: var(--byl-space-10); }
.sync-metrics div { display: grid; gap: var(--byl-space-4); padding: var(--byl-space-12); border-radius: var(--byl-radius-md); background: var(--byl-readonly-bg); }
.sync-metrics strong { font-size: var(--byl-font-size-20); }
.sync-metrics span { color: var(--byl-text-muted); }
.woo-order-sync-panel { border-width: 2px; }
.woo-order-sync-metrics { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.woo-order-sync-metrics strong { font-size: var(--byl-font-size-16); }
@media (max-width: 800px) {
  .sync-domain-grid { grid-template-columns: 1fr; }
  .sync-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .woo-order-sync-metrics { grid-template-columns: 1fr; }
}
</style>
