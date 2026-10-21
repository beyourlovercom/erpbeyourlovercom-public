<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type RunItem = {
  id: number; sequence: number; operation: string; order_id: number | null; shipment_id: number | null
  outbox_id: number | null; provider_reference: string | null; status: string; result_code: string | null
  error_code: string | null; error_message: string | null; started_at: string | null; completed_at: string | null
}
type Run = {
  id: number; run_type: string; provider_key: string | null; connection_key: string | null; status: string
  requested_items: number; processed_items: number; succeeded_items: number; retrying_items: number
  failed_items: number; conflict_items: number; skipped_items: number; initiator: string | null
  started_at: string | null; completed_at: string | null; items: RunItem[]
}

defineProps<{ run: Run }>()
</script>

<template>
  <Head :title="`Sync Run #${run.id} · BYL ERP`" />
  <AuthenticatedLayout title="Sync Run" :breadcrumbs="['BYL ERP', '同步', `Run #${run.id}`]">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">Sync Orchestration Evidence</p><h2>Sync Run #{{ run.id }}</h2><p>{{ run.run_type }} · {{ run.provider_key ?? '—' }} · {{ run.connection_key ?? '—' }}</p></div><div class="mvp-form-actions page-header-actions"><a class="mvp-button" href="/sync">返回同步</a></div></div>
      <div class="mvp-table-wrap">
        <table class="mvp-table">
          <thead><tr><th>状态</th><th>请求</th><th>处理</th><th>成功</th><th>重试</th><th>冲突</th><th>失败</th><th>跳过</th></tr></thead>
          <tbody><tr><td><span class="mvp-status">{{ run.status }}</span></td><td>{{ run.requested_items }}</td><td>{{ run.processed_items }}</td><td>{{ run.succeeded_items }}</td><td>{{ run.retrying_items }}</td><td>{{ run.conflict_items }}</td><td>{{ run.failed_items }}</td><td>{{ run.skipped_items }}</td></tr></tbody>
        </table>
      </div>

      <div class="mvp-table-wrap">
        <table class="mvp-table">
          <thead><tr><th>#</th><th>Operation</th><th>Order</th><th>Shipment</th><th>Outbox</th><th>Remote Ref</th><th>状态</th><th>结果</th><th>错误</th></tr></thead>
          <tbody>
            <tr v-for="item in run.items" :key="item.id">
              <td>{{ item.sequence }}</td><td>{{ item.operation }}</td><td>{{ item.order_id ?? '—' }}</td>
              <td>{{ item.shipment_id ?? '—' }}</td><td>{{ item.outbox_id ?? '—' }}</td><td>{{ item.provider_reference ?? '—' }}</td>
              <td><span class="mvp-status">{{ item.status }}</span></td><td>{{ item.result_code ?? '—' }}</td>
              <td>{{ item.error_code ?? '—' }}<span v-if="item.error_message"> · {{ item.error_message }}</span></td>
            </tr>
            <tr v-if="run.items.length === 0"><td colspan="9" class="mvp-empty">此 Run 没有项目</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
