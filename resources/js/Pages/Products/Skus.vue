<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ProductSku = {
  id: number
  sku: string | null
  name: string
  is_active: boolean
  source_type: string
  alias_count: number
}
type Paginated<T> = {
  data: T[]
  current_page: number
  last_page: number
  total: number
  from: number | null
  to: number | null
  links: { url: string | null; label: string; active: boolean }[]
}

const props = defineProps<{ products: Paginated<ProductSku>; filters: { search?: string } }>()
const search = ref(props.filters.search ?? '')

const applySearch = () => {
  router.get('/products/skus', search.value ? { search: search.value } : {}, { preserveState: true, replace: true })
}

const breadcrumbs = [
  { label: 'BYL ERP', href: '/dashboard' },
  { label: '产品', href: '/products' },
  '规范 SKU 注册表',
]
</script>

<template>
  <Head title="SKU 注册表 · BYL ERP" />
  <AuthenticatedLayout title="SKU 注册表" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">产品主数据</p>
          <h2>规范 SKU 注册表</h2>
          <p>共 {{ products.total }} 件产品</p>
        </div>
        <div class="mvp-page-actions page-header-actions">
          <a href="/products/sku-aliases" class="mvp-button mvp-button-secondary">SKU 别名 &amp; 待解析</a>
        </div>
      </div>

      <form class="mvp-filter-bar" @submit.prevent="applySearch">
        <input v-model="search" type="search" placeholder="搜索规范 SKU 或产品名称…" class="mvp-search-input" />
        <button class="mvp-button" type="submit">搜索</button>
      </form>

      <div class="mvp-table-wrapper">
        <table class="mvp-table">
          <thead>
            <tr>
              <th>规范 SKU</th>
              <th>产品名称</th>
              <th>状态</th>
              <th>来源</th>
              <th>别名数量</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in products.data" :key="p.id">
              <td>{{ p.sku ?? '—' }}</td>
              <td>{{ p.name }}</td>
              <td>{{ p.is_active ? '启用' : '停用' }}</td>
              <td>{{ p.source_type === 'manual' ? '手动' : 'Woo' }}</td>
              <td>{{ p.alias_count }}</td>
              <td class="mvp-actions-cell">
                <a :href="`/products/${p.id}/edit`" class="mvp-link">查看产品</a>
              </td>
            </tr>
            <tr v-if="products.data.length === 0">
              <td colspan="6" class="mvp-empty">暂无产品</td>
            </tr>
          </tbody>
        </table>
      </div>

      <nav v-if="products.last_page > 1" class="mvp-pagination">
        <template v-for="link in products.links" :key="link.label">
          <a v-if="link.url" :href="link.url" :class="{ 'mvp-page-active': link.active }" v-html="link.label" />
          <span v-else v-html="link.label" />
        </template>
      </nav>
    </section>
  </AuthenticatedLayout>
</template>
