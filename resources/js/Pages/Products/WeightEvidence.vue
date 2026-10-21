<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import SkuCombobox from '../../Components/Products/SkuCombobox.vue'

type Evidence = {
  id: number
  product_id: number
  product_sku: string | null
  product_name: string | null
  weight_kind: string
  observed_weight_kg: string
  raw_sku: string | null
  source_type: string
  source_reference: string | null
  source_note: string | null
  observed_at: string | null
  status: string
  rejection_note: string | null
  created_at: string | null
  reviewed_at: string | null
  is_current_provenance: boolean
}

type Paginated<T> = {
  data: T[]
  current_page: number
  last_page: number
  total: number
  links: { url: string | null; label: string; active: boolean }[]
}

type Summary = {
  total_products: number
  weight_kg_filled: number
  weight_kg_missing: number
  shipping_weight_kg_filled: number
  shipping_weight_kg_missing: number
  pending_evidence_count: number
}

type PreviewResult = {
  total_rows: number
  valid_rows: number
  unmatched_sku_rows: number
  invalid_weight_rows: number
  duplicate_rows: number
  would_replace_non_null: number
  would_fill_null: number
  evidence_only_rows: number
  errors: string[]
}

const props = defineProps<{
  evidences?: Paginated<Evidence>
  summary: Summary
  filters?: Record<string, string>
  canOperate: boolean
  sourceTypes: string[]
  weightKinds: string[]
  importMode?: boolean
  preview?: PreviewResult
  previewToken?: string
  importConfirmed?: boolean
  importCreatedCount?: number
  importError?: string
}>()

const breadcrumbs = [
  { label: 'BYL ERP', href: '/dashboard' },
  { label: '产品', href: '/products' },
  '重量证据',
]

const activeTab = ref<'list' | 'add' | 'import'>(props.importMode ? 'import' : 'list')

// Filters
const filterStatus = ref(props.filters?.status ?? '')
const filterSourceType = ref(props.filters?.source_type ?? '')
const filterWeightKind = ref(props.filters?.weight_kind ?? '')
const filterSearch = ref(props.filters?.search ?? '')

function applyFilters() {
  router.get('/products/weight-evidence', {
    status: filterStatus.value || undefined,
    source_type: filterSourceType.value || undefined,
    weight_kind: filterWeightKind.value || undefined,
    search: filterSearch.value || undefined,
  }, { preserveScroll: true })
}

// Add evidence form
const addProductId = ref<number | null>(null)
const addProductSearch = ref('')

const addForm = useForm({
  product_id: null as number | null,
  weight_kind: 'product_weight',
  observed_weight_kg: '',
  source_type: 'manual_measurement',
  source_reference: '',
  source_note: '',
  observed_at: '',
  raw_sku: '',
})

// Sync form fields when combobox selection changes
watch(addProductId, (id) => { addForm.product_id = id })
watch(addProductSearch, (sku) => { addForm.raw_sku = sku })

function submitAdd() {
  addForm.post('/products/weight-evidence', {
    preserveScroll: true,
    onSuccess: () => {
      addForm.reset()
      addProductId.value = null
      addProductSearch.value = ''
      activeTab.value = 'list'
    },
  })
}

// Adopt/reject
const adoptConfirmId = ref<number | null>(null)
const rejectId = ref<number | null>(null)
const rejectNote = ref('')

function adopt(id: number, force = false) {
  router.post(`/products/weight-evidence/${id}/adopt`, { confirmed: force }, {
    preserveScroll: true,
    onSuccess: (page) => {
      if ((page.props as any).flash?.confirm_required) {
        adoptConfirmId.value = id
      } else {
        adoptConfirmId.value = null
      }
    },
  })
}

function confirmAdopt() {
  if (adoptConfirmId.value === null) return
  adopt(adoptConfirmId.value, true)
  adoptConfirmId.value = null
}

function startReject(id: number) {
  rejectId.value = id
  rejectNote.value = ''
}

function submitReject() {
  if (rejectId.value === null) return
  router.post(`/products/weight-evidence/${rejectId.value}/reject`, { rejection_note: rejectNote.value }, {
    preserveScroll: true,
    onSuccess: () => { rejectId.value = null },
  })
}

// CSV import
const csvFile = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

function onFileChange(e: Event) {
  const t = e.target as HTMLInputElement
  csvFile.value = t.files?.[0] ?? null
}

function submitPreview() {
  if (!csvFile.value) return
  const form = new FormData()
  form.append('csv_file', csvFile.value)
  router.post('/products/weight-evidence/import/preview', form, { forceFormData: true })
}

