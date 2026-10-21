<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import SkuCombobox from '../../Components/Products/SkuCombobox.vue'

type Alias = {
  id: number
  alias_sku: string
  match_key: string
  source_type: string
  product: { id: number; sku: string | null; name: string } | null
  created_at: string | null
}
type ReconciliationItem = {
  id: number
  raw_sku: string
  match_key: string
  status: string
  occurrence_count: number
  source_counts: Record<string, number> | null
  last_seen_at: string | null
  resolved_product: { id: number; sku: string | null; name: string } | null
}
type Paginated<T> = {
  data: T[]
  current_page: number
  last_page: number
  total: number
  links: { url: string | null; label: string; active: boolean }[]
}

const props = defineProps<{
  aliases: Paginated<Alias>
  reconciliations: Paginated<ReconciliationItem>
  aliasFilters: { search?: string }
  reconcileFilters: { status?: string; search?: string }
  canOperate: boolean
}>()

const activeTab = ref<'aliases' | 'reconciliations'>('aliases')

// Alias creation form
const showAliasForm = ref(false)
const aliasForm = useForm({ product_id: null as number | null, alias_sku: '', notes: '' })
// aliasSku: the external alias SKU the operator types — must NOT be overwritten by combobox selection.
const aliasSku = ref('')
// aliasProductSearch: independent search input used only by SkuCombobox to find the target product.
const aliasProductSearch = ref('')
const aliasProductId = ref<number | null>(null)

const submitAlias = () => {
  aliasForm.product_id = aliasProductId.value
  aliasForm.alias_sku = aliasSku.value
  aliasForm.post('/products/sku-aliases/aliases', {
    onSuccess: () => {
      showAliasForm.value = false
      aliasSku.value = ''
      aliasProductSearch.value = ''
      aliasProductId.value = null
      aliasForm.reset()
    },
  })
}

const deleteAliasConfirmId = ref<number | null>(null)
const doDeleteAlias = () => {
  if (deleteAliasConfirmId.value === null) return
  router.delete(`/products/sku-aliases/aliases/${deleteAliasConfirmId.value}`, {
    onFinish: () => { deleteAliasConfirmId.value = null },
  })
}

// Reconciliation resolve form
const resolveItemId = ref<number | null>(null)
const resolveItemSku = ref('')
const resolveForm = useForm({ product_id: null as number | null, alias_sku: '', note: '' })
const resolveProductId = ref<number | null>(null)
// resolveAliasSku: the alias display value the operator wishes to register — preserved independently.
const resolveAliasSku = ref('')
// resolveProductSearch: independent search input for SkuCombobox — must NOT overwrite resolveAliasSku.
const resolveProductSearch = ref('')

const openResolve = (item: ReconciliationItem) => {
  resolveItemId.value = item.id
  resolveItemSku.value = item.raw_sku
  resolveAliasSku.value = item.raw_sku
  resolveProductSearch.value = ''
  resolveProductId.value = null
}

const submitResolve = () => {
  resolveForm.product_id = resolveProductId.value
  resolveForm.alias_sku = resolveAliasSku.value
  resolveForm.post(`/products/sku-aliases/reconciliations/${resolveItemId.value}/resolve`, {
    onSuccess: () => {
      resolveItemId.value = null
      resolveProductSearch.value = ''
      resolveForm.reset()
    },
  })
}

const ignoreForm = useForm({ note: '' })
const ignoreItemId = ref<number | null>(null)

const doIgnore = (id: number) => {
  ignoreItemId.value = id
  ignoreForm.post(`/products/sku-aliases/reconciliations/${id}/ignore`, {
    onFinish: () => { ignoreItemId.value = null },
  })
}

const doReopen = (id: number) => {
  router.post(`/products/sku-aliases/reconciliations/${id}/reopen`)
}

// Filters
const aliasSearch = ref(props.aliasFilters.search ?? '')
const reconcileSearch = ref(props.reconcileFilters.search ?? '')
const reconcileStatus = ref(props.reconcileFilters.status ?? 'open')

const applyAliasSearch = () => {
  router.get('/products/sku-aliases', { search: aliasSearch.value }, { preserveState: true, replace: true })
}

const applyReconcileFilter = () => {
  router.get('/products/sku-aliases', { status: reconcileStatus.value, search: reconcileSearch.value }, { preserveState: true, replace: true })
}

const breadcrumbs = [
  { label: 'BYL ERP', href: '/dashboard' },
  { label: '产品', href: '/products' },
  'SKU 别名 & 待解析',
]

