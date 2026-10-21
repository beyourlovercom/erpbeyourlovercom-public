<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Person = { name?: string | null; email?: string | null }
type Row = {
    source_row_no: number
    action: 'create' | 'update' | 'unchanged'
    validation_status: 'ok' | 'warning' | 'error'
    validation_message: string | null
    normalized_data: Record<string, string | null>
}
type Batch = {
    id: number
    source_type: 'csv' | 'xlsx'
    original_filename: string
    status: 'preview' | 'confirmed' | 'abandoned'
    preview_total_rows: number
    preview_create_rows: number
    preview_update_rows: number
    preview_unchanged_rows: number
    preview_warning_rows: number
    preview_error_rows: number
    creator?: Person | null
    confirmer?: Person | null
    created_at?: string | null
    confirmed_at?: string | null
    rows?: Row[]
}

const props = defineProps<{ history: Batch[]; columns: string[] }>()
const file = ref<File | null>(null)
const batch = ref<Batch | null>(null)
const busy = ref(false)
const error = ref('')
const isDragging = ref(false)
const selectedFileName = computed(() => file.value?.name ?? '尚未选择文件')

const setFile = (next: File | null) => {
    file.value = next
    batch.value = null
    error.value = ''
}
const choose = (event: Event) => setFile((event.target as HTMLInputElement).files?.[0] ?? null)
const drop = (event: DragEvent) => {
    isDragging.value = false
    setFile(event.dataTransfer?.files?.[0] ?? null)
}
const preview = async () => {
    if (!file.value) { error.value = '请选择 CSV 或 XLSX 文件'; return }
    busy.value = true
    error.value = ''
    const data = new FormData()
    data.append('file', file.value)
    try { batch.value = (await axios.post('/customers/import/preview', data)).data.batch }
    catch (e: any) { error.value = e.response?.data?.message ?? '预览失败' }
    finally { busy.value = false }
}
const confirm = async () => {
    if (!batch.value || batch.value.preview_error_rows > 0 || batch.value.status !== 'preview') return
    busy.value = true
    error.value = ''
    try {
        batch.value = (await axios.post(`/customers/import/${batch.value.id}/confirm`)).data.batch
        router.reload({ only: ['history'] })
    } catch (e: any) { error.value = e.response?.data?.message ?? '确认失败' }
    finally { busy.value = false }
}
const identity = (row: Row) => row.normalized_data.customer_number || row.normalized_data.email || row.normalized_data.phone || '—'
const actionLabel = (action: Row['action']) => ({ create: '新增', update: '更新', unchanged: '无变化' })[action]
const statusLabel = (status: Row['validation_status']) => ({ ok: '可导入', warning: '警告', error: '错误' })[status]
const timeText = (value?: string | null) => value ? new Date(value).toLocaleString('zh-CN') : '—'
</script>

