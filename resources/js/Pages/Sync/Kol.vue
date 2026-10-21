<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Platform = { key: string; label: string; status: string; last_sync_at: string | null }
defineProps<{ platforms: Platform[] }>()
</script>

<template>
  <Head title="同步网红 · BYL ERP" />
  <AuthenticatedLayout title="同步网红" :breadcrumbs="['BYL ERP', '同步', '同步网红']">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">KOL SYNC</p><h2>同步网红</h2><p>从外部社交平台同步网红公开资料和平台指标，人工维护字段保持独立。</p></div></div>
      <div class="mvp-table-wrap"><table class="mvp-table">
        <thead><tr><th>平台</th><th>连接状态</th><th>最后同步</th><th>数据原则</th></tr></thead>
        <tbody>
          <tr v-for="platform in platforms" :key="platform.key">
            <td>{{ platform.label }}</td><td><span class="mvp-status">{{ platform.status === 'not_configured' ? '未配置' : platform.status }}</span></td>
            <td>{{ platform.last_sync_at ?? '尚未同步' }}</td><td>平台 Snapshot 不直接覆盖人工字段</td>
          </tr>
        </tbody>
      </table></div>
      <a class="mvp-button" href="/sync">返回同步总览</a>
    </section>
  </AuthenticatedLayout>
</template>
