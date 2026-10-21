<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Toc = { id:string; label:string }
type Guide = { slug:string; content_type:string; title:string; summary:string|null; video_url:string|null; status:string; published_at:string|null; updated_at:string|null }
defineProps<{ guide:Guide; html:string; toc:Toc[]; canManage:boolean }>()
</script>

<template>
  <Head :title="`${guide.title} · BYL ERP`" />
  <AuthenticatedLayout :title="guide.title" :breadcrumbs="[{label:'BYL ERP',href:'/dashboard'},{label:'指南',href:'/guides'},guide.title]">
    <section class="mvp-page guide-reader-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">{{ guide.content_type.toUpperCase() }}</p><h2>{{ guide.title }}</h2><p>{{ guide.summary || 'ERP 内部知识内容' }}</p></div>
        <Link v-if="canManage" class="mvp-button" :href="`/guides/${guide.slug}/edit`">编辑</Link>
      </div>
      <div class="guide-reader-layout">
        <aside v-if="toc.length" class="mvp-card guide-toc"><strong>本页目录</strong><a v-for="item in toc" :key="item.id" :href="`#${item.id}`">{{ item.label }}</a></aside>
        <article class="mvp-card guide-content">
          <a v-if="guide.video_url" class="mvp-button guide-video" :href="guide.video_url" target="_blank" rel="noopener noreferrer">打开视频</a>
          <div class="guide-markdown" v-html="html"></div>
        </article>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.guide-reader-layout{display:grid;grid-template-columns:220px minmax(0,1fr);gap:18px;align-items:start}.guide-toc{position:sticky;top:84px;display:flex;flex-direction:column;gap:8px}.guide-toc a{text-decoration:none;font-size:13px}.guide-content{min-width:0}.guide-video{display:inline-flex;margin-bottom:16px}.guide-markdown:deep(h2){scroll-margin-top:100px;margin-top:28px}.guide-markdown:deep(pre){overflow:auto;padding:12px;border-radius:8px;background:#f8fafc}.guide-markdown:deep(table){width:100%;border-collapse:collapse}.guide-markdown:deep(th),.guide-markdown:deep(td){padding:8px;border:1px solid #e2e8f0}@media(max-width:850px){.guide-reader-layout{grid-template-columns:1fr}.guide-toc{position:static}}
</style>
