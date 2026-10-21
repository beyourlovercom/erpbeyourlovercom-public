<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Guide = { id:number; slug:string; content_type:string; title:string; summary:string|null; status:string; published_at:string|null; updated_at:string|null }
defineProps<{ title:string; contentType:string|null; guides:Guide[]; canManage:boolean }>()
const typeLabel = (type:string) => ({ tutorial:'教程', video:'视频', manual:'手册' }[type] ?? type)
</script>

<template>
  <Head :title="`${title} · BYL ERP`" />
  <AuthenticatedLayout :title="title" :breadcrumbs="['BYL ERP', '指南', title]">
    <section class="mvp-page guide-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">ERP KNOWLEDGE BASE</p><h2>{{ title }}</h2><p>Markdown 教程、操作手册与视频说明的统一知识库。</p></div>
        <Link v-if="canManage" class="mvp-button mvp-button-primary" href="/guides/create">新建教程</Link>
      </div>
      <nav class="guide-tabs" aria-label="指南分类">
        <Link href="/guides">全部教程</Link><Link href="/guides/videos">视频教程</Link><Link href="/guides/manuals">操作手册</Link><Link href="/settings/changelog">版本更新</Link>
      </nav>
      <div class="guide-grid">
        <article v-for="guide in guides" :key="guide.id" class="mvp-card guide-card">
          <div class="guide-card-meta"><span class="mvp-status">{{ typeLabel(guide.content_type) }}</span><span v-if="canManage" class="mvp-status">{{ guide.status === 'published' ? '已发布' : '草稿' }}</span></div>
          <h3><Link :href="`/guides/${guide.slug}`">{{ guide.title }}</Link></h3>
          <p>{{ guide.summary || '暂无摘要' }}</p>
          <div class="guide-card-actions"><Link :href="`/guides/${guide.slug}`">阅读</Link><Link v-if="canManage" :href="`/guides/${guide.slug}/edit`">编辑</Link></div>
        </article>
        <div v-if="!guides.length" class="mvp-empty">当前分类暂无{{ canManage ? '内容' : '已发布内容' }}。</div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.guide-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}.guide-tabs a{padding:8px 12px;border:1px solid var(--border,#dbe2e8);border-radius:8px;text-decoration:none}.guide-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.guide-card h3{margin:10px 0 6px}.guide-card p{margin:0;color:var(--text-muted,#64748b)}.guide-card-meta,.guide-card-actions{display:flex;gap:8px;align-items:center}.guide-card-actions{margin-top:14px}@media(max-width:800px){.guide-grid{grid-template-columns:1fr}}
</style>
