<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Company = { id: number; name: string; legal_name: string | null; website_domain_normalized: string | null; country_code: string | null; registration_number: string | null; owner?: { name: string } | null; status: string; archived_at: string | null }
const props = defineProps<{ companies: Company[]; filters: Record<string, string>; owners: { id: number; name: string }[]; canOperate: boolean }>()
const filters = reactive({ search: props.filters.search ?? '', country_code: props.filters.country_code ?? '', owner_id: props.filters.owner_id ?? '', archived: props.filters.archived ?? '' })
const submit = () => router.get('/customers/companies', filters, { preserveState: true, replace: true })
const restore = (company: Company) => { if (window.confirm(`确认取消归档公司「${company.name}」？`)) router.post(`/customers/companies/${company.id}/restore`) }
</script>
<template>
  <Head title="公司 · BYL ERP" />
  <AuthenticatedLayout title="公司" :breadcrumbs="['BYL ERP', '客户', '公司']">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">客户主数据</p><h2>公司</h2><p>维护公司主数据档案。</p></div>
        <div class="mvp-form-actions page-header-actions"><Link v-if="canOperate" class="mvp-button mvp-button-primary" href="/customers/companies/create">新建公司</Link></div>
      </div>
      <form class="mvp-toolbar" @submit.prevent="submit">
        <input v-model="filters.search" aria-label="搜索公司" placeholder="搜索名称、网址域名或注册号">
        <input v-model="filters.country_code" aria-label="国家代码" placeholder="国家代码" maxlength="2">
        <select v-model="filters.owner_id" aria-label="负责人"><option value="">全部负责人</option><option v-for="owner in owners" :key="owner.id" :value="owner.id">{{ owner.name }}</option></select>
        <select v-model="filters.archived" aria-label="归档状态"><option value="">仅在用</option><option value="only">仅已归档</option><option value="all">全部</option></select>
        <button class="mvp-button" type="submit">筛选</button>
      </form>
      <div class="mvp-table-wrap">
        <table class="mvp-table">
          <thead><tr><th>名称</th><th>网址域名</th><th>国家</th><th>注册号</th><th>负责人</th><th>状态</th><th></th></tr></thead>
          <tbody>
            <tr v-for="company in companies" :key="company.id">
              <td><Link :href="`/customers/companies/${company.id}`">{{ company.name }}</Link></td>
              <td>{{ company.website_domain_normalized ?? '—' }}</td>
              <td>{{ company.country_code ?? '—' }}</td>
              <td>{{ company.registration_number ?? '—' }}</td>
              <td>{{ company.owner?.name ?? '—' }}</td>
              <td><span class="mvp-status">{{ company.archived_at ? '已归档' : company.status }}</span></td>
              <td><Link :href="`/customers/companies/${company.id}`">查看</Link> <button v-if="canOperate && company.archived_at" class="mvp-button" type="button" @click="restore(company)">取消归档</button></td>
            </tr>
            <tr v-if="companies.length === 0"><td colspan="7" class="mvp-empty">暂无符合条件的公司</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
