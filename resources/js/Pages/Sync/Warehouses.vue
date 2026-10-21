<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Warehouse = {
  id: number; code: string; name: string; country_code: string | null
  is_active: boolean; connector_status: string; last_sync_at: string | null
}
defineProps<{ warehouses: Warehouse[] }>()
</script>

<template>
  <Head title="同步仓库 · BYL ERP" />
  <AuthenticatedLayout title="同步仓库" :breadcrumbs="['BYL ERP', '同步', '同步仓库']">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">WAREHOUSE SYNC</p><h2>同步仓库</h2><p>统一承载各仓库 Connector、库存同步、订单下发与回传状态。</p></div></div>
      <div class="mvp-table-wrap"><table class="mvp-table">
        <thead><tr><th>仓库</th><th>代码</th><th>国家/地区</th><th>状态</th><th>Connector</th><th>最后同步</th></tr></thead>
        <tbody>
          <tr v-for="warehouse in warehouses" :key="warehouse.id">
            <td>{{ warehouse.name }}</td><td>{{ warehouse.code }}</td><td>{{ warehouse.country_code ?? '—' }}</td>
            <td><span class="mvp-status">{{ warehouse.is_active ? 'active' : 'inactive' }}</span></td>
            <td><span class="mvp-status">{{ warehouse.connector_status === 'not_configured' ? '未配置' : warehouse.connector_status }}</span></td>
            <td>{{ warehouse.last_sync_at ?? '尚未同步' }}</td>
          </tr>
          <tr v-if="warehouses.length === 0"><td colspan="6" class="mvp-empty">暂无仓库</td></tr>
        </tbody>
      </table></div>
      <p class="mvp-muted">当前页面只展示真实仓库 Authority；未接入的 Connector 明确显示“未配置”，不会伪造同步状态。</p>
      <a class="mvp-button" href="/sync">返回同步总览</a>
    </section>
  </AuthenticatedLayout>
</template>
