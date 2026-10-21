<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Connection = { configured: boolean; url: string; connection_key: string; last_import_at: string | null }
type Summary = { seen: number; created: number; updated: number; skipped?: number; failed: number; pages: number; errors: Array<{ reference: string; message: string }> }
const props = defineProps<{ connection: Connection; summary: Summary | null }>()
const single = reactive({ woo_order_id: '' })
const historical = reactive({ from: '', to: '' })
const post = (path: string, data: any = {}) => router.post(path, data, { preserveScroll: true })
</script>

<template>
  <Head title="WooCommerce 入站 · BYL ERP" />
  <AuthenticatedLayout title="WooCommerce 入站" :breadcrumbs="['BYL ERP', '同步', 'WooCommerce 入站']">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">MVP1.1 · WooCommerce Inbound</p>
          <h2>WooCommerce 入站</h2>
          <p>把 WooCommerce 客户订单幂等写入同一个 ERP Order，不创建第二套订单表。</p>
        </div>
        <div class="mvp-form-actions page-header-actions"><a class="mvp-button" href="/sync">返回人工同步</a></div>
      </div>

      <div class="mvp-grid mvp-grid-3">
        <article class="mvp-card">
          <p class="mvp-kicker">连接</p>
          <h3>{{ props.connection.configured ? '已配置' : '未配置' }}</h3>
          <p>{{ props.connection.url || '请设置 WOO_BASE_URL' }}</p>
          <p>Connection: {{ props.connection.connection_key }}</p>
          <p>最后观察：{{ props.connection.last_import_at ?? '—' }}</p>
          <button class="mvp-button mvp-button-primary" :disabled="!props.connection.configured" @click="post('/sync/woocommerce/probe')">测试连接</button>
        </article>

        <article class="mvp-card">
          <p class="mvp-kicker">单笔</p><h3>按 Woo Order ID 导入</h3>
          <input v-model="single.woo_order_id" inputmode="numeric" placeholder="例如 12345" aria-label="Woo Order ID">
          <button class="mvp-button mvp-button-primary" :disabled="!props.connection.configured || !single.woo_order_id" @click="post('/sync/woocommerce/single', single)">导入单笔</button>
        </article>

        <article class="mvp-card">
          <p class="mvp-kicker">手动同步</p><h3>同步今天订单</h3>
          <p>自动同步每 1 小时执行一次。手动同步仅处理今天 00:00 至当前创建的 Woo 订单，昨天及更早订单不会回补。</p>
          <button class="mvp-button mvp-button-primary" :disabled="!props.connection.configured" @click="post('/sync/woocommerce/incremental')">手动同步今日订单</button>
        </article>
      </div>

      <article class="mvp-card">
        <div class="mvp-page-heading"><div><p class="mvp-kicker">历史</p><h3>按日期范围导入</h3></div></div>
        <div class="mvp-toolbar">
          <label>开始日期 <input v-model="historical.from" type="date"></label>
          <label>结束日期 <input v-model="historical.to" type="date"></label>
          <button class="mvp-button mvp-button-primary" :disabled="!props.connection.configured || !historical.from || !historical.to" @click="post('/sync/woocommerce/historical', historical)">历史导入</button>
        </div>
      </article>

      <article v-if="props.summary" class="mvp-card">
        <p class="mvp-kicker">最近一次结果</p>
        <h3>Seen {{ props.summary.seen }} · 新建 {{ props.summary.created }} · 更新 {{ props.summary.updated }} · 跳过 {{ props.summary.skipped ?? 0 }} · 失败 {{ props.summary.failed }}</h3>
        <p>API pages: {{ props.summary.pages }}</p>
        <div v-if="props.summary.errors.length" class="mvp-table-wrap">
          <table class="mvp-table"><thead><tr><th>Woo ID</th><th>失败原因</th></tr></thead><tbody><tr v-for="error in props.summary.errors" :key="error.reference + error.message"><td>{{ error.reference }}</td><td>{{ error.message }}</td></tr></tbody></table>
        </div>
      </article>
    </section>
  </AuthenticatedLayout>
</template>
