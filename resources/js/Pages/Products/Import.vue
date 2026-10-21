<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ImportResult = {
  totalRows?: number
  candidates?: number
  newCandidates?: number
  updates?: number
  unchanged?: number
  active?: number
  inactive?: number
  skippedNoSkuVariations?: number
  resolvedVariationMappings?: number
  unresolvedVariationMappings?: number
  noSkuNonVariationReconciliation?: number
  invalid: number
  duplicateSkuConflicts?: number
  identityConflicts?: number
  errors: string[]
  canConfirm?: boolean
}

const props = defineProps<{ result?: ImportResult; previewToken?: string; confirmed?: boolean }>()

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '产品', href: '/products' }, { label: '产品列表', href: '/products/list' }, '导入 CSV']

const fileInput = ref<HTMLInputElement | null>(null)
const submitting = ref(false)
const fieldError = ref('')

const confirm = () => {
  if (!props.previewToken || props.result?.canConfirm !== true) return
  submitting.value = true
  router.post('/products/import', { action: 'confirm', preview_token: props.previewToken } as any, { onFinish: () => { submitting.value = false } })
}

const submit = () => {
  const file = fileInput.value?.files?.[0]
  if (!file) {
    fieldError.value = '请选择 CSV 文件'
    return
  }
  fieldError.value = ''
  submitting.value = true
  const formData = new FormData()
  formData.append('csv_file', file)
  router.post('/products/import', formData as any, {
    forceFormData: true,
    onFinish: () => { submitting.value = false },
  })
}
</script>

<template>
  <Head title="导入产品 CSV · BYL ERP" />
  <AuthenticatedLayout title="导入产品 CSV" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">产品主数据</p><h2>导入 WooCommerce CSV</h2></div>
        <div class="mvp-form-actions page-header-actions">
          <a href="/products/import/template" class="mvp-button mvp-button-secondary">下载 CSV 模板</a>
          <a href="/products/list" class="mvp-button mvp-button-secondary">返回列表</a>
          <button v-if="previewToken && result?.canConfirm && !confirmed" type="button" class="mvp-button mvp-button-primary" :disabled="submitting" @click="confirm">{{ submitting ? '确认中…' : '确认导入' }}</button>
          <button v-else type="submit" form="product-import-form" class="mvp-button mvp-button-primary" :disabled="submitting">{{ submitting ? '预览中…' : '预览 CSV' }}</button>
        </div>
      </div>

      <form id="product-import-form" class="mvp-form" @submit.prevent="submit">
        <div class="mvp-form-field">
          <label class="mvp-label">选择 CSV 文件</label>
          <input ref="fileInput" type="file" accept=".csv,text/csv" class="mvp-input">
          <p class="mvp-muted">模板包含标准 10 列，含 Parent 列；Published：1=公开，0=私密，-1=草稿/未公开。Parent 可填写父产品的精确 SKU 或 id:&lt;Woo 产品 ID&gt;。</p>
          <p v-if="fieldError" class="mvp-error">{{ fieldError }}</p>
        </div>
      </form>

      <div v-if="result" class="mvp-import-result">
        <h3>{{ confirmed ? '导入完成' : '导入预览' }}</h3>
        <ul class="mvp-import-counts">
          <li>源文件行数：{{ result.totalRows ?? 0 }} 条</li>
          <li>候选产品：{{ result.candidates ?? 0 }} 条</li>
          <li>新增：{{ result.newCandidates ?? 0 }} 条</li>
          <li>更新：{{ result.updates ?? 0 }} 条</li>
          <li>未变化：{{ result.unchanged ?? 0 }} 条</li>
          <li>启用：{{ result.active ?? 0 }} 条；停用：{{ result.inactive ?? 0 }} 条</li>
          <li>无 SKU 变体（合计）：{{ result.skippedNoSkuVariations ?? 0 }} 条</li>
          <li>已映射父产品：{{ result.resolvedVariationMappings ?? 0 }} 条</li>
          <li>未解析父产品：{{ result.unresolvedVariationMappings ?? 0 }} 条</li>
          <li>无 SKU 非变体待核对：{{ result.noSkuNonVariationReconciliation ?? 0 }} 条</li>
          <li>无效：{{ result.invalid }} 条</li>
          <li>重复 SKU 冲突：{{ result.duplicateSkuConflicts ?? 0 }} 条；身份冲突：{{ result.identityConflicts ?? 0 }} 条</li>
        </ul>
        <p v-if="!confirmed && result.canConfirm === false" class="mvp-error">预览存在问题，确认导入已阻止。</p>
        <ul v-if="result.errors.length > 0" class="mvp-import-errors">
          <li v-for="(err, i) in result.errors" :key="i" class="mvp-error">{{ err }}</li>
        </ul>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
