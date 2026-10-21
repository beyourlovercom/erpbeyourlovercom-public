<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Guide = { slug:string; content_type:string; title:string; summary:string|null; markdown_body:string; video_url:string|null; status:string }
const props = defineProps<{ guide:Guide|null }>()
const defaultMarkdown = `# 标题\n\n## 第一步\n\n在这里写教程。`
const form = useForm({ title:props.guide?.title ?? '', slug:props.guide?.slug ?? '', content_type:props.guide?.content_type ?? 'tutorial', summary:props.guide?.summary ?? '', markdown_body:props.guide?.markdown_body ?? defaultMarkdown, video_url:props.guide?.video_url ?? '' })
const submit = () => props.guide ? form.patch(`/guides/${props.guide.slug}`) : form.post('/guides')
const setPublished = (published:boolean) => props.guide && router.post(`/guides/${props.guide.slug}/${published ? 'publish' : 'unpublish'}`)
</script>

<template>
  <Head :title="`${guide ? '编辑教程' : '新建教程'} · BYL ERP`" />
  <AuthenticatedLayout :title="guide ? '编辑教程' : '新建教程'" :breadcrumbs="[{label:'BYL ERP',href:'/dashboard'},{label:'指南',href:'/guides'},guide ? '编辑' : '新建']">
    <form class="erp-form-page" @submit.prevent="submit">
      <div class="erp-form-content">
        <div class="erp-form-header-row"><div><p class="mvp-kicker">MARKDOWN CMS</p><h2 class="erp-form-title">{{ guide ? guide.title : '新建教程' }}</h2></div><div class="mvp-actions"><Link class="erp-btn erp-btn-secondary" href="/guides">返回列表</Link><button class="erp-btn erp-btn-primary" type="submit" :disabled="form.processing">保存</button></div></div>
        <div class="erp-form-card"><h3 class="erp-form-card-title">基本信息</h3><div class="erp-form-grid">
          <label class="erp-form-field"><span class="erp-form-label">标题</span><input v-model="form.title" class="erp-input"><small v-if="form.errors.title" class="erp-field-error">{{ form.errors.title }}</small></label>
          <label class="erp-form-field"><span class="erp-form-label">Slug</span><input v-model="form.slug" class="erp-input" placeholder="留空则按标题生成"></label>
          <label class="erp-form-field"><span class="erp-form-label">类型</span><select v-model="form.content_type" class="erp-input"><option value="tutorial">教程</option><option value="video">视频教程</option><option value="manual">操作手册</option></select></label>
          <label class="erp-form-field"><span class="erp-form-label">视频 URL</span><input v-model="form.video_url" class="erp-input" placeholder="仅视频教程需要"></label>
          <label class="erp-form-field ord-span-2"><span class="erp-form-label">摘要</span><textarea v-model="form.summary" class="erp-input" rows="3"></textarea></label>
        </div></div>
        <div class="erp-form-card"><h3 class="erp-form-card-title">Markdown 正文</h3><p>阅读页只使用 H2（<code>## 标题</code>）生成本页目录。</p><textarea v-model="form.markdown_body" class="erp-input guide-editor" rows="24"></textarea><small v-if="form.errors.markdown_body" class="erp-field-error">{{ form.errors.markdown_body }}</small></div>
        <div v-if="guide" class="erp-form-card"><h3 class="erp-form-card-title">发布状态</h3><p>当前：{{ guide.status === 'published' ? '已发布' : '草稿' }}</p><div class="mvp-actions"><button v-if="guide.status !== 'published'" type="button" class="erp-btn erp-btn-primary" @click="setPublished(true)">发布教程</button><button v-else type="button" class="erp-btn erp-btn-secondary" @click="setPublished(false)">转为草稿</button><Link class="erp-btn erp-btn-secondary" :href="`/guides/${guide.slug}`">预览 / 阅读</Link></div></div>
      </div>
    </form>
  </AuthenticatedLayout>
</template>

<style scoped>.guide-editor{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;line-height:1.6;min-height:520px}.ord-span-2{grid-column:span 2}@media(max-width:800px){.ord-span-2{grid-column:auto}}</style>
