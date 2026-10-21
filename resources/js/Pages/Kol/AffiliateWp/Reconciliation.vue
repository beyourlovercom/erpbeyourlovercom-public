<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Row = { source_row_number: number; identity: Record<string, unknown>; evidence: Record<string, unknown>; kol: { id: number; display_name: string } | null; affiliate_account_id: number | null; status: string; message: string }
type Batch = { id: number; source_type: string; original_filename: string; checksum: string; total_rows: number; distinct_source_identities: number; summary: Record<string, number>; rows: Row[]; created_at?: string }
const props = defineProps<{ batches: Batch[]; connectionKey: string; canOperate: boolean }>()
const file = ref<File | null>(null)
const preview = ref<Batch | null>(null)
const busy = ref(false)
const error = ref('')
const statuses = ['matched_kol_only', 'ready_for_binding', 'already_bound', 'no_affiliate', 'unresolved', 'conflict']
const choose = (event: Event) => { file.value = (event.target as HTMLInputElement).files?.[0] ?? null; error.value = '' }
const upload = async () => {
  if (!file.value) { error.value = '请选择 CSV 或 XLSX 文件'; return }
  busy.value = true; error.value = ''
  const data = new FormData(); data.append('file', file.value)
  try { preview.value = (await axios.post('/kol/affiliatewp/reconciliation/preview', data)).data.batch }
  catch (e: any) { error.value = e.response?.data?.message ?? '预览失败' }
  finally { busy.value = false }
}
const summary = computed(() => preview.value?.summary ?? {})
const statusLabel = (status: string) => ({ matched_kol_only: '仅匹配 KOL', ready_for_binding: '待人工绑定', already_bound: '已绑定', no_affiliate: '无 Affiliate', unresolved: '未解析', conflict: '冲突' }[status] ?? status)
</script>
<template>
  <Head title="真实数据核对 · BYL ERP" />
  <AuthenticatedLayout title="真实数据核对" :breadcrumbs="['BYL ERP', '网红', 'AffiliateWP', '真实数据核对']">
    <section class="erp-form-page">
      <div class="erp-form-content">
        <div class="erp-form-header-row"><div><h1 class="erp-form-title">真实数据核对</h1><p class="erp-form-subtitle">只生成可审阅的确定性匹配预览，不创建或修改 KOL、Affiliate、Referral 或 Provider 数据。</p></div><div class="erp-form-header-actions page-header-actions"><a class="erp-btn erp-btn-secondary" href="/kol/affiliatewp">返回 AffiliateWP</a><a v-if="props.canOperate" class="erp-btn erp-btn-secondary" href="/kol/import-preview">KOL 导入预览</a><button class="erp-btn erp-btn-primary" type="button" :disabled="busy || !file" @click="upload">{{ busy ? '处理中…' : '预览文件' }}</button></div></div>
        <div class="erp-form-card"><label class="import-drop-zone"><input class="sr-only" type="file" accept=".csv,.xlsx" @change="choose"><strong>选择 CSV / XLSX 文件</strong><small>{{ file?.name ?? '未选择文件' }} · 最大 64 MiB · 最多 5,000 行</small></label><p v-if="error" class="erp-error">{{ error }}</p></div>
        <div v-if="preview" class="erp-form-card"><h2>预览 #{{ preview.id }} · {{ preview.original_filename }}</h2><p>SHA-256: {{ preview.checksum }} · 总行数 {{ preview.total_rows }} · 不同身份 {{ preview.distinct_source_identities }}</p><div class="import-summary-grid"><div v-for="status in statuses" :key="status"><strong>{{ summary[status] ?? 0 }}</strong><small>{{ statusLabel(status) }}</small></div></div><div class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th>来源行</th><th>精确身份证据</th><th>ERP KOL 候选</th><th>Affiliate 候选</th><th>状态</th><th>消息</th></tr></thead><tbody><tr v-for="row in preview.rows" :key="row.source_row_number"><td>{{ row.source_row_number }}</td><td><code>{{ JSON.stringify(row.identity) }}</code><small>{{ JSON.stringify(row.evidence) }}</small></td><td>{{ row.kol ? `${row.kol.display_name} (#${row.kol.id})` : '—' }}</td><td>{{ row.affiliate_account_id ?? '—' }}</td><td>{{ statusLabel(row.status) }}</td><td>{{ row.message }}</td></tr></tbody></table></div></div>
        <div v-for="batch in props.batches" :key="batch.id" class="erp-form-card"><strong>Batch #{{ batch.id }} · {{ batch.original_filename }}</strong><span> · {{ batch.total_rows }} 行 · {{ batch.created_at ? new Date(batch.created_at).toLocaleString() : '—' }}</span></div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
