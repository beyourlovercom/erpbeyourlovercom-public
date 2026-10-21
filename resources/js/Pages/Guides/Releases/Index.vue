<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type ChangelogEntry = { date: string; html: string }
const props = defineProps<{ entries: ChangelogEntry[]; initialDate: string | null }>()

const selectedDate = ref(props.initialDate ?? props.entries[0]?.date ?? '')
const selectedEntry = computed(() => props.entries.find((entry) => entry.date === selectedDate.value) ?? props.entries[0] ?? null)

const selectDate = (date: string) => {
  selectedDate.value = date
  const url = new URL(window.location.href)
  url.searchParams.set('date', date)
  window.history.replaceState({}, '', url)
}
</script>

<template>
  <Head title="版本记录 · BYL ERP" />
  <AuthenticatedLayout title="版本记录" :breadcrumbs="['BYL ERP', '设置', '版本记录']">
    <section class="mvp-page changelog-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">ERP CHANGELOG</p>
          <h2>版本记录</h2>
          <p>按日期查看 ERP 已完成的开发记录。</p>
        </div>
      </div>

      <div v-if="entries.length" class="changelog-shell">
        <aside class="changelog-sidebar" aria-label="版本日期">
          <button
            v-for="entry in entries"
            :key="entry.date"
            type="button"
            class="changelog-date"
            :class="{ active: entry.date === selectedEntry?.date }"
            :data-date="entry.date"
            @click="selectDate(entry.date)"
          >
            {{ entry.date }}
          </button>
        </aside>

        <article v-if="selectedEntry" class="changelog-content">
          <header class="changelog-content-header">
            <h1>{{ selectedEntry.date }}</h1>
            <p>BYL ERP 开发日志</p>
          </header>
          <div class="markdown-body" v-html="selectedEntry.html" />
        </article>
      </div>

      <div v-else class="mvp-card mvp-empty">暂无版本记录。</div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.changelog-shell{display:grid;grid-template-columns:240px minmax(0,1fr);min-height:620px;border:1px solid var(--border,#dbe2e8);border-radius:12px;overflow:hidden;background:#fff}
.changelog-sidebar{padding:14px 10px;background:#f8fafc;border-right:1px solid var(--border,#dbe2e8);max-height:calc(100vh - 190px);overflow-y:auto}
.changelog-date{display:block;width:100%;border:0;background:transparent;border-radius:8px;padding:11px 14px;text-align:left;font:inherit;color:#334155;cursor:pointer}
.changelog-date:hover{background:#eef2f6}.changelog-date.active{background:#e2e8f0;color:#0f172a;font-weight:700}
.changelog-content{padding:34px 42px;min-width:0}.changelog-content-header{padding-bottom:22px;margin-bottom:24px;border-bottom:1px solid #e2e8f0}
.changelog-content-header h1{margin:0;font-size:32px;line-height:1.2;color:#0f172a}.changelog-content-header p{margin:8px 0 0;color:#64748b}
.markdown-body{font-size:15px;line-height:1.75;color:#334155}.markdown-body :deep(h3){margin:28px 0 10px;font-size:20px;color:#0f172a}.markdown-body :deep(p){margin:0 0 14px}.markdown-body :deep(ul){margin:8px 0 20px;padding-left:22px}.markdown-body :deep(li){margin:7px 0}.markdown-body :deep(strong){color:#0f172a}
@media(max-width:760px){.changelog-shell{grid-template-columns:1fr}.changelog-sidebar{display:flex;gap:8px;max-height:none;overflow-x:auto;border-right:0;border-bottom:1px solid var(--border,#dbe2e8)}.changelog-date{width:auto;white-space:nowrap}.changelog-content{padding:24px 20px}.changelog-content-header h1{font-size:26px}}
</style>
