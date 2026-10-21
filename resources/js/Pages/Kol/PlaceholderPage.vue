<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

const props = defineProps<{ title: string; note: string }>()
const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, '网红', props.title]
</script>

<template>
  <Head :title="`${title} · BYL ERP`" />
  <AuthenticatedLayout :title="title" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">KOL 模块</p>
          <h2>{{ title }}</h2>
          <p>此功能在后续包中交付：{{ note }}</p>
        </div>
      </div>
      <div class="mvp-card placeholder-card">
        <p>此页面已在导航中注册，业务引擎将在 {{ note }} 中实现。</p>
        <p>届时此占位页将替换为真实功能，已保存的链接和权限配置不变。</p>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.placeholder-card { display: flex; flex-direction: column; gap: 8px; color: #64748b; font-size: 14px; padding: 24px; }
</style>
