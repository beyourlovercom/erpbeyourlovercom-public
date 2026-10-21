<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
type Status = { key: string; label: string; url: string; count: number }
defineProps<{ statuses: Status[] }>()
const page = usePage()
</script>
<template>
  <nav class="logistics-status-bar" aria-label="物流状态工作台">
    <Link v-for="status in statuses" :key="status.key" :href="status.url" class="logistics-status-tab" :class="{ active: page.url.split('?')[0] === status.url }">
      <span>{{ status.label }}</span><strong>{{ status.count }}</strong>
    </Link>
  </nav>
</template>
<style scoped>
.logistics-status-bar { display:flex; gap:8px; overflow-x:auto; padding:10px 0 14px; border-bottom:1px solid #dbe4e7; margin-bottom:18px; }
.logistics-status-tab { display:inline-flex; align-items:center; gap:8px; white-space:nowrap; padding:8px 12px; border:1px solid #dbe4e7; border-radius:8px; background:#fff; color:#40545b; text-decoration:none; font-size:13px; }
.logistics-status-tab strong { color:#183d49; }.logistics-status-tab.active { border-color:#2b7a65; background:#eef8f3; color:#155a47; }
</style>