<template>
    <Head title="客户导入 · BYL ERP" />
    <AuthenticatedLayout title="客户导入" :breadcrumbs="['BYL ERP', '客户', '客户导入']">
        <section class="erp-form-page">
            <div class="erp-form-content customer-import-workspace">
                <div class="erp-form-header-row">
                    <div>
                        <h1 class="erp-form-title">客户导入</h1>
                        <p class="erp-form-subtitle">CSV / XLSX 先预览、校验，再一次性确认写入 Customer 主档。模板共 {{ props.columns.length }} 个标准字段。</p>
                    </div>
                    <div class="erp-form-header-actions page-header-actions">
                        <a class="erp-btn erp-btn-secondary" href="/customers/library">返回列表</a>
                        <a class="erp-btn erp-btn-secondary" href="/customers/import/template?format=csv">下载 CSV 模板</a>
                        <a class="erp-btn erp-btn-secondary" href="/customers/import/template?format=xlsx">下载 XLSX 模板</a>
                    </div>
                </div>

                <div class="erp-form-card">
                    <div class="import-step-title"><span>1</span><div><h2>选择文件并预览</h2><p>预览不会修改任何客户、地址或分组数据。</p></div></div>
                    <label class="import-drop-zone" :class="{ 'is-dragging': isDragging }" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="drop">
                        <input class="sr-only" type="file" accept=".csv,.xlsx" @change="choose" />
                        <strong>拖入 CSV / XLSX，或点击选择文件</strong>
                        <small>{{ selectedFileName }} · 单文件最大 10 MiB · 最多 5,000 行</small>
                    </label>
                    <div class="import-step-actions">
                        <button class="erp-btn erp-btn-primary" type="button" :disabled="busy || !file" @click="preview">{{ busy ? '处理中…' : '生成预览' }}</button>
                    </div>
                    <p v-if="error" class="erp-error">{{ error }}</p>
                </div>

                <div v-if="batch" class="erp-form-card">
                    <div class="import-step-title"><span>2</span><div><h2>检查预览并确认</h2><p>{{ batch.original_filename }} · {{ batch.source_type.toUpperCase() }} · Batch #{{ batch.id }}</p></div></div>
                    <div class="import-summary-grid">
                        <div><strong>{{ batch.preview_total_rows }}</strong><small>总行数</small></div>
                        <div><strong>{{ batch.preview_create_rows }}</strong><small>新增</small></div>
                        <div><strong>{{ batch.preview_update_rows }}</strong><small>更新</small></div>
                        <div><strong>{{ batch.preview_unchanged_rows }}</strong><small>无变化</small></div>
                        <div><strong>{{ batch.preview_warning_rows }}</strong><small>警告</small></div>
                        <div><strong>{{ batch.preview_error_rows }}</strong><small>错误</small></div>
                    </div>
                    <div v-if="batch.status === 'confirmed'" class="import-confirmed">该批次已确认写入，不能重复确认。</div>
                    <div v-else-if="batch.preview_error_rows > 0" class="import-blocked">存在错误行，请修正文件后重新生成预览。</div>
                    <div class="import-step-actions">
                        <button class="erp-btn erp-btn-primary" type="button" :disabled="busy || batch.preview_error_rows > 0 || batch.status !== 'preview'" @click="confirm">
                            {{ batch.status === 'confirmed' ? '已确认导入' : '确认导入' }}
                        </button>
                    </div>
                    <div class="import-table-wrap">
                        <table class="import-table">
                            <thead><tr><th>来源行</th><th>身份</th><th>动作</th><th>校验</th><th>信息</th></tr></thead>
                            <tbody>
                                <tr v-for="row in batch.rows ?? []" :key="row.source_row_no">
                                    <td>{{ row.source_row_no }}</td><td>{{ identity(row) }}</td><td>{{ actionLabel(row.action) }}</td><td>{{ statusLabel(row.validation_status) }}</td><td>{{ row.validation_message || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="erp-form-card">
                    <div class="import-step-title"><span>↺</span><div><h2>最近导入批次</h2><p>最近 20 个预览/确认批次，用于追踪导入证据。</p></div></div>
                    <div class="import-table-wrap">
                        <table class="import-table history-table">
                            <thead><tr><th>批次</th><th>文件</th><th>来源</th><th>统计</th><th>状态</th><th>创建人</th><th>确认人</th><th>时间</th></tr></thead>
                            <tbody>
                                <tr v-for="item in props.history" :key="item.id">
                                    <td>#{{ item.id }}</td><td>{{ item.original_filename }}</td><td>{{ item.source_type?.toUpperCase() }}</td>
                                    <td>{{ item.preview_total_rows }} / +{{ item.preview_create_rows }} / ~{{ item.preview_update_rows }} / !{{ item.preview_error_rows }}</td>
                                    <td>{{ item.status }}</td><td>{{ item.creator?.name || item.creator?.email || '—' }}</td><td>{{ item.confirmer?.name || item.confirmer?.email || '—' }}</td><td>{{ timeText(item.confirmed_at || item.created_at) }}</td>
                                </tr>
                                <tr v-if="props.history.length === 0"><td colspan="8" class="empty-cell">暂无导入批次</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
.customer-import-workspace{display:flex;flex-direction:column;gap:14px}.import-step-title{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px}.import-step-title>span{display:grid;place-items:center;width:28px;height:28px;border-radius:999px;background:#eef7f5;color:#247a70;font-weight:800}.import-step-title h2{margin:0;color:#1d2939;font-size:16px}.import-step-title p{margin:3px 0 0;color:#667085;font-size:12px}.import-drop-zone{display:flex;min-height:112px;flex-direction:column;align-items:center;justify-content:center;gap:6px;border:1px dashed #aeb9c7;border-radius:10px;background:#fbfcfd;cursor:pointer;text-align:center}.import-drop-zone.is-dragging{border-color:#2f8f83;background:#f0f9f7}.import-drop-zone strong{color:#344054;font-size:14px}.import-drop-zone small{color:#98a2b3}.import-step-actions{display:flex;justify-content:flex-end;margin-top:12px}.import-summary-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px}.import-summary-grid>div{display:flex;flex-direction:column;gap:2px;padding:10px 12px;border:1px solid #e4e9ef;border-radius:8px;background:#fafbfc}.import-summary-grid strong{font-size:20px;color:#1d2939}.import-summary-grid small{color:#667085}.import-confirmed,.import-blocked{margin-top:12px;padding:10px 12px;border-radius:8px;font-size:12px}.import-confirmed{background:#eef8f5;color:#247a70}.import-blocked{background:#fff4f2;color:#b42318}.import-table-wrap{margin-top:14px;overflow:auto;border:1px solid #e4e9ef;border-radius:8px}.import-table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:12px}.import-table th,.import-table td{padding:9px 11px;border-bottom:1px solid #eef1f4;text-align:left;color:#475467}.import-table th{background:#f8fafc;color:#344054;font-weight:700}.import-table tbody tr:last-child td{border-bottom:0}.empty-cell{text-align:center!important;color:#98a2b3!important}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:900px){.import-summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
</style>
