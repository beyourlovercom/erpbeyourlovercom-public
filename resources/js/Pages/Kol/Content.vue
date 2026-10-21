<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Attachment = { id:number; name:string; mime_type:string|null; url:string; created_at:string|null }
type ContentRow = { id:number; kol_id:number; kol:{id:number;display_name:string}|null; collaboration_id:number|null; collaboration:{id:number;primary_post_url:string|null;cooperation_type:string|null}|null; platform:string; content_type:string; publication_status:'unpublished'|'published'; post_url:string|null; planned_publish_at:string|null; published_at:string|null; evidence_classification:string; attachments:Attachment[]; created_at:string|null }
type CollaborationOption = { id:number; primary_post_url:string|null; cooperation_type:string|null; status:string; planned_publish_at:string|null }
type KolOption = { id:number; display_name:string; collaborations:CollaborationOption[] }
type Paginated<T> = { data:T[]; current_page:number; last_page:number; per_page:number; total:number }

const props = defineProps<{ contents:Paginated<ContentRow>; filters:Record<string,string>; canOperate:boolean }>()
const search = ref(props.filters.search ?? '')
const publicationStatus = ref(props.filters.publication_status ?? '')
const platform = ref(props.filters.platform ?? '')
const evidence = ref(props.filters.evidence ?? '')
const filterPayload = () => ({ search:search.value||undefined, publication_status:publicationStatus.value||undefined, platform:platform.value||undefined, evidence:evidence.value||undefined })
const applyFilters = () => router.get('/kol/content', filterPayload(), { preserveState:true, replace:true })
const goPage = (page:number) => router.get('/kol/content', { ...filterPayload(), page }, { preserveState:true, replace:true })

const drawerOpen = ref(false)
const saving = ref(false)
const syncing = ref(false)
const error = ref('')
const editingId = ref<number|null>(null)
const activeItem = ref<ContentRow|null>(null)
const drawerAttachments = ref<Attachment[]>([])
const evidenceFile = ref<File|null>(null)
const pendingPreviewUrl = ref<string|null>(null)
const fileInput = ref<HTMLInputElement|null>(null)
const kolSearch = ref('')
const kolOptions = ref<KolOption[]>([])
const optionLoading = ref(false)
const emptyForm = () => ({ kol_id:'', collaboration_id:'', platform:'instagram', content_type:'post', publication_status:'unpublished' as 'unpublished'|'published', post_url:'', planned_publish_at:'', published_at:'' })
const form = ref(emptyForm())

const selectedKol = computed(() => kolOptions.value.find(k => String(k.id) === form.value.kol_id) ?? null)
const selectedKolName = computed(() => selectedKol.value?.display_name ?? activeItem.value?.kol?.display_name ?? '—')
const selectedCollaborations = computed(() => selectedKol.value?.collaborations ?? [])
const selectedCollaboration = computed(() => selectedCollaborations.value.find(c => String(c.id) === form.value.collaboration_id) ?? null)
const primaryReferenceUrl = computed(() => selectedCollaboration.value?.primary_post_url ?? '')
const syncSupported = computed(() => form.value.platform !== 'other')
const canSyncPostImage = computed(() => Boolean(editingId.value && form.value.post_url && activeItem.value?.post_url === form.value.post_url && syncSupported.value))

const statusLabel = (v:string) => v === 'published' ? '已发布' : '未发布'
const evidenceLabel = (v:string) => ({none:'无素材',url:'仅 URL',screenshot:'仅截图',url_and_screenshot:'URL + 截图'}[v] ?? v)
const platformLabel = (v:string) => ({instagram:'Instagram',tiktok:'TikTok',youtube:'YouTube',facebook:'Facebook',twitter:'X/Twitter',xiaohongshu:'小红书',weibo:'微博',wechat:'微信',other:'其他'}[v] ?? v)
const typeLabel = (v:string) => ({post:'图文',reel:'Reel',short_video:'短视频',long_video:'长视频',story:'Story',live:'直播',article:'文章',other:'其他'}[v] ?? v)
const dateLabel = (v:string|null) => v ? v.slice(0,10) : '—'
const today = () => {
  const d = new Date()
  const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000)
  return local.toISOString().slice(0,10)
}
const isImageMime = (mime:string|null) => Boolean(mime?.startsWith('image/'))
const thumbnailFor = (item:ContentRow) => item.attachments.find(a => isImageMime(a.mime_type))?.url ?? null

