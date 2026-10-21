<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ExportOption = { key: string; label: string; href: string }
defineProps<{ exports: ExportOption[] }>()
</script>

<template>
  <Head title="导出平台订单 · BYL ERP" />
  <AuthenticatedLayout title="导出平台订单" :breadcrumbs="['BYL ERP', '同步', '导出平台订单']">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">ORDER EXPORT</p><h2>导出平台订单</h2><p>导出只生成数据文件，不修改订单、Shipment 或同步状态。</p></div></div>
      <div class="mvp-form">
        <h3>当前可用导出</h3>
        <div class="mvp-actions">
          <a v-for="option in exports" :key="option.key" class="mvp-button mvp-button-primary" :href="option.href">{{ option.label }}</a>
        </div>
        <p class="mvp-muted">WooCommerce / Shopify 等按来源平台筛选将在对应 Connector 接入后继续扩展；当前不伪造未存在的平台导出。</p>
      </div>
      <a class="mvp-button" href="/sync">返回同步总览</a>
    </section>
  </AuthenticatedLayout>
</template>