const sourceLabel = (s: Record<string, number> | null) => {
  if (!s) return '—'
  return Object.entries(s).map(([k, v]) => `${k}: ${v}`).join(', ')
}
</script>

<template>
  <Head title="SKU 别名 & 待解析 · BYL ERP" />
  <AuthenticatedLayout title="SKU 别名 & 待解析" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">产品主数据</p>
          <h2>SKU 别名 &amp; 待解析</h2>
        </div>
        <div v-if="canOperate" class="mvp-page-actions page-header-actions">
          <button class="mvp-button mvp-button-primary" @click="showAliasForm = true">新增别名</button>
        </div>
      </div>

      <!-- Tabs -->
      <div class="mvp-tabs">
        <button :class="['mvp-tab', activeTab === 'aliases' && 'mvp-tab-active']" @click="activeTab = 'aliases'">
          SKU 别名 ({{ aliases.total }})
        </button>
        <button :class="['mvp-tab', activeTab === 'reconciliations' && 'mvp-tab-active']" @click="activeTab = 'reconciliations'">
          待解析 SKU ({{ reconciliations.total }})
        </button>
      </div>

      <!-- Alias create form overlay -->
      <div v-if="showAliasForm && canOperate" class="mvp-confirm-overlay">
        <div class="mvp-confirm-dialog" style="min-width:380px">
          <h3>新增 SKU 别名</h3>
          <form @submit.prevent="submitAlias">
            <div class="mvp-field">
              <label>外部 SKU（别名）</label>
              <input v-model="aliasSku" type="text" class="mvp-input" placeholder="例：VM467A DARK GREEN" required />
            </div>
            <div class="mvp-field">
              <label>搜索规范产品（按 SKU 或名称）</label>
              <SkuCombobox v-model="aliasProductSearch" :productId="aliasProductId" @update:productId="aliasProductId = $event" />
              <p v-if="aliasProductId" style="font-size:0.8em;color:#444;margin-top:2px">已选产品 ID: {{ aliasProductId }}</p>
            </div>
            <div class="mvp-field">
              <label>备注（可选）</label>
              <input v-model="aliasForm.notes" type="text" class="mvp-input" />
            </div>
            <p v-if="aliasForm.errors.alias_sku" class="mvp-error">{{ aliasForm.errors.alias_sku }}</p>
            <div class="mvp-confirm-actions">
              <button type="submit" class="mvp-button mvp-button-primary" :disabled="aliasForm.processing">创建别名</button>
              <button type="button" class="mvp-button mvp-button-secondary" @click="showAliasForm = false">取消</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Delete alias confirm -->
      <div v-if="deleteAliasConfirmId !== null" class="mvp-confirm-overlay">
        <div class="mvp-confirm-dialog">
          <p>确认删除此 SKU 别名？删除后该别名 SKU 将不再解析到对应产品。</p>
          <div class="mvp-confirm-actions">
            <button class="mvp-button mvp-button-danger" @click="doDeleteAlias">确认删除</button>
            <button class="mvp-button mvp-button-secondary" @click="deleteAliasConfirmId = null">取消</button>
          </div>
        </div>
      </div>

      <!-- Resolve reconciliation overlay -->
      <div v-if="resolveItemId !== null" class="mvp-confirm-overlay">
        <div class="mvp-confirm-dialog" style="min-width:420px">
          <h3>解析 SKU：{{ resolveItemSku }}</h3>
          <p style="font-size:0.85em;color:#666">选择对应的规范产品，系统将自动创建 SKU 别名并标记为已解析。</p>
          <form @submit.prevent="submitResolve">
            <div class="mvp-field">
              <label>别名 SKU（可修改展示值）</label>
              <input v-model="resolveAliasSku" type="text" class="mvp-input" required />
            </div>
            <div class="mvp-field">
              <label>搜索规范产品（按 SKU 或名称）</label>
              <SkuCombobox v-model="resolveProductSearch" :productId="resolveProductId" @update:productId="resolveProductId = $event" />
              <p v-if="resolveProductId" style="font-size:0.8em;color:#444;margin-top:2px">已选产品 ID: {{ resolveProductId }}</p>
            </div>
            <div class="mvp-field">
              <label>备注（可选）</label>
              <input v-model="resolveForm.note" type="text" class="mvp-input" />
            </div>
            <p v-if="resolveForm.errors.alias_sku" class="mvp-error">{{ resolveForm.errors.alias_sku }}</p>
            <div class="mvp-confirm-actions">
              <button type="submit" class="mvp-button mvp-button-primary" :disabled="resolveForm.processing">确认解析</button>
              <button type="button" class="mvp-button mvp-button-secondary" @click="resolveItemId = null">取消</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Tab: Aliases -->
      <template v-if="activeTab === 'aliases'">
        <form class="mvp-filter-bar" @submit.prevent="applyAliasSearch">
          <input v-model="aliasSearch" type="search" placeholder="搜索别名 SKU 或产品…" class="mvp-search-input" />
          <button class="mvp-button" type="submit">搜索</button>
        </form>

        <div class="mvp-table-wrapper">
          <table class="mvp-table">
            <thead>
              <tr>
                <th>别名 SKU</th>
                <th>规范产品 SKU</th>
                <th>产品名称</th>
                <th>来源</th>
                <th>创建日期</th>
                <th v-if="canOperate">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in aliases.data" :key="a.id">
                <td>{{ a.alias_sku }}</td>
                <td>{{ a.product?.sku ?? '—' }}</td>
                <td>{{ a.product?.name ?? '—' }}</td>
                <td>{{ a.source_type }}</td>
                <td>{{ a.created_at ?? '—' }}</td>
                <td v-if="canOperate" class="mvp-actions-cell">
                  <button class="mvp-link mvp-link-danger" @click="deleteAliasConfirmId = a.id">删除</button>
                </td>
              </tr>
              <tr v-if="aliases.data.length === 0">
                <td :colspan="canOperate ? 6 : 5" class="mvp-empty">暂无 SKU 别名</td>
              </tr>
            </tbody>
          </table>
        </div>

        <nav v-if="aliases.last_page > 1" class="mvp-pagination">
          <template v-for="link in aliases.links" :key="link.label">
            <a v-if="link.url" :href="link.url" :class="{ 'mvp-page-active': link.active }" v-html="link.label" />
            <span v-else v-html="link.label" />
          </template>
        </nav>
      </template>

      <!-- Tab: Reconciliations -->
      <template v-if="activeTab === 'reconciliations'">
        <form class="mvp-filter-bar" @submit.prevent="applyReconcileFilter">
          <select v-model="reconcileStatus" class="mvp-select">
            <option value="open">待解析</option>
            <option value="ignored">已忽略</option>
            <option value="resolved">已解析</option>
          </select>
          <input v-model="reconcileSearch" type="search" placeholder="搜索 SKU…" class="mvp-search-input" />
          <button class="mvp-button" type="submit">筛选</button>
        </form>

        <div class="mvp-table-wrapper">
          <table class="mvp-table">
            <thead>
              <tr>
                <th>外部 SKU</th>
                <th>状态</th>
                <th>出现次数</th>
                <th>来源分布</th>
                <th>最近出现</th>
                <th>已解析产品</th>
                <th v-if="canOperate">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in reconciliations.data" :key="r.id">
                <td>{{ r.raw_sku }}</td>
                <td>
                  <span :class="['mvp-badge', r.status === 'open' ? 'mvp-badge-warning' : r.status === 'resolved' ? 'mvp-badge-success' : 'mvp-badge-neutral']">
                    {{ r.status === 'open' ? '待解析' : r.status === 'resolved' ? '已解析' : '已忽略' }}
                  </span>
                </td>
                <td>{{ r.occurrence_count }}</td>
                <td>{{ sourceLabel(r.source_counts) }}</td>
                <td>{{ r.last_seen_at ?? '—' }}</td>
                <td>{{ r.resolved_product ? `${r.resolved_product.sku} · ${r.resolved_product.name}` : '—' }}</td>
                <td v-if="canOperate" class="mvp-actions-cell">
                  <template v-if="r.status === 'open'">
                    <button class="mvp-link" @click="openResolve(r)">解析</button>
                    <button class="mvp-link mvp-link-secondary" @click="doIgnore(r.id)">忽略</button>
                  </template>
                  <template v-else-if="r.status === 'ignored'">
                    <button class="mvp-link" @click="doReopen(r.id)">重新开放</button>
                  </template>
                  <template v-else>
                    <span class="mvp-muted">已完成</span>
                  </template>
                </td>
              </tr>
              <tr v-if="reconciliations.data.length === 0">
                <td :colspan="canOperate ? 7 : 6" class="mvp-empty">暂无待解析 SKU</td>
              </tr>
            </tbody>
          </table>
        </div>

        <nav v-if="reconciliations.last_page > 1" class="mvp-pagination">
          <template v-for="link in reconciliations.links" :key="link.label">
            <a v-if="link.url" :href="link.url" :class="{ 'mvp-page-active': link.active }" v-html="link.label" />
            <span v-else v-html="link.label" />
          </template>
        </nav>
      </template>
    </section>
  </AuthenticatedLayout>
</template>