const loadKolOptions = async (kolId?:number) => {
  optionLoading.value = true
  try {
    const params = kolId ? { kol_id:kolId } : { search:kolSearch.value || undefined }
    const response = await axios.get('/kol/content/options', { params })
    kolOptions.value = response.data.kols as KolOption[]
  } finally { optionLoading.value = false }
}
const clearPendingFile = () => {
  if (pendingPreviewUrl.value) URL.revokeObjectURL(pendingPreviewUrl.value)
  pendingPreviewUrl.value = null
  evidenceFile.value = null
  if (fileInput.value) fileInput.value.value = ''
}
const closeDrawer = () => { clearPendingFile(); drawerOpen.value=false; error.value='' }
const openNew = async () => {
  editingId.value=null; activeItem.value=null; form.value=emptyForm(); drawerAttachments.value=[]; clearPendingFile(); error.value=''; kolSearch.value=''; drawerOpen.value=true
  await loadKolOptions()
}
const openEdit = async (item:ContentRow) => {
  editingId.value=item.id; activeItem.value=item
  form.value={ kol_id:String(item.kol_id), collaboration_id:item.collaboration_id ? String(item.collaboration_id) : '', platform:item.platform, content_type:item.content_type, publication_status:item.publication_status, post_url:item.post_url ?? '', planned_publish_at:item.planned_publish_at ?? '', published_at:item.published_at ?? '' }
  drawerAttachments.value=[...item.attachments]; clearPendingFile(); error.value=''; drawerOpen.value=true
  await loadKolOptions(item.kol_id)
}
const changeKol = () => { form.value.collaboration_id='' }
const copyPrimaryUrl = () => {
  if (!primaryReferenceUrl.value) return
  form.value.post_url = primaryReferenceUrl.value
}
const setPublicationStatus = (status:'published'|'unpublished') => {
  form.value.publication_status = status
  if (status === 'published' && !form.value.published_at) form.value.published_at = today()
  if (status === 'unpublished') form.value.published_at = ''
}

const setPendingFile = (file:File) => {
  const allowed = ['image/jpeg','image/png','image/webp','image/gif','application/pdf']
  if (!allowed.includes(file.type)) { error.value='发布素材截图仅支持 JPG / PNG / WebP / GIF / PDF。'; return }
  if (file.size > 10 * 1024 * 1024) { error.value='发布素材截图不能超过 10MB。'; return }
  clearPendingFile(); evidenceFile.value=file; error.value=''
  if (file.type.startsWith('image/')) pendingPreviewUrl.value=URL.createObjectURL(file)
}
const chooseFile = (event:Event) => {
  const file=(event.target as HTMLInputElement).files?.[0]
  if (file) setPendingFile(file)
}
const openFilePicker = () => fileInput.value?.click()
const handlePaste = (event:ClipboardEvent) => {
  const file = Array.from(event.clipboardData?.items ?? []).find(item => item.kind === 'file')?.getAsFile()
  if (!file) return
  event.preventDefault(); setPendingFile(file)
}
const handleDrop = (event:DragEvent) => {
  const file = event.dataTransfer?.files?.[0]
  if (file) setPendingFile(file)
}
const handleWindowPaste = (event:ClipboardEvent) => {
  if (!drawerOpen.value) return
  const target = event.target as HTMLElement | null
  if (target?.closest('input, textarea, [contenteditable="true"]')) return
  handlePaste(event)
}
onMounted(() => window.addEventListener('paste', handleWindowPaste))
onBeforeUnmount(() => window.removeEventListener('paste', handleWindowPaste))