function submitConfirm() {
  router.post('/products/weight-evidence/import/confirm', { preview_token: props.previewToken })
}

function statusLabel(s: string) {
  return s === 'pending' ? '待审' : s === 'adopted' ? '已采纳' : s === 'rejected' ? '已拒绝' : s
}

function kindLabel(k: string) {
  return k === 'product_weight' ? '产品重量' : '运输参考重量'
}

const pendingEvidences = computed(() => props.evidences?.data.filter(e => e.status === 'pending') ?? [])
</script>

<template>
  <Head title="重量证据" />
  <AuthenticatedLayout title="重量证据" :breadcrumbs="breadcrumbs">
    <template #header>
      <h1 class="text-xl font-semibold">重量证据工作区</h1>
    </template>

    <!-- Coverage summary -->
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
      <div class="bg-white rounded border p-4">
        <div class="text-sm text-gray-500">产品总数</div>
        <div class="text-2xl font-bold">{{ summary.total_products }}</div>
      </div>
      <div class="bg-white rounded border p-4">
        <div class="text-sm text-gray-500">weight_kg 已填写</div>
        <div class="text-2xl font-bold text-green-700">{{ summary.weight_kg_filled }}</div>
        <div class="text-xs text-gray-400">缺少: {{ summary.weight_kg_missing }}</div>
      </div>
      <div class="bg-white rounded border p-4">
        <div class="text-sm text-gray-500">shipping_weight_kg 已填写</div>
        <div class="text-2xl font-bold text-blue-700">{{ summary.shipping_weight_kg_filled }}</div>
        <div class="text-xs text-gray-400">缺少: {{ summary.shipping_weight_kg_missing }}</div>
      </div>
      <div class="bg-white rounded border p-4">
        <div class="text-sm text-gray-500">待审证据</div>
        <div class="text-2xl font-bold text-yellow-700">{{ summary.pending_evidence_count }}</div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-4 border-b">
      <button
        class="px-4 py-2 text-sm font-medium"
        :class="activeTab === 'list' ? 'border-b-2 border-indigo-600 text-indigo-700' : 'text-gray-600'"
        @click="activeTab = 'list'"
      >证据列表</button>
      <button
        v-if="canOperate"
        class="px-4 py-2 text-sm font-medium"
        :class="activeTab === 'add' ? 'border-b-2 border-indigo-600 text-indigo-700' : 'text-gray-600'"
        @click="activeTab = 'add'"
      >添加证据</button>
      <button
        v-if="canOperate"
        class="px-4 py-2 text-sm font-medium"
        :class="activeTab === 'import' ? 'border-b-2 border-indigo-600 text-indigo-700' : 'text-gray-600'"
        @click="activeTab = 'import'"
      >CSV 导入</button>
    </div>

    <!-- List tab -->
    <div v-if="activeTab === 'list'">
      <!-- Filters -->
      <div class="flex flex-wrap gap-2 mb-4">
        <input v-model="filterSearch" type="text" placeholder="SKU / 产品名" class="border rounded px-2 py-1 text-sm" @keyup.enter="applyFilters" />
        <select v-model="filterStatus" class="border rounded px-2 py-1 text-sm" @change="applyFilters">
          <option value="">全部状态</option>
          <option value="pending">待审</option>
          <option value="adopted">已采纳</option>
          <option value="rejected">已拒绝</option>
        </select>
        <select v-model="filterWeightKind" class="border rounded px-2 py-1 text-sm" @change="applyFilters">
          <option value="">全部类型</option>
          <option value="product_weight">产品重量</option>
          <option value="shipping_reference_weight">运输参考重量</option>
        </select>
        <select v-model="filterSourceType" class="border rounded px-2 py-1 text-sm" @change="applyFilters">
          <option value="">全部来源</option>
          <option v-for="st in sourceTypes" :key="st" :value="st">{{ st }}</option>
        </select>
        <button class="px-3 py-1 bg-indigo-600 text-white text-sm rounded" @click="applyFilters">筛选</button>
      </div>

      <div v-if="!evidences || evidences.data.length === 0" class="text-gray-500 text-sm py-8 text-center">暂无证据</div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm border">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 text-left">ID</th>
              <th class="px-3 py-2 text-left">产品 SKU</th>
              <th class="px-3 py-2 text-left">原始 SKU</th>
              <th class="px-3 py-2 text-left">类型</th>
              <th class="px-3 py-2 text-left">来源</th>
              <th class="px-3 py-2 text-left">证据重量 (kg)</th>
              <th class="px-3 py-2 text-left">观测时间</th>
              <th class="px-3 py-2 text-left">状态</th>
              <th class="px-3 py-2 text-left">当前溯源</th>
              <th v-if="canOperate" class="px-3 py-2 text-left">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in evidences.data" :key="e.id" class="border-t">
              <td class="px-3 py-2 text-gray-500">{{ e.id }}</td>
              <td class="px-3 py-2 font-mono">{{ e.product_sku ?? '—' }}</td>
              <td class="px-3 py-2 text-gray-500 font-mono">{{ e.raw_sku ?? '—' }}</td>
              <td class="px-3 py-2">{{ kindLabel(e.weight_kind) }}</td>
              <td class="px-3 py-2">{{ e.source_type }}</td>
              <td class="px-3 py-2 font-mono">{{ e.observed_weight_kg }}</td>
              <td class="px-3 py-2 text-gray-500">{{ e.observed_at ? new Date(e.observed_at).toLocaleDateString() : '—' }}</td>
              <td class="px-3 py-2">
                <span
                  class="px-2 py-0.5 rounded text-xs font-medium"
                  :class="{
                    'bg-yellow-100 text-yellow-800': e.status === 'pending',
                    'bg-green-100 text-green-800': e.status === 'adopted',
                    'bg-red-100 text-red-800': e.status === 'rejected',
                  }"
                >{{ statusLabel(e.status) }}</span>
              </td>
              <td class="px-3 py-2">
                <span v-if="e.is_current_provenance" class="text-xs text-indigo-700 font-medium">✓ 当前溯源</span>
                <span v-else class="text-xs text-gray-400">—</span>
              </td>
              <td v-if="canOperate" class="px-3 py-2 space-x-1">
                <template v-if="e.status === 'pending'">
                  <button
                    class="text-xs px-2 py-1 bg-green-600 text-white rounded"
                    @click="adopt(e.id)"
                  >采纳</button>
                  <button
                    class="text-xs px-2 py-1 bg-red-600 text-white rounded"
                    @click="startReject(e.id)"
                  >拒绝</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <div class="flex gap-1 mt-3">
          <template v-for="link in evidences.links" :key="link.label">
            <a
              v-if="link.url"
              :href="link.url"
              class="px-2 py-1 text-sm border rounded"
              :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-600'"
              v-html="link.label"
            />
            <span v-else class="px-2 py-1 text-sm text-gray-300" v-html="link.label" />
          </template>
        </div>
      </div>

      <!-- Adopt confirmation dialog -->
      <div v-if="adoptConfirmId !== null" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-md w-full shadow-xl">
          <h3 class="text-lg font-semibold mb-2">确认覆盖现有重量？</h3>
          <p class="text-sm text-gray-600 mb-4">该产品已有重量值。采纳此证据将覆盖现有值，并记录审计日志。</p>
          <div class="flex gap-2 justify-end">
            <button class="px-4 py-2 text-sm border rounded" @click="adoptConfirmId = null">取消</button>
            <button class="px-4 py-2 text-sm bg-green-600 text-white rounded" @click="confirmAdopt">确认采纳</button>
          </div>
        </div>
      </div>

      <!-- Reject dialog -->
      <div v-if="rejectId !== null" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-md w-full shadow-xl">
          <h3 class="text-lg font-semibold mb-2">拒绝证据</h3>
          <textarea v-model="rejectNote" class="w-full border rounded p-2 text-sm mb-4" rows="3" placeholder="拒绝原因（可选）" />
          <div class="flex gap-2 justify-end">
            <button class="px-4 py-2 text-sm border rounded" @click="rejectId = null">取消</button>
            <button class="px-4 py-2 text-sm bg-red-600 text-white rounded" @click="submitReject">确认拒绝</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Add evidence tab -->
    <div v-if="activeTab === 'add' && canOperate">
      <form class="max-w-lg space-y-4" @submit.prevent="submitAdd">
        <div>
          <label class="block text-sm font-medium mb-1">产品 SKU</label>
          <SkuCombobox v-model="addProductSearch" v-model:productId="addProductId" />
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">重量类型</label>
          <select v-model="addForm.weight_kind" class="w-full border rounded px-3 py-2 text-sm">
            <option value="product_weight">产品重量 (weight_kg)</option>
            <option value="shipping_reference_weight">运输参考重量 (shipping_weight_kg)</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">证据重量 (kg)</label>
          <input v-model="addForm.observed_weight_kg" type="number" step="0.0001" min="0.0001" class="w-full border rounded px-3 py-2 text-sm" required />
          <p v-if="addForm.errors.observed_weight_kg" class="text-red-600 text-xs mt-1">{{ addForm.errors.observed_weight_kg }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">来源类型</label>
          <select v-model="addForm.source_type" class="w-full border rounded px-3 py-2 text-sm">
            <option v-for="st in sourceTypes" :key="st" :value="st">{{ st }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">来源参考（可选）</label>
          <input v-model="addForm.source_reference" type="text" class="w-full border rounded px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">备注（可选）</label>
          <textarea v-model="addForm.source_note" class="w-full border rounded px-3 py-2 text-sm" rows="2" />
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">观测时间（可选）</label>
          <input v-model="addForm.observed_at" type="date" class="w-full border rounded px-3 py-2 text-sm" />
        </div>
        <button type="submit" :disabled="addForm.processing || addProductId === null" class="px-4 py-2 bg-indigo-600 text-white rounded text-sm disabled:opacity-50">
          创建证据
        </button>
        <p v-if="addProductId === null" class="text-xs text-gray-500">请先选择产品</p>
      </form>
    </div>

    <!-- CSV import tab -->
    <div v-if="activeTab === 'import' && canOperate">
      <div v-if="importConfirmed" class="mb-4 p-4 bg-green-50 border border-green-200 rounded text-sm text-green-800">
        已成功创建 {{ importCreatedCount }} 条证据（待审，未自动采纳）。
      </div>
      <div v-if="importError" class="mb-4 p-4 bg-red-50 border border-red-200 rounded text-sm text-red-800">
        {{ importError }}
      </div>

      <p class="text-sm text-gray-600 mb-4">
        CSV 必须包含列：<code class="bg-gray-100 px-1 rounded">SKU, Weight (kg), Weight Kind, Source Type</code>，
        可选列：<code class="bg-gray-100 px-1 rounded">Source Reference, Observed At, Note</code>。
        预览不会修改任何数据；确认只创建证据，不自动采纳。
      </p>

      <form class="space-y-3 mb-6" @submit.prevent="submitPreview">
        <div>
          <input ref="fileInput" type="file" accept=".csv,.txt" class="text-sm" @change="onFileChange" />
        </div>
        <button type="submit" :disabled="!csvFile" class="px-4 py-2 bg-indigo-600 text-white rounded text-sm disabled:opacity-50">
          预览
        </button>
      </form>

      <!-- Preview results -->
      <div v-if="preview">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
          <div class="bg-white border rounded p-3 text-sm">
            <div class="text-gray-500">总行数</div>
            <div class="font-bold">{{ preview.total_rows }}</div>
          </div>
          <div class="bg-white border rounded p-3 text-sm">
            <div class="text-gray-500">有效行</div>
            <div class="font-bold text-green-700">{{ preview.valid_rows }}</div>
          </div>
          <div class="bg-white border rounded p-3 text-sm">
            <div class="text-gray-500">将覆盖现有值</div>
            <div class="font-bold text-orange-600">{{ preview.would_replace_non_null }}</div>
          </div>
          <div class="bg-white border rounded p-3 text-sm">
            <div class="text-gray-500">将填写空值</div>
            <div class="font-bold text-blue-700">{{ preview.would_fill_null }}</div>
          </div>
          <div class="bg-white border rounded p-3 text-sm">
            <div class="text-gray-500">SKU 未找到</div>
            <div class="font-bold text-red-600">{{ preview.unmatched_sku_rows }}</div>
          </div>
          <div class="bg-white border rounded p-3 text-sm">
            <div class="text-gray-500">无效重量</div>
            <div class="font-bold text-red-600">{{ preview.invalid_weight_rows }}</div>
          </div>
          <div class="bg-white border rounded p-3 text-sm">
            <div class="text-gray-500">重复行</div>
            <div class="font-bold text-red-600">{{ preview.duplicate_rows }}</div>
          </div>
          <div class="bg-white border rounded p-3 text-sm">
            <div class="text-gray-500">仅创建证据</div>
            <div class="font-bold">{{ preview.evidence_only_rows }}</div>
          </div>
        </div>

        <div v-if="preview.errors.length > 0" class="mb-4">
          <p class="text-sm font-medium text-red-700 mb-1">错误详情：</p>
          <ul class="text-xs text-red-600 list-disc list-inside space-y-0.5">
            <li v-for="(err, i) in preview.errors" :key="i">{{ err }}</li>
          </ul>
        </div>

        <div v-if="preview.valid_rows > 0 && previewToken">
          <button class="px-4 py-2 bg-green-600 text-white rounded text-sm" @click="submitConfirm">
            确认创建 {{ preview.valid_rows }} 条证据（不自动采纳）
          </button>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
