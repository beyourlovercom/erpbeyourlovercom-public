<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Company = { id: number; name: string; legal_name: string | null; website_url: string | null; website_domain_normalized: string | null; country_code: string | null; registration_number: string | null; industry: string | null; owner: { id: number; name: string } | null; status: string; archived_at: string | null; created_at: string | null; updated_at: string | null }
const props = defineProps<{ company: Company; canOperate: boolean; audit: { action: string; actor_id: number | null; created_at: string }[] }>()
const archive = () => { if (window.confirm(`确认归档公司「${props.company.name}」？`)) router.post(`/customers/companies/${props.company.id}/archive`) }
const restore = () => { if (window.confirm(`确认取消归档公司「${props.company.name}」？`)) router.post(`/customers/companies/${props.company.id}/restore`) }
</script>
<template>
  <Head :title="`${company.name} · BYL ERP`" />
  <AuthenticatedLayout title="公司详情" :breadcrumbs="['BYL ERP', '客户', '公司', company.name]">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">公司详情</p><h2>{{ company.name }}</h2><p><span class="mvp-status">{{ company.archived_at ? '已归档' : company.status }}</span></p></div>
        <div class="mvp-form-actions page-header-actions">
          <a class="mvp-button" href="/customers/companies">返回列表</a>
          <Link v-if="canOperate && !company.archived_at" class="mvp-button" :href="`/customers/companies/${company.id}/edit`">编辑</Link>
          <button v-if="canOperate && !company.archived_at" class="mvp-button" type="button" @click="archive">归档</button>
          <button v-if="canOperate && company.archived_at" class="mvp-button" type="button" @click="restore">取消归档</button>
        </div>
      </div>
      <div class="mvp-detail-grid">
        <article class="mvp-card">
          <h3>公司信息</h3>
          <ul class="mvp-list">
            <li><span>法定名称</span><strong>{{ company.legal_name ?? '—' }}</strong></li>
            <li><span>网址</span><strong>{{ company.website_url ?? '—' }}</strong></li>
            <li><span>网址域名</span><strong>{{ company.website_domain_normalized ?? '—' }}</strong></li>
            <li><span>国家代码</span><strong>{{ company.country_code ?? '—' }}</strong></li>
            <li><span>注册号</span><strong>{{ company.registration_number ?? '—' }}</strong></li>
            <li><span>行业</span><strong>{{ company.industry ?? '—' }}</strong></li>
            <li><span>负责人</span><strong>{{ company.owner?.name ?? '—' }}</strong></li>
            <li><span>创建时间</span><strong>{{ company.created_at ?? '—' }}</strong></li>
            <li><span>更新时间</span><strong>{{ company.updated_at ?? '—' }}</strong></li>
            <li><span>归档时间</span><strong>{{ company.archived_at ?? '未归档' }}</strong></li>
          </ul>
        </article>
        <article class="mvp-card">
          <h3>操作审计</h3>
          <ul class="mvp-list"><li v-for="event in audit" :key="event.created_at + event.action"><span>{{ event.action }}</span><small>{{ event.created_at }} · actor {{ event.actor_id ?? 'system' }}</small></li></ul>
        </article>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