const save = async () => {
  saving.value=true; error.value=''
  const fd=new FormData()
  Object.entries(form.value).forEach(([key,value]) => { if(value !== '') fd.append(key,value) })
  if (!form.value.collaboration_id) fd.append('collaboration_id','')
  if (!form.value.post_url) fd.append('post_url','')
  if (!form.value.published_at) fd.append('published_at','')
  if (!form.value.planned_publish_at) fd.append('planned_publish_at','')
  if (evidenceFile.value) fd.append('evidence',evidenceFile.value)
  try {
    if(editingId.value) fd.append('_method','PATCH')
    const url=editingId.value ? `/kol/contents/${editingId.value}` : '/kol/contents'
    await axios.post(url,fd)
    closeDrawer(); router.reload({ only:['contents'] })
  } catch(e:any) {
    const errors=e?.response?.data?.errors
    error.value=errors ? Object.values(errors).flat().join('；') : (e?.response?.data?.message || '内容保存失败')
  } finally { saving.value=false }
}
const syncPostImage = async () => {
  if (!editingId.value || !canSyncPostImage.value) return
  syncing.value=true; error.value=''
  try {
    const response=await axios.post(`/kol/contents/${editingId.value}/sync-post-image`)
    drawerAttachments.value=response.data.content.attachments as Attachment[]
    router.reload({ only:['contents'] })
  } catch(e:any) {
    const errors=e?.response?.data?.errors
    error.value=errors ? Object.values(errors).flat().join('；') : (e?.response?.data?.message || '同步帖子图片失败')
  } finally { syncing.value=false }
}
const deleteEvidence = async (contentId:number, attachment:Attachment, fromDrawer=false) => {
  if(!confirm(`删除素材 ${attachment.name}？`)) return
  try {
    const response=await axios.delete(`/kol/contents/${contentId}/evidence/${attachment.id}`)
    if (fromDrawer) drawerAttachments.value=response.data.content.attachments as Attachment[]
    router.reload({ only:['contents'] })
  } catch(e:any){ error.value=e?.response?.data?.message || '素材删除失败' }
}

const breadcrumbs=[{label:'BYL ERP',href:'/dashboard'},{label:'网红',href:'/kol'},'内容与发布']
</script>

