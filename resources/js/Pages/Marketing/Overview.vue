<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
defineProps<{ metrics: { subscribers: number; subscriptions: number; subscribed: number } }>()
</script>
<template>
  <Head title="营销 · BYL ERP" />
  <AuthenticatedLayout title="营销" :breadcrumbs="['BYL ERP', '营销']">
    <div class="erp-form-page"><div class="erp-form-content">
      <div class="erp-form-header-row"><div><p class="mvp-kicker">MARKETING OS M1.0</p><h1 class="erp-form-title">营销</h1><p class="erp-form-subtitle">订阅身份与授权事实的统一只读工作台。</p></div></div>
      <div class="marketing-kpi-grid">
        <article class="erp-form-card"><p>订阅者身份</p><strong>{{ metrics.subscribers }}</strong></article>
        <article class="erp-form-card"><p>订阅事实</p><strong>{{ metrics.subscriptions }}</strong></article>
        <article class="erp-form-card"><p>已订阅事实</p><strong>{{ metrics.subscribed }}</strong></article>
      </div>
      <section class="erp-form-card marketing-entry"><h2 class="erp-form-card-title">邮件订阅</h2><p>当前仅展示 ERP 中已有的订阅身份与 provider/source 授权事实，不从客户或订单推导同意。</p><Link class="erp-btn erp-btn-primary" href="/marketing/subscribers">查看订阅者</Link></section>
    </div></div>
  </AuthenticatedLayout>
</template>
<style scoped>.marketing-kpi-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:24px}.marketing-kpi-grid p{margin:0;color:#7a8c94}.marketing-kpi-grid strong{display:block;margin-top:8px;font-size:1.75rem}.marketing-entry p{color:#52646b}@media(max-width:700px){.marketing-kpi-grid{grid-template-columns:1fr}}</style>
