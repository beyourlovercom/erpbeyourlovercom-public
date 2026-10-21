<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

defineProps<{ totalProducts: number }>()

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, '产品']
</script>

<template>
  <Head title="产品 · BYL ERP" />
  <AuthenticatedLayout title="产品" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">产品主数据</p><h2>产品仪表盘</h2></div>
        <a class="mvp-button" href="/products/list">产品列表</a>
      </div>

      <div class="mvp-stat-grid">
        <article class="mvp-card mvp-stat-card">
          <small>产品总数</small>
          <strong>{{ totalProducts }}</strong>
        </article>
        <article class="mvp-card mvp-stat-card mvp-stat-unavailable">
          <small>在售产品</small>
          <span class="mvp-unavailable">暂无数据（发布状态待接入）</span>
        </article>
        <article class="mvp-card mvp-stat-card mvp-stat-unavailable">
          <small>缺库存产品</small>
          <span class="mvp-unavailable">暂无数据（库存数据待接入）</span>
        </article>
      </div>

      <article class="mvp-card mvp-card-wide mvp-stat-unavailable">
        <h3>产品销售图表</h3>
        <p class="mvp-unavailable">暂无数据（销售统计待接入）</p>
      </article>
    </section>
  </AuthenticatedLayout>
</template>