<template>
  <Head title="内容与发布 · BYL ERP" />
  <AuthenticatedLayout title="内容与发布" :breadcrumbs="breadcrumbs">
    <section class="mvp-page content-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">KOL Content</p><h2>内容与发布</h2><p>已合作不等于已发布；已发布至少需要 Content URL 或发布素材截图。</p></div>
        <button v-if="canOperate" class="mvp-button mvp-button-primary" type="button" @click="openNew">新增内容</button>
      </div>

      <form class="content-toolbar" @submit.prevent="applyFilters">
        <select v-model="platform" aria-label="平台"><option value="">全部平台</option><option value="instagram">Instagram</option><option value="tiktok">TikTok</option><option value="youtube">YouTube</option><option value="facebook">Facebook</option><option value="twitter">X/Twitter</option><option value="xiaohongshu">小红书</option><option value="weibo">微博</option><option value="wechat">微信</option><option value="other">其他</option></select>
        <select v-model="publicationStatus" aria-label="发布状态"><option value="">全部状态</option><option value="published">已发布</option><option value="unpublished">未发布</option></select>
        <select v-model="evidence" aria-label="发布素材"><option value="">全部素材</option><option value="none">无素材</option><option value="url">仅 URL</option><option value="screenshot">仅截图</option><option value="url_and_screenshot">URL + 截图</option></select>
        <div class="content-search"><span>⌕</span><input v-model="search" placeholder="搜索 KOL / 合作 / URL ..."><button type="submit">搜索</button></div>
      </form>

      <div class="content-table-wrap">
        <table class="content-table">
          <thead><tr><th>内容</th><th>KOL</th><th>平台</th><th>Content 发布 URL</th><th>发布状态</th><th>发布素材</th><th>发布日期</th><th>正式合作</th><th></th></tr></thead>
          <tbody>
            <tr v-for="item in contents.data" :key="item.id">
              <td><div class="content-cell"><div class="content-thumb"><img v-if="thumbnailFor(item)" :src="thumbnailFor(item)!" alt=""><span v-else>▧</span></div><div><strong>#{{ item.id }} · {{ typeLabel(item.content_type) }}</strong><small>计划 {{ dateLabel(item.planned_publish_at) }}</small></div></div></td>
              <td><Link class="content-kol-link" :href="`/kol/library/${item.kol_id}`">{{ item.kol?.display_name ?? `KOL #${item.kol_id}` }}</Link></td>
              <td><span class="platform-chip">{{ platformLabel(item.platform) }}</span></td>
              <td class="url-cell"><a v-if="item.post_url" :href="item.post_url" target="_blank" rel="noopener noreferrer">{{ item.post_url }}</a><span v-else>—</span></td>
              <td><span :class="['publication-pill', item.publication_status === 'published' ? 'is-published' : 'is-unpublished']">{{ statusLabel(item.publication_status) }}</span></td>
              <td><span class="asset-summary">{{ evidenceLabel(item.evidence_classification) }}</span><small v-if="item.attachments.length">{{ item.attachments.length }} 张/份</small></td>
              <td><small>计划 {{ dateLabel(item.planned_publish_at) }}</small><small>实际 {{ dateLabel(item.published_at) }}</small></td>
              <td><span v-if="item.collaboration">#{{ item.collaboration.id }} · {{ item.collaboration.cooperation_type ?? '正式合作' }}</span><span v-else>—</span></td>
              <td><button v-if="canOperate" class="mvp-button" type="button" @click="openEdit(item)">编辑</button></td>
            </tr>
            <tr v-if="contents.data.length===0"><td colspan="9" class="mvp-empty">暂无内容记录</td></tr>
          </tbody>
        </table>
      </div>

      <div v-if="contents.last_page > 1" class="mvp-pagination">
        <button class="mvp-button" type="button" :disabled="contents.current_page <= 1" @click="goPage(contents.current_page - 1)">上一页</button>
        <span>第 {{ contents.current_page }} / {{ contents.last_page }} 页 · 共 {{ contents.total }} 条</span>
        <button class="mvp-button" type="button" :disabled="contents.current_page >= contents.last_page" @click="goPage(contents.current_page + 1)">下一页</button>
      </div>
    </section>

    <div v-if="drawerOpen" class="content-drawer-backdrop" @click.self="closeDrawer">
      <aside class="content-drawer" role="dialog" aria-modal="true" aria-labelledby="content-editor-title">
        <header class="drawer-header">
          <div><h2 id="content-editor-title">{{ editingId ? '编辑内容' : '新增内容' }}</h2><p>{{ selectedKolName }} · {{ platformLabel(form.platform) }}</p></div>
          <div class="drawer-actions"><button class="drawer-btn" type="button" @click="closeDrawer">取消</button><button class="drawer-btn drawer-btn-primary" type="button" :disabled="saving || !form.kol_id" @click="save">{{ saving ? '保存中…' : (editingId ? '保存修改' : '创建内容') }}</button><button class="drawer-close" type="button" aria-label="关闭" @click="closeDrawer">×</button></div>
        </header>

        <div class="drawer-scroll">
          <div v-if="error" class="form-error">{{ error }}</div>

          <section class="drawer-section">
            <h3>关联信息</h3>
            <div v-if="!editingId" class="field span-2"><label>搜索 KOL</label><div class="kol-search-row"><input v-model="kolSearch" type="text" placeholder="输入 KOL 名称" @keyup.enter.prevent="loadKolOptions()"><button class="drawer-btn" type="button" :disabled="optionLoading" @click="loadKolOptions()">{{ optionLoading ? '搜索中…' : '搜索' }}</button></div></div>
            <div class="two-col">
              <div class="field"><label>KOL</label><div v-if="editingId" class="readonly-field">{{ selectedKolName }}</div><select v-else v-model="form.kol_id" @change="changeKol"><option value="">请选择 KOL</option><option v-for="k in kolOptions" :key="k.id" :value="String(k.id)">{{ k.display_name }}</option></select><small>创建后不可变更 KOL。</small></div>
              <div class="field"><label>关联正式合作</label><select v-model="form.collaboration_id"><option value="">不关联</option><option v-for="c in selectedCollaborations" :key="c.id" :value="String(c.id)">#{{ c.id }} · {{ c.cooperation_type ?? '合作' }} · {{ c.status }}</option></select></div>
            </div>
            <div class="reference-box"><label>合作主链接参考</label><div class="reference-row"><div class="readonly-field reference-url">{{ primaryReferenceUrl || '所选正式合作暂无主贴 URL' }}</div><button class="drawer-btn" type="button" :disabled="!primaryReferenceUrl" @click="copyPrimaryUrl">复制到 Content URL</button></div><small>Content 发布 URL 是具体内容事实；正式合作主链接仅作参考，不会自动覆盖。</small></div>
          </section>

          <section class="drawer-section">
            <h3>发布信息</h3>
            <div class="two-col"><div class="field"><label>平台</label><select v-model="form.platform"><option value="instagram">Instagram</option><option value="tiktok">TikTok</option><option value="youtube">YouTube</option><option value="facebook">Facebook</option><option value="twitter">X/Twitter</option><option value="xiaohongshu">小红书</option><option value="weibo">微博</option><option value="wechat">微信</option><option value="other">其他</option></select></div><div class="field"><label>内容类型</label><select v-model="form.content_type"><option value="post">图文</option><option value="reel">Reel</option><option value="short_video">短视频</option><option value="long_video">长视频</option><option value="story">Story</option><option value="live">直播</option><option value="article">文章</option><option value="other">其他</option></select></div></div>
            <div class="field status-field"><label>发布状态</label><div class="status-toggle" role="group" aria-label="发布状态"><button type="button" :class="{active:form.publication_status==='published'}" @click="setPublicationStatus('published')">已发布</button><button type="button" :class="{active:form.publication_status==='unpublished'}" @click="setPublicationStatus('unpublished')">未发布</button></div></div>
            <div class="field"><label>Content 发布 URL</label><input v-model="form.post_url" type="url" placeholder="https://www.instagram.com/p/..."><small>标记“已发布”时，URL 与发布素材截图至少存在一个。</small></div>
          </section>

          <section class="drawer-section">
            <h3>发布时间</h3>
            <div class="two-col"><div class="field"><label>计划发布日期</label><input v-model="form.planned_publish_at" type="date"></div><div class="field"><label>实际发布日期</label><input v-model="form.published_at" type="date" :disabled="form.publication_status!=='published'"></div></div>
          </section>

          <section class="drawer-section">
            <h3>发布素材截图</h3>
            <input ref="fileInput" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp,image/gif,.pdf" @change="chooseFile">
            <div class="upload-zone" tabindex="0" @click="openFilePicker" @keydown.enter.prevent="openFilePicker" @keydown.space.prevent="openFilePicker" @paste.stop="handlePaste" @dragover.prevent @drop.prevent="handleDrop">
              <div class="upload-main"><span class="upload-icon">⇧</span><strong>Ctrl + V / ⌘V 粘贴，或点击上传</strong><small>支持 PNG / JPG / WebP / GIF / PDF · 最大 10MB</small></div>
              <button class="drawer-btn sync-btn" type="button" :disabled="syncing || !canSyncPostImage" :title="!editingId ? '保存内容后可同步帖子图片' : (activeItem?.post_url !== form.post_url ? '请先保存当前 Content URL，再同步帖子图片' : (!syncSupported ? '该平台暂不支持自动同步' : ''))" @click.stop="syncPostImage">{{ syncing ? '同步中…' : '↻ 同步帖子图片' }}</button>
            </div>
            <div v-if="evidenceFile" class="asset-card is-pending"><div class="asset-preview"><img v-if="pendingPreviewUrl" :src="pendingPreviewUrl" alt="待上传素材"><span v-else>PDF</span></div><div class="asset-meta"><strong>{{ evidenceFile.name }}</strong><small>{{ (evidenceFile.size / 1024 / 1024).toFixed(2) }} MB · 待保存上传</small></div><button type="button" @click="clearPendingFile">移除</button></div>
            <div v-for="attachment in drawerAttachments" :key="attachment.id" class="asset-card"><div class="asset-preview"><img v-if="isImageMime(attachment.mime_type)" :src="attachment.url" alt="发布素材"><span v-else>PDF</span></div><div class="asset-meta"><strong>{{ attachment.name }}</strong><small>上传于 {{ dateLabel(attachment.created_at) }}</small></div><a :href="attachment.url" target="_blank" rel="noopener noreferrer">查看</a><button class="danger-link" type="button" @click="deleteEvidence(editingId!, attachment, true)">删除</button></div>
            <p class="private-note">🔒 私有存储，仅登录 ERP 且拥有该 KOL 数据权限的用户可查看。</p>
          </section>
        </div>
      </aside>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.content-page{max-width:none}.content-toolbar{display:flex;gap:10px;align-items:center;margin:18px 0}.content-toolbar select,.content-search{height:40px;border:1px solid #d7dee7;border-radius:8px;background:#fff}.content-toolbar select{padding:0 34px 0 12px;color:#344054}.content-search{display:flex;align-items:center;flex:1;min-width:280px;padding-left:12px}.content-search span{color:#98a2b3}.content-search input{flex:1;border:0;outline:0;padding:0 10px;background:transparent}.content-search button{height:100%;padding:0 16px;border:0;border-left:1px solid #e5e7eb;background:transparent;cursor:pointer}.content-table-wrap{overflow:auto;border:1px solid #e5eaf0;border-radius:10px;background:#fff}.content-table{width:100%;border-collapse:collapse;min-width:1180px}.content-table th{padding:12px 14px;text-align:left;font-size:12px;color:#667085;background:#f8fafc;border-bottom:1px solid #e8edf2}.content-table td{padding:12px 14px;font-size:13px;color:#344054;border-bottom:1px solid #eef2f6;vertical-align:middle}.content-table tr:last-child td{border-bottom:0}.content-table td small{display:block;color:#98a2b3;margin-top:4px}.content-cell{display:flex;align-items:center;gap:10px}.content-thumb{width:48px;height:48px;border-radius:7px;overflow:hidden;background:#f1f5f9;display:grid;place-items:center;color:#98a2b3;flex:0 0 auto}.content-thumb img{width:100%;height:100%;object-fit:cover}.content-kol-link{font-weight:600;color:#367d76}.platform-chip{font-weight:600}.url-cell{max-width:220px}.url-cell a{display:block;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#32736d}.publication-pill{display:inline-flex;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:700}.publication-pill.is-published{background:#e9f7f3;color:#2c7a6d}.publication-pill.is-unpublished{background:#eef4fb;color:#52749b}.asset-summary{font-weight:600}.mvp-pagination{display:flex;justify-content:center;gap:12px;align-items:center;margin-top:16px}
.content-drawer-backdrop{position:fixed;inset:0;z-index:1600;background:rgba(15,23,42,.44);display:flex;justify-content:flex-end}.content-drawer{width:min(840px,calc(100vw - 260px));height:100dvh;background:#f8fafc;box-shadow:-18px 0 50px rgba(15,23,42,.18);overflow:hidden;display:flex;flex-direction:column;border-radius:16px 0 0 16px}.drawer-header{position:sticky;top:0;z-index:3;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px 24px;background:#fff;border-bottom:1px solid #e4e9ef}.drawer-header h2{margin:0;font-size:22px;color:#101828}.drawer-header p{margin:4px 0 0;font-size:13px;color:#667085}.drawer-actions{display:flex;align-items:center;gap:10px}.drawer-btn{height:38px;padding:0 16px;border:1px solid #d0d7e0;border-radius:7px;background:#fff;color:#344054;font-weight:600;cursor:pointer}.drawer-btn:hover:not(:disabled){background:#f8fafc}.drawer-btn:disabled{opacity:.48;cursor:not-allowed}.drawer-btn-primary{border-color:#2f8f83;background:#2f8f83;color:#fff}.drawer-btn-primary:hover:not(:disabled){background:#287d73}.drawer-close{width:34px;height:34px;border:0;background:transparent;font-size:26px;line-height:1;color:#344054;cursor:pointer}.drawer-scroll{padding:14px 18px 28px;overflow-y:auto}.drawer-section{padding:16px;margin-bottom:12px;background:#fff;border:1px solid #e1e7ee;border-radius:10px}.drawer-section h3{margin:0 0 14px;font-size:16px;color:#1d2939}.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px}.field{display:flex;flex-direction:column;gap:6px;margin-bottom:13px}.field:last-child{margin-bottom:0}.field label,.reference-box label{font-size:12px;font-weight:700;color:#344054}.field input,.field select,.kol-search-row input{width:100%;height:38px;border:1px solid #d2d9e2;border-radius:7px;padding:0 11px;background:#fff;color:#344054;outline:none}.field input:focus,.field select:focus,.kol-search-row input:focus{border-color:#6bb5aa;box-shadow:0 0 0 3px rgba(47,143,131,.08)}.field input:disabled{background:#f2f4f7;color:#98a2b3}.field small,.reference-box small{font-size:11px;color:#7d8998}.readonly-field{height:38px;display:flex;align-items:center;padding:0 11px;border:1px solid #d2d9e2;border-radius:7px;background:#f7f9fb;color:#475467}.kol-search-row,.reference-row{display:flex;gap:8px}.kol-search-row input,.reference-url{flex:1}.reference-box{padding:12px;margin-top:2px;border:1px solid #e4e9ef;border-radius:8px;background:#fafbfc}.reference-row{margin:7px 0 6px}.status-field{max-width:360px}.status-toggle{display:grid;grid-template-columns:1fr 1fr;height:36px;border:1px solid #cfd7df;border-radius:7px;overflow:hidden;background:#fff}.status-toggle button{border:0;background:#fff;color:#667085;font-weight:700;cursor:pointer}.status-toggle button+button{border-left:1px solid #cfd7df}.status-toggle button.active{color:#247a70;background:#eff9f6;box-shadow:inset 0 0 0 1px #47a596}.upload-zone{display:flex;align-items:center;gap:14px;min-height:76px;padding:12px 14px;border:1px dashed #aeb9c7;border-radius:8px;background:#fcfdff;cursor:pointer;outline:none}.upload-zone:focus{border-color:#4ba395;box-shadow:0 0 0 3px rgba(47,143,131,.08)}.upload-main{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;text-align:center}.upload-main strong{font-size:13px;color:#344054}.upload-main small{font-size:11px;color:#98a2b3}.upload-icon{font-size:22px;color:#5b6b7d}.sync-btn{flex:0 0 auto}.asset-card{display:grid;grid-template-columns:48px 1fr auto auto;gap:10px;align-items:center;margin-top:10px;padding:9px;border:1px solid #e1e7ee;border-radius:8px;background:#fff}.asset-card.is-pending{background:#f7fbfa}.asset-preview{width:48px;height:48px;border-radius:6px;overflow:hidden;background:#edf2f7;display:grid;place-items:center;font-size:11px;font-weight:700;color:#667085}.asset-preview img{width:100%;height:100%;object-fit:cover}.asset-meta{min-width:0}.asset-meta strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;color:#344054}.asset-meta small{font-size:11px;color:#98a2b3}.asset-card a,.asset-card button{border:0;background:transparent;color:#2f8f83;font-weight:600;text-decoration:none;cursor:pointer}.asset-card .danger-link{color:#d54b4b}.private-note{margin:10px 0 0;font-size:11px;color:#667085}.form-error{margin-bottom:12px;padding:10px 12px;border:1px solid #f2b8b5;border-radius:7px;background:#fff5f4;color:#a33b34;font-size:12px}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media(max-width:1000px){.content-drawer{width:min(840px,calc(100vw - 32px))}.content-toolbar{flex-wrap:wrap}.content-search{flex-basis:100%}}@media(max-width:720px){.content-drawer{width:100vw;border-radius:0}.drawer-header{padding:14px}.drawer-actions .drawer-btn:first-child{display:none}.drawer-scroll{padding:10px}.two-col{grid-template-columns:1fr}.reference-row{flex-direction:column}.status-field{max-width:none}.upload-zone{flex-direction:column}.sync-btn{width:100%}}
</style>
