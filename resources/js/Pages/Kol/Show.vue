<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type SocialMetric = { followers: number | null; raw_followers_value: string | null; measured_at: string | null }
type SocialAccount = { id: number; platform: string; handle: string | null; profile_url: string | null; is_active: boolean; latest_metric: SocialMetric | null }
type SalesPerformanceEvent = { id: number; order_count: number; recorded_at: string | null; source: string | null; note: string | null }
type Opportunity = { id: number; title: string; cooperation_type: string | null; target_products: string[]; stage: string; estimated_cost: number | null; currency: string; expected_publish_at: string | null; next_follow_up_at: string | null; next_action: string | null; owner: { id: number; name: string } | null; status: string }
type Communication = { id: number; channel: string; direction: string; summary: string; contacted_at: string | null; next_action: string | null; next_follow_up_at: string | null; creator: { id: number; name: string } | null; opportunity: { id: number; title: string } | null }
type HistoricalOpportunity = { id: number; title: string; cooperation_type: string | null; stage: string; status: string; created_at: string | null; estimated_cost: number | null; currency: string; owner: { id: number; name: string } | null }
type CollaborationProduct = { id: number; product_id: number | null; sku: string; name: string | null; quantity: number }
type FulfillmentOrder = { id:number; erp_order_id:string|null; order_status:string; shipment:{ shipment_status:string; warehouse_name:string|null; carrier:string|null; service:string|null; tracking_number:string|null; shipped_at:string|null }|null; manual_sync_status:string|null }
type ContentAttachment = { id:number; name:string; url:string }
type ContentRecord = { id:number; collaboration_id:number|null; platform:string; content_type:string; publication_status:string; post_url:string|null; planned_publish_at:string|null; published_at:string|null; evidence_classification:string; attachments:ContentAttachment[] }
type Collaboration = { id: number; opportunity_id: number | null; owner_id: number | null; order_id:number|null; owner: { id: number; name: string } | null; title: string | null; post_url: string | null; cooperation_type: string | null; status: string; agreement_amount: number | null; currency: string; agreed_at: string | null; planned_publish_at: string | null; deliverables: string | null; fulfillment_required: boolean; notes: string | null; products: CollaborationProduct[]; fulfillment_order:FulfillmentOrder|null; created_at: string | null }
type KolDetail = {
  id: number; display_name: string; avatar_url?: string | null; avatar_source?: string | null; avatar_updated_at?: string | null; legal_name: string | null; country_code: string | null; language: string | null
  status: string; relationship_stage: string; cooperation_type: string | null; owner_id: number | null
  owner: { id: number; name: string } | null; joined_at: string | null; archived_at: string | null; notes: string | null
  contact_email: string | null; contact_phone: string | null; contact_whatsapp: string | null; other_contact: string | null
  preferred_contact_method: string
  tax_id: string | null; payout_method: string | null; payout_account: string | null
  shipping_recipient_name: string | null; shipping_country: string | null; shipping_province_state: string | null
  shipping_city: string | null; shipping_postal_code: string | null
  shipping_address_line_1: string | null; shipping_address_line_2: string | null
  level: string | null; gender: string | null; categories: string[]; products: string[]
  content_format: string | null; product_quantity: number | null
  internal_contact: string | null; mcn_agency: string | null; is_mcn_managed: boolean
  historical_order_count: number
  sales_performance_history: SalesPerformanceEvent[]
  social_accounts: SocialAccount[]
  current_opportunity: Opportunity | null
  recent_communications: Communication[]
  historical_opportunities: HistoricalOpportunity[]
  collaborations: Collaboration[]
  contents?: ContentRecord[]
}

const props = defineProps<{
  kol: KolDetail
  canOperate: boolean
  canCreateOrders?: boolean
  canManageViews: boolean
}>()

const archive = () => {
  if (confirm(`确认归档 ${props.kol.display_name}？`)) {
    router.post(`/kol/library/${props.kol.id}/archive`)
  }
}
const reactivate = () => router.post(`/kol/library/${props.kol.id}/reactivate`)

const stageLabel = (s: string) => ({ prospect: '潜在', contacted: '已联系', negotiating: '谈判中', cooperating: '合作中', long_term: '长期合作', paused: '暂停', ended: '已结束' }[s] ?? s)
const platformLabel = (p: string) => ({ instagram: 'Instagram', tiktok: 'TikTok', youtube: 'YouTube', xiaohongshu: '小红书', weibo: '微博', wechat: '微信', twitter: 'X/Twitter', facebook: 'Facebook', other: '其他' }[p] ?? p)
const followersLabel = (n: number | null) => n === null ? '—' : n >= 1000000 ? `${(n / 1000000).toFixed(1)}M` : n >= 1000 ? `${(n / 1000).toFixed(1)}K` : String(n)
const contactMethodLabel = (m: string) => ({ phone: '电话', email: 'Email', whatsapp: 'WhatsApp', other: '其他' }[m] ?? m)
const countryLabel = (code: string | null) => ({ US: 'United States (US)', GB: 'United Kingdom (GB)', DE: 'Germany (DE)', FR: 'France (FR)', CA: 'Canada (CA)', EU: 'Europe (EU)', AU: 'Australia (AU)', ASIA: '东南亚 (Asia)', PH: 'Philippines (PH)', OTHER: '其他' }[code ?? ''] ?? code ?? '—')
const languageLabel = (code: string | null) => ({ en: 'English', es: 'Spanish', de: 'German', fr: 'French', it: 'Italian', zh: 'Chinese', other: '其他' }[code ?? ''] ?? code ?? '—')
const cooperationLabel = (value: string | null) => value ?? '—'
const joinedDateLabel = (value: string | null) => value ? value.slice(0, 10).replaceAll('-', '/') : '—'
const opportunityStages = [
  { value: 'pending_contact', label: '待联系' },
  { value: 'contacted', label: '已联系' },
  { value: 'communicating', label: '沟通中' },
  { value: 'confirmed', label: '已确认' },
  { value: 'waiting_sample', label: '等待寄样' },
  { value: 'success', label: '成功' },
  { value: 'failed', label: '失败' },
]
const opportunityStageLabel = (value: string) => opportunityStages.find((stage) => stage.value === value)?.label ?? value
const opportunityStageIndex = (value: string) => Math.max(0, opportunityStages.findIndex((stage) => stage.value === value))
const moneyLabel = (amount: number | null, currency: string) => amount === null ? '—' : `${currency} ${new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(amount)}`
const dateLabel = (value: string | null) => value ? value.slice(0, 10).replaceAll('-', '/') : '—'
const dateTimeLabel = (value: string | null) => value ? `${value.slice(0, 10).replaceAll('-', '/')} ${value.slice(11, 16)}` : '—'
const communicationChannelLabel = (value: string) => ({ instagram_dm: 'Instagram DM', email: 'Email', x_dm: 'X DM', whatsapp: 'WhatsApp', phone: '电话', other: '其他' }[value] ?? value)
const communicationMark = (value: string) => ({ instagram_dm: 'IG', email: '✉', x_dm: '𝕏', whatsapp: 'WA', phone: '☎', other: '•' }[value] ?? '•')
const communicationDirectionLabel = (value: string) => value === 'inbound' ? '对方联系我方' : '我方联系对方'
const opportunityStatusLabel = (value: string) => ({ active: '进行中', success: '成功', failed: '失败', paused: '暂停', completed: '已完成', cancelled: '已取消' }[value] ?? value)
const opportunityStatusClass = (value: string) => value === 'success' || value === 'completed' ? 'is-success' : value === 'failed' ? 'is-failed' : value === 'paused' ? 'is-paused' : 'is-neutral'
const collaborationStatusLabel = (value: string) => ({ draft: '草稿', confirmed: '已确认', in_progress: '进行中', completed: '已完成', cancelled: '已取消' }[value] ?? value)
const contentStatusLabel = (value:string) => value === 'published' ? '已发布' : '未发布'
const contentTypeLabel = (value:string) => ({ post:'图文', reel:'Reel', short_video:'短视频', long_video:'长视频', story:'Story', live:'直播', article:'文章', other:'其他' }[value] ?? value)
const evidenceLabel = (value:string) => ({ none:'无证据', url:'URL', screenshot:'截图/附件', url_and_screenshot:'URL + 截图/附件' }[value] ?? value)
const currentCollaboration = computed(() => props.kol.collaborations[0] ?? null)
const collaborationHistory = computed(() => props.kol.collaborations.filter(item => item.status === 'completed' || item.status === 'cancelled'))
const contentRows = computed(() => props.kol.contents ?? [])

const primarySocial = computed(() => props.kol.social_accounts.find((account) => account.is_active) ?? props.kol.social_accounts[0] ?? null)
const strongestFollowers = computed(() => {
  const values = props.kol.social_accounts.map((account) => account.latest_metric?.followers).filter((value): value is number => value !== null && value !== undefined)
  return values.length ? Math.max(...values) : null
})
const profileInitial = computed(() => props.kol.display_name.trim().slice(0, 1).toUpperCase() || 'K')
const primaryHandle = computed(() => primarySocial.value?.handle ? `@${primarySocial.value.handle}` : '暂无主账号')

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '红人库', href: '/kol/library' }, props.kol.display_name]
</script>

<template>
  <Head :title="`${kol.display_name} · BYL ERP`" />
  <AuthenticatedLayout :title="kol.display_name" :breadcrumbs="breadcrumbs">
    <section class="kol-detail-page">
      <div class="kol-detail-toolbar">
        <div class="kol-detail-toolbar__trail">KOL 资料库 / KOL 详情</div>
        <div class="kol-detail-toolbar__actions page-header-actions">
          <Link class="kol-action-button" href="/kol/library">返回列表</Link>
          <Link v-if="canOperate" class="kol-action-button" :href="`/kol/library/${kol.id}/edit`">编辑 KOL</Link>
          <button v-if="canOperate && !kol.archived_at" class="kol-action-button kol-action-button--danger" type="button" @click="archive">归档</button>
          <button v-else-if="canOperate" class="kol-action-button" type="button" @click="reactivate">重新激活</button>
          <Link v-if="canOperate" class="kol-action-button kol-action-button--primary" :href="`/kol/library/${kol.id}/edit#crm-opportunities`">新增合作机会</Link>
          <Link v-if="canOperate" class="kol-action-button" :href="`/kol/library/${kol.id}/edit#crm-communications`">新增沟通</Link>
        </div>
      </div>

      <section class="kol-profile-hero" aria-label="KOL 概览">
        <div class="kol-profile-identity">
          <div class="kol-avatar"><img v-if="kol.avatar_url" :src="kol.avatar_url" :alt="`${kol.display_name} 头像`" class="kol-avatar__image"><span v-else aria-hidden="true">{{ profileInitial }}</span></div>
          <div class="kol-profile-copy">
            <div class="kol-profile-name-row">
              <h2>{{ kol.display_name }}</h2>
              <span class="kol-status-pill">{{ stageLabel(kol.relationship_stage) }}</span>
            </div>
            <div class="kol-profile-handle">{{ primaryHandle }}<span v-if="primarySocial"> · {{ platformLabel(primarySocial.platform) }}</span></div>
            <div class="kol-profile-meta">
              <span>{{ countryLabel(kol.country_code) }}</span>
              <span>{{ languageLabel(kol.language) }}</span>
            </div>
            <div class="kol-profile-tags">
              <span v-for="category in kol.categories.slice(0, 4)" :key="category" class="kol-tag">{{ category }}</span>
              <span v-if="kol.categories.length === 0" class="kol-tag kol-tag--muted">暂无标签</span>
            </div>
          </div>
        </div>

        <div class="kol-hero-metrics">
          <div class="kol-stat"><span>粉丝数</span><strong>{{ followersLabel(strongestFollowers) }}</strong></div>
          <div class="kol-stat"><span>平均互动率</span><strong>—</strong></div>
          <div class="kol-stat"><span>平均播放量</span><strong>—</strong></div>
          <div class="kol-stat"><span>合作次数</span><strong>—</strong></div>
        </div>
      </section>

      <div v-if="kol.archived_at" class="archived-banner">此 KOL 已归档（{{ kol.archived_at }}）</div>

      <!-- Region B + C · 左侧资料列 -->
      <div class="detail-grid kol-primary-grid">
        <div class="kol-left-stack">
          <section class="kol-info-card" aria-labelledby="kol-basic-info-title">
            <div class="kol-card-heading">
              <h3 id="kol-basic-info-title">基本信息</h3>
              <Link v-if="canOperate" class="kol-card-edit" :href="`/kol/library/${kol.id}/edit`" aria-label="编辑 KOL 基本信息">✎ <span>编辑</span></Link>
            </div>
            <dl class="kol-basic-list">
              <div class="kol-basic-row"><dt><span class="kol-basic-icon">♙</span><span>显示名称</span></dt><dd>{{ kol.display_name }}</dd></div>
              <div class="kol-basic-row"><dt><span class="kol-basic-icon">✉</span><span>邮箱</span></dt><dd>{{ kol.contact_email ?? '—' }}</dd></div>
              <div class="kol-basic-row"><dt><span class="kol-basic-icon">◎</span><span>国家/地区</span></dt><dd>{{ countryLabel(kol.country_code) }}</dd></div>
              <div class="kol-basic-row"><dt><span class="kol-basic-icon">◉</span><span>语言</span></dt><dd>{{ languageLabel(kol.language) }}</dd></div>
              <div class="kol-basic-row"><dt><span class="kol-basic-icon">✚</span><span>合作类型</span></dt><dd>{{ cooperationLabel(kol.cooperation_type) }}</dd></div>
              <div class="kol-basic-row"><dt><span class="kol-basic-icon">◫</span><span>加入时间</span></dt><dd>{{ joinedDateLabel(kol.joined_at) }}</dd></div>
              <div class="kol-basic-row"><dt><span class="kol-basic-icon">◇</span><span>状态</span></dt><dd><span :class="['kol-basic-status', kol.status === 'active' ? 'is-active' : 'is-inactive']">{{ kol.status === 'active' ? '活跃' : '停用' }}</span></dd></div>
              <div class="kol-basic-row"><dt><span class="kol-basic-icon">♙</span><span>负责人</span></dt><dd>{{ kol.owner?.name ?? '—' }}</dd></div>
              <div class="kol-basic-row kol-basic-row--notes"><dt><span class="kol-basic-icon">▣</span><span>备注</span></dt><dd>{{ kol.notes ?? '—' }}</dd></div>
            </dl>
          </section>

          <section class="kol-social-card" aria-labelledby="kol-social-data-title">
            <div class="kol-card-heading kol-social-heading">
              <h3 id="kol-social-data-title">社交媒体数据</h3>
              <button class="kol-card-view-all" type="button" disabled title="独立社媒数据页尚未启用">查看全部</button>
            </div>
            <div v-if="kol.social_accounts.length === 0" class="kol-social-empty">暂无社交账号</div>
            <div v-else class="kol-social-list">
              <a v-for="sa in kol.social_accounts" :key="sa.id" :href="sa.profile_url ?? undefined" :target="sa.profile_url ? '_blank' : undefined" :rel="sa.profile_url ? 'noopener noreferrer' : undefined" :class="['kol-social-data-row', `is-${sa.platform}`]">
                <span class="kol-platform-mark">{{ sa.platform === 'instagram' ? 'IG' : sa.platform === 'youtube' ? '▶' : sa.platform === 'tiktok' ? '♪' : sa.platform === 'twitter' ? '𝕏' : sa.platform.slice(0, 2).toUpperCase() }}</span>
                <span class="kol-social-account"><strong>{{ sa.handle ? `@${sa.handle}` : platformLabel(sa.platform) }}</strong><small v-if="!sa.is_active">停用</small></span>
                <span class="kol-social-followers"><strong>{{ followersLabel(sa.latest_metric?.followers ?? null) }}</strong><small>{{ sa.platform === 'youtube' ? '订阅' : '粉丝' }}</small></span>
                <span class="kol-social-engagement"><strong>—</strong><small>互动率</small></span>
              </a>
            </div>
          </section>
        </div>

        <div class="kol-right-stack">
        <!-- Region D · 当前合作机会 -->
        <section class="kol-opportunity-card" aria-labelledby="kol-current-opportunity-title">
          <div class="kol-card-heading kol-card-heading--opportunity">
            <h3 id="kol-current-opportunity-title">当前合作机会</h3>
            <Link class="kol-card-view-all" :href="`/kol/library/${kol.id}/edit#crm-opportunities`">管理机会</Link>
          </div>

          <div v-if="kol.current_opportunity" class="kol-opportunity-body">
            <div class="kol-opportunity-title-row">
              <strong>{{ kol.current_opportunity.title }}</strong>
              <span class="kol-opportunity-stage-pill">{{ opportunityStageLabel(kol.current_opportunity.stage) }}</span>
            </div>

            <dl class="kol-opportunity-facts">
              <div><dt>合作类型</dt><dd>{{ kol.current_opportunity.cooperation_type ?? '—' }}</dd></div>
              <div><dt>预计发布时间</dt><dd>{{ dateLabel(kol.current_opportunity.expected_publish_at) }}</dd></div>
              <div><dt>目标产品</dt><dd>{{ kol.current_opportunity.target_products.length ? kol.current_opportunity.target_products.join('、') : '—' }}</dd></div>
              <div><dt>下次跟进</dt><dd>{{ dateLabel(kol.current_opportunity.next_follow_up_at) }}</dd></div>
              <div><dt>当前阶段</dt><dd><span class="kol-inline-stage">{{ opportunityStageLabel(kol.current_opportunity.stage) }}</span></dd></div>
              <div><dt>负责人</dt><dd>{{ kol.current_opportunity.owner?.name ?? '—' }}</dd></div>
              <div><dt>预计费用</dt><dd>{{ moneyLabel(kol.current_opportunity.estimated_cost, kol.current_opportunity.currency) }}</dd></div>
            </dl>

            <div class="kol-stage-timeline" aria-label="合作机会阶段">
              <div v-for="(stage, index) in opportunityStages" :key="stage.value" class="kol-stage-step" :class="{ 'is-done': index < opportunityStageIndex(kol.current_opportunity.stage), 'is-current': index === opportunityStageIndex(kol.current_opportunity.stage) }">
                <div class="kol-stage-track">
                  <span class="kol-stage-dot">{{ index < opportunityStageIndex(kol.current_opportunity.stage) ? '✓' : '' }}</span>
                  <span v-if="index < opportunityStages.length - 1" class="kol-stage-line"></span>
                </div>
                <span class="kol-stage-label">{{ stage.label }}</span>
              </div>
            </div>

            <div class="kol-next-action">
              <strong>下一步动作：</strong>
              <span>{{ kol.current_opportunity.next_action ?? '—' }}</span>
            </div>
          </div>
          <div v-else class="kol-opportunity-empty">暂无进行中的合作机会</div>
        </section>

        <!-- M1.2A · 当前正式合作 -->
        <section class="kol-collaboration-card" aria-labelledby="kol-current-collaboration-title">
          <div class="kol-card-heading">
            <h3 id="kol-current-collaboration-title">当前正式合作</h3>
            <Link class="kol-card-view-all" :href="`/kol/library/${kol.id}/edit#crm-collaborations`">管理合作</Link>
          </div>
          <div v-if="currentCollaboration" class="kol-collaboration-detail">
            <div class="kol-collaboration-title-row"><a v-if="currentCollaboration.post_url" :href="currentCollaboration.post_url" target="_blank" rel="noopener noreferrer"><strong>{{ currentCollaboration.post_url }}</strong></a><strong v-else>待补合作发帖 URL</strong><span class="kol-opportunity-stage-pill">{{ collaborationStatusLabel(currentCollaboration.status) }}</span></div>
            <dl class="kol-opportunity-facts">
              <div><dt>合作类型</dt><dd>{{ currentCollaboration.cooperation_type ?? '—' }}</dd></div>
              <div><dt>确认日期</dt><dd>{{ dateLabel(currentCollaboration.agreed_at) }}</dd></div>
              <div><dt>计划发布时间</dt><dd>{{ dateLabel(currentCollaboration.planned_publish_at) }}</dd></div>
              <div><dt>合作金额</dt><dd>{{ moneyLabel(currentCollaboration.agreement_amount, currentCollaboration.currency) }}</dd></div>
              <div><dt>负责人</dt><dd>{{ currentCollaboration.owner?.name ?? '—' }}</dd></div>
              <div><dt>寄样/发货</dt><dd>{{ currentCollaboration.fulfillment_required ? '需要' : '不需要' }}</dd></div>
            </dl>
            <div class="kol-collaboration-products"><strong>合作产品：</strong><span>{{ currentCollaboration.products.length ? currentCollaboration.products.map(p => `${p.sku} ×${p.quantity}`).join('、') : '—' }}</span></div>
            <div v-if="currentCollaboration.fulfillment_order" class="kol-next-action"><strong>履约订单：</strong><span><Link :href="`/kol/orders/${currentCollaboration.fulfillment_order.id}`">{{ currentCollaboration.fulfillment_order.erp_order_id ?? '—' }}</Link> · {{ currentCollaboration.fulfillment_order.order_status }}</span></div>
            <div v-if="currentCollaboration.fulfillment_order?.shipment" class="kol-next-action"><strong>发货状态：</strong><span>{{ currentCollaboration.fulfillment_order.shipment.shipment_status }} · {{ currentCollaboration.fulfillment_order.shipment.warehouse_name ?? '未选择仓库' }} · {{ currentCollaboration.fulfillment_order.shipment.tracking_number ?? '暂无追踪号' }}</span></div>
            <div v-if="currentCollaboration.deliverables" class="kol-next-action"><strong>约定交付：</strong><span>{{ currentCollaboration.deliverables }}</span></div>
          </div>
          <div v-else class="kol-opportunity-empty">暂无正式合作</div>
        </section>

        <!-- M1.3 · 内容与发布 -->
        <section class="kol-collaboration-card kol-content-publication-card" aria-labelledby="kol-content-publication-title">
          <div class="kol-card-heading">
            <h3 id="kol-content-publication-title">内容与发布</h3>
            <Link class="kol-card-view-all" :href="`/kol/content?search=${encodeURIComponent(kol.display_name)}`">管理内容</Link>
          </div>
          <div v-if="contentRows.length" class="kol-history-scroll">
            <table class="kol-history-table">
              <thead><tr><th>平台 / 类型</th><th>发布 URL</th><th>状态</th><th>发布证据</th><th>计划时间</th><th>实际时间</th></tr></thead>
              <tbody><tr v-for="content in contentRows" :key="`content-${content.id}`">
                <td><strong>{{ platformLabel(content.platform) }}</strong><small class="kol-history-note">{{ contentTypeLabel(content.content_type) }}</small></td>
                <td><a v-if="content.post_url" :href="content.post_url" target="_blank" rel="noopener noreferrer">{{ content.post_url }}</a><span v-else>—</span></td>
                <td><span class="kol-history-status" :class="content.publication_status === 'published' ? 'is-success' : 'is-neutral'">{{ contentStatusLabel(content.publication_status) }}</span></td>
                <td><strong>{{ evidenceLabel(content.evidence_classification) }}</strong><small v-for="attachment in content.attachments" :key="attachment.id" class="kol-history-note"><a :href="attachment.url" target="_blank" rel="noopener noreferrer">{{ attachment.name }}</a></small></td>
                <td>{{ dateLabel(content.planned_publish_at) }}</td>
                <td>{{ dateLabel(content.published_at) }}</td>
              </tr></tbody>
            </table>
          </div>
          <div v-else class="kol-opportunity-empty">暂无内容发布记录</div>
        </section>

        <!-- Region E · 最近沟通 -->
        <section class="kol-communications-card" aria-labelledby="kol-recent-communications-title">
          <div class="kol-card-heading kol-card-heading--communications">
            <h3 id="kol-recent-communications-title">最近沟通</h3>
            <Link class="kol-card-view-all" :href="`/kol/library/${kol.id}/edit#crm-communications`">管理沟通 <span v-if="kol.recent_communications.length">({{ kol.recent_communications.length }})</span></Link>
          </div>
          <div v-if="kol.recent_communications.length" class="kol-communications-list">
            <article v-for="communication in kol.recent_communications" :key="communication.id" class="kol-communication-row">
              <span :class="['kol-communication-mark', `is-${communication.channel}`]">{{ communicationMark(communication.channel) }}</span>
              <div class="kol-communication-main">
                <div class="kol-communication-title">
                  <strong>{{ communicationChannelLabel(communication.channel) }} · {{ communicationDirectionLabel(communication.direction) }}</strong>
                  <time>{{ dateTimeLabel(communication.contacted_at) }}</time>
                </div>
                <p>{{ communication.summary }}</p>
                <div class="kol-communication-meta">
                  <span v-if="communication.opportunity">{{ communication.opportunity.title }}</span>
                  <span v-if="communication.next_action">下一步：{{ communication.next_action }}</span>
                  <span v-if="communication.next_follow_up_at">跟进：{{ dateLabel(communication.next_follow_up_at) }}</span>
                </div>
              </div>
              <span class="kol-communication-owner">{{ communication.creator?.name ?? '—' }}</span>
            </article>
          </div>
          <div v-else class="kol-communications-empty">暂无沟通记录</div>
        </section>
        </div>
      </div>

      <!-- Region F · 历史合作记录（合作机会 + 带货记录合并） -->
      <section class="kol-history-card" aria-labelledby="kol-history-opportunities-title">
        <div class="kol-card-heading kol-card-heading--history">
          <h3 id="kol-history-opportunities-title">历史合作记录</h3>
          <Link class="kol-card-view-all" :href="`/kol/library/${kol.id}/edit#crm-opportunities`">管理记录</Link>
        </div>
        <div v-if="kol.historical_opportunities.length || kol.sales_performance_history.length || collaborationHistory.length" class="kol-history-scroll">
          <table class="kol-history-table">
            <thead><tr><th>合作项目</th><th>合作类型</th><th>时间</th><th>结果 / 阶段</th><th>带货数量</th><th>预计费用</th><th>负责人</th><th>来源</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="collaboration in collaborationHistory" :key="`collaboration-${collaboration.id}`">
                <td><a v-if="collaboration.post_url" :href="collaboration.post_url" target="_blank" rel="noopener noreferrer"><strong>{{ collaboration.post_url }}</strong></a><strong v-else>待补合作发帖 URL</strong><small v-if="collaboration.deliverables" class="kol-history-note">{{ collaboration.deliverables }}</small></td>
                <td>{{ collaboration.cooperation_type ?? '—' }}</td>
                <td>{{ dateLabel(collaboration.agreed_at ?? collaboration.created_at) }}</td>
                <td><span :class="['kol-history-status', collaboration.status === 'completed' ? 'is-success' : 'is-neutral']">{{ collaborationStatusLabel(collaboration.status) }}</span></td>
                <td>—</td>
                <td>{{ moneyLabel(collaboration.agreement_amount, collaboration.currency) }}</td>
                <td>{{ collaboration.owner?.name ?? '—' }}</td>
                <td><span class="kol-history-source">正式合作</span></td>
                <td><Link class="kol-history-action" :href="`/kol/library/${kol.id}/edit#crm-collaborations`">查看</Link></td>
              </tr>
              <tr v-for="opportunity in kol.historical_opportunities" :key="`opportunity-${opportunity.id}`">
                <td><strong>{{ opportunity.title }}</strong></td>
                <td>{{ opportunity.cooperation_type ?? '—' }}</td>
                <td>{{ dateLabel(opportunity.created_at) }}</td>
                <td><span :class="['kol-history-status', opportunityStatusClass(opportunity.status)]">{{ opportunityStatusLabel(opportunity.status) }} · {{ opportunityStageLabel(opportunity.stage) }}</span></td>
                <td>—</td>
                <td>{{ moneyLabel(opportunity.estimated_cost, opportunity.currency) }}</td>
                <td>{{ opportunity.owner?.name ?? '—' }}</td>
                <td><span class="kol-history-source">合作机会</span></td>
                <td><Link class="kol-history-action" :href="`/kol/library/${kol.id}/edit#crm-opportunities`">查看</Link></td>
              </tr>
              <tr v-for="event in kol.sales_performance_history" :key="`sales-${event.id}`">
                <td><strong>历史带货记录</strong><small v-if="event.note" class="kol-history-note">{{ event.note }}</small></td>
                <td>—</td>
                <td>{{ dateLabel(event.recorded_at) }}</td>
                <td><span class="kol-history-status is-legacy">历史记录</span></td>
                <td><strong>{{ event.order_count }}</strong></td>
                <td>—</td>
                <td>{{ kol.owner?.name ?? '—' }}</td>
                <td><span class="kol-history-source">{{ event.source ?? '历史记录' }}</span></td>
                <td><Link class="kol-history-action" :href="`/kol/library/${kol.id}/edit`">查看</Link></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="kol-history-empty">暂无历史合作记录</div>
      </section>

      <!-- Supplemental legacy facts · 信息无损保留 -->
      <section class="kol-supplemental" aria-labelledby="kol-supplemental-title">
        <div class="kol-supplemental-heading">
          <div>
            <h3 id="kol-supplemental-title">补充资料</h3>
            <p>保留新版主视图未覆盖的历史字段与运营资料</p>
          </div>
        </div>

        <div class="kol-supplemental-grid">
          <section class="kol-supplemental-card">
            <h4>联系方式</h4>
            <dl class="detail-list">
              <div><dt>首选方式</dt><dd>{{ contactMethodLabel(kol.preferred_contact_method) }}</dd></div>
              <div><dt>电话</dt><dd>{{ kol.contact_phone ?? '—' }}</dd></div>
              <div><dt>WhatsApp</dt><dd>{{ kol.contact_whatsapp ?? '—' }}</dd></div>
              <div><dt>其他</dt><dd>{{ kol.other_contact ?? '—' }}</dd></div>
            </dl>
          </section>

          <section class="kol-supplemental-card">
            <h4>收件地址</h4>
            <dl class="detail-list">
              <div><dt>收件人</dt><dd>{{ kol.shipping_recipient_name ?? '—' }}</dd></div>
              <div><dt>国家</dt><dd>{{ kol.shipping_country ?? '—' }}</dd></div>
              <div><dt>省/州</dt><dd>{{ kol.shipping_province_state ?? '—' }}</dd></div>
              <div><dt>城市</dt><dd>{{ kol.shipping_city ?? '—' }}</dd></div>
              <div><dt>邮编</dt><dd>{{ kol.shipping_postal_code ?? '—' }}</dd></div>
              <div><dt>详细地址</dt><dd>{{ kol.shipping_address_line_1 ?? '—' }}</dd></div>
              <div v-if="kol.shipping_address_line_2"><dt>历史地址行 2</dt><dd>{{ kol.shipping_address_line_2 }}</dd></div>
            </dl>
          </section>

          <section class="kol-supplemental-card">
            <h4>业务画像</h4>
            <dl class="detail-list">
              <div><dt>网红分级</dt><dd>{{ kol.level ?? '—' }}</dd></div>
              <div><dt>性别</dt><dd>{{ kol.gender ?? '—' }}</dd></div>
              <div><dt>主营品类</dt><dd>{{ kol.categories.length ? kol.categories.join('、') : '—' }}</dd></div>
              <div><dt>主推产品</dt><dd>{{ kol.products.length ? kol.products.join('、') : '—' }}</dd></div>
              <div><dt>内容形式</dt><dd>{{ kol.content_format ?? '—' }}</dd></div>
              <div><dt>产品数量</dt><dd>{{ kol.product_quantity ?? '—' }}</dd></div>
              <div><dt>历史带货数量</dt><dd>{{ kol.historical_order_count }}</dd></div>
            </dl>
          </section>

          <section class="kol-supplemental-card">
            <h4>税务与收款</h4>
            <dl class="detail-list">
              <div><dt>Tax ID</dt><dd>{{ kol.tax_id ?? '—' }}</dd></div>
              <div><dt>收款方式</dt><dd>{{ { paypal: 'PayPal', bank: '银行转账', other: '其他' }[kol.payout_method ?? ''] ?? '—' }}</dd></div>
              <div><dt>收款账户</dt><dd>{{ kol.payout_account ?? '—' }}</dd></div>
            </dl>
          </section>

          <section class="kol-supplemental-card">
            <h4>内部运营信息</h4>
            <dl class="detail-list">
              <div><dt>内部联系人</dt><dd>{{ kol.internal_contact ?? '—' }}</dd></div>
              <div><dt>MCN 机构</dt><dd>{{ kol.mcn_agency ?? '—' }}</dd></div>
              <div><dt>MCN 管理</dt><dd>{{ kol.is_mcn_managed ? '是' : '否' }}</dd></div>
            </dl>
          </section>


        </div>
      </section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.kol-detail-page { width: 100%; max-width: 1440px; margin: 0 auto; padding: 4px 0 28px; }
.kol-detail-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.kol-detail-toolbar__trail { color: #64748b; font-size: 13px; font-weight: 500; }
.kol-detail-toolbar__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; }
.kol-action-button { min-height: 38px; display: inline-flex; align-items: center; justify-content: center; padding: 0 15px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #334155; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; }
.kol-action-button:hover:not(:disabled) { border-color: #0f766e; color: #0f766e; }
.kol-action-button:disabled { cursor: not-allowed; opacity: .48; }
.kol-action-button--primary { background: #0f766e; border-color: #0f766e; color: #fff; }
.kol-action-button--danger { color: #b91c1c; border-color: #fecaca; }
.kol-profile-hero { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(420px, .9fr); align-items: center; gap: 30px; padding: 28px 30px; margin-bottom: 16px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; box-shadow: 0 8px 28px rgba(15, 23, 42, .05); }
.kol-profile-identity { min-width: 0; display: flex; align-items: center; gap: 20px; }
.kol-avatar { width: 78px; height: 78px; flex: 0 0 78px; display: grid; place-items: center; overflow: hidden; border-radius: 50%; background: #e7f4f1; color: #0f766e; font-size: 28px; font-weight: 700; border: 4px solid #f8fafc; box-shadow: 0 0 0 1px #dbe4e7; }
.kol-avatar__image { width: 100%; height: 100%; object-fit: cover; }
.kol-profile-copy { min-width: 0; }
.kol-profile-name-row { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.kol-profile-name-row h2 { margin: 0; color: #0f172a; font-size: 24px; line-height: 1.25; letter-spacing: -.02em; }
.kol-status-pill { padding: 4px 10px; border-radius: 999px; background: #e8f5e9; color: #2f7d46; font-size: 12px; font-weight: 700; }
.kol-profile-handle { margin-top: 7px; color: #64748b; font-size: 13px; }
.kol-profile-meta { display: flex; flex-wrap: wrap; gap: 18px; margin-top: 12px; color: #475569; font-size: 13px; }
.kol-profile-tags { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.kol-tag { padding: 4px 9px; border-radius: 7px; background: #f1f5f9; color: #475569; font-size: 12px; }
.kol-tag--muted { color: #94a3b8; }
.kol-hero-metrics { display: grid; grid-template-columns: repeat(4, minmax(86px, 1fr)); gap: 12px; }
.kol-stat { min-width: 0; padding-left: 16px; border-left: 1px solid #edf0f2; }
.kol-stat:first-child { padding-left: 0; border-left: 0; }
.kol-stat span { display: block; color: #64748b; font-size: 12px; white-space: nowrap; }
.kol-stat strong { display: block; margin-top: 8px; color: #111827; font-size: 24px; line-height: 1; font-weight: 700; }
@media (max-width: 1080px) { .kol-profile-hero { grid-template-columns: 1fr; } .kol-hero-metrics { padding-left: 98px; } }
@media (max-width: 760px) { .kol-detail-toolbar { align-items: flex-start; flex-direction: column; } .kol-detail-toolbar__actions { justify-content: flex-start; } .kol-profile-hero { padding: 20px; } .kol-profile-identity { align-items: flex-start; } .kol-avatar { width: 58px; height: 58px; flex-basis: 58px; font-size: 22px; } .kol-hero-metrics { grid-template-columns: repeat(2, 1fr); padding-left: 0; } .kol-stat:nth-child(3) { padding-left: 0; border-left: 0; } }
.legal-name { font-size: 13px; color: #64748b; }
.archived-banner { background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; font-size: 13px; }
.detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 16px; }
.kol-primary-grid { grid-template-columns: minmax(320px, 390px) minmax(0, 1fr); align-items: start; }
.kol-info-card { overflow: hidden; border: 1px solid #e6e9ed; border-radius: 12px; background: #fff; box-shadow: 0 6px 20px rgba(15, 23, 42, .035); }
.kol-card-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 20px 10px; }
.kol-card-heading h3 { margin: 0; color: #111827; font-size: 16px; line-height: 1.35; font-weight: 700; }
.kol-card-edit { display: inline-flex; align-items: center; gap: 5px; color: #64748b; font-size: 12px; font-weight: 600; text-decoration: none; }
.kol-card-edit:hover { color: #0f766e; }
.kol-basic-list { margin: 0; padding: 0 20px 14px; }
.kol-basic-row { display: grid; grid-template-columns: minmax(116px, .92fr) minmax(0, 1.45fr); align-items: center; min-height: 48px; border-bottom: 1px solid #eef1f4; }
.kol-basic-row:last-child { border-bottom: 0; }
.kol-basic-row dt { min-width: 0; display: flex; align-items: center; gap: 9px; margin: 0; color: #64748b; font-size: 12px; font-weight: 500; }
.kol-basic-row dd { min-width: 0; margin: 0; color: #202733; font-size: 13px; line-height: 1.45; overflow-wrap: anywhere; }
.kol-basic-icon { width: 17px; flex: 0 0 17px; display: inline-flex; align-items: center; justify-content: center; color: #475569; font-size: 14px; line-height: 1; }
.kol-basic-status { display: inline-flex; align-items: center; min-height: 26px; padding: 0 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
.kol-basic-status.is-active { background: #e9f7ec; color: #2f7d46; }
.kol-basic-status.is-inactive { background: #f1f5f9; color: #64748b; }
.kol-basic-row--notes { align-items: start; padding-top: 13px; padding-bottom: 8px; }
.kol-basic-row--notes dd { padding-bottom: 6px; line-height: 1.65; }
.kol-left-stack, .kol-right-stack { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.kol-social-card { overflow: hidden; border: 1px solid #e6e9ed; border-radius: 12px; background: #fff; box-shadow: 0 6px 20px rgba(15, 23, 42, .035); }
.kol-social-heading { padding-bottom: 8px; }
.kol-card-view-all { padding: 0; border: 0; background: transparent; color: #0f766e; font-size: 12px; font-weight: 700; cursor: pointer; }
.kol-card-view-all:disabled { cursor: default; opacity: 1; }
.kol-social-list { padding: 0 20px 10px; }
.kol-social-data-row { display: grid; grid-template-columns: 34px minmax(0, 1fr) 74px 68px; align-items: center; min-height: 52px; gap: 8px; border-bottom: 1px solid #eef1f4; color: inherit; text-decoration: none; }
.kol-social-data-row:last-child { border-bottom: 0; }
.kol-social-data-row[href]:hover { background: #fbfdfc; }
.kol-platform-mark { width: 26px; height: 26px; display: grid; place-items: center; border-radius: 7px; background: #111827; color: #fff; font-size: 10px; font-weight: 800; letter-spacing: -.02em; }
.kol-social-data-row.is-instagram .kol-platform-mark { background: linear-gradient(135deg, #7c3aed, #db2777 55%, #f59e0b); }
.kol-social-data-row.is-youtube .kol-platform-mark { background: #dc2626; font-size: 11px; }
.kol-social-data-row.is-tiktok .kol-platform-mark { background: #111827; font-size: 14px; }
.kol-social-data-row.is-twitter .kol-platform-mark { background: #050505; font-size: 14px; }
.kol-social-account { min-width: 0; display: flex; align-items: center; gap: 6px; }
.kol-social-account strong { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #26303d; font-size: 12px; font-weight: 600; }
.kol-social-account small { padding: 1px 5px; border-radius: 999px; background: #f1f5f9; color: #94a3b8; font-size: 10px; }
.kol-social-followers, .kol-social-engagement { display: flex; align-items: baseline; justify-content: flex-end; gap: 4px; white-space: nowrap; }
.kol-social-followers strong, .kol-social-engagement strong { color: #26303d; font-size: 12px; font-weight: 700; }
.kol-social-followers small, .kol-social-engagement small { color: #94a3b8; font-size: 10px; }
.kol-social-empty { padding: 8px 20px 20px; color: #94a3b8; font-size: 12px; }
@media (max-width: 980px) { .kol-primary-grid { grid-template-columns: 1fr; } }
@media (max-width: 520px) { .kol-social-data-row { grid-template-columns: 30px minmax(0, 1fr) 62px; } .kol-social-engagement { display: none; } }

.kol-opportunity-card { min-width: 0; overflow: hidden; border: 1px solid #e6e9ed; border-radius: 12px; background: #fff; box-shadow: 0 6px 20px rgba(15, 23, 42, .035); }
.kol-card-heading--opportunity { padding-bottom: 14px; }
.kol-card-view-all { color: #0f766e; font-size: 12px; font-weight: 700; text-decoration: none; }
.kol-card-view-all:hover { text-decoration: underline; }
.kol-opportunity-body { margin: 0 16px 16px; padding: 18px; border: 1px solid #e7ebef; border-radius: 10px; }
.kol-opportunity-title-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 16px; }
.kol-opportunity-title-row strong { color: #17202a; font-size: 15px; line-height: 1.4; }
.kol-opportunity-stage-pill, .kol-inline-stage { display: inline-flex; align-items: center; min-height: 25px; padding: 0 10px; border-radius: 999px; background: #fff3d8; color: #b36b00; font-size: 12px; font-weight: 700; white-space: nowrap; }
.kol-inline-stage { background: #e8f3ff; color: #2875b8; }
.kol-opportunity-facts { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 11px 26px; margin: 0; }
.kol-opportunity-facts div { display: grid; grid-template-columns: 78px minmax(0, 1fr); gap: 10px; align-items: baseline; min-width: 0; }
.kol-opportunity-facts dt { color: #718096; font-size: 12px; }
.kol-opportunity-facts dd { min-width: 0; margin: 0; color: #27313c; font-size: 13px; line-height: 1.45; overflow-wrap: anywhere; }
.kol-stage-timeline { display: grid; grid-template-columns: repeat(7, minmax(58px, 1fr)); margin-top: 22px; }
.kol-stage-step { min-width: 0; text-align: center; }
.kol-stage-track { position: relative; height: 24px; display: flex; align-items: center; justify-content: center; }
.kol-stage-dot { position: relative; z-index: 2; width: 22px; height: 22px; display: grid; place-items: center; border: 3px solid #d9dee5; border-radius: 50%; background: #fff; color: #fff; font-size: 10px; font-weight: 800; }
.kol-stage-line { position: absolute; z-index: 1; left: 50%; top: 11px; width: 100%; height: 2px; background: #e3e7ec; }
.kol-stage-step.is-done .kol-stage-dot { border-color: #2f8575; background: #2f8575; }
.kol-stage-step.is-done .kol-stage-line { background: #2f8575; }
.kol-stage-step.is-current .kol-stage-dot { border-color: #2f8575; }
.kol-stage-label { display: block; margin-top: 6px; color: #667085; font-size: 11px; white-space: nowrap; }
.kol-stage-step.is-done .kol-stage-label, .kol-stage-step.is-current .kol-stage-label { color: #344054; font-weight: 600; }
.kol-next-action { margin-top: 20px; padding: 13px 14px; border: 1px solid #b9ddcb; border-radius: 8px; background: #f5fbf7; color: #334a40; font-size: 12px; line-height: 1.6; }
.kol-next-action strong { display: block; margin-bottom: 2px; color: #2f7d62; }
.kol-opportunity-empty { margin: 0 16px 16px; padding: 36px 18px; border: 1px dashed #d9dee5; border-radius: 10px; color: #98a2b3; font-size: 13px; text-align: center; }
@media (max-width: 760px) { .kol-opportunity-facts { grid-template-columns: 1fr; } .kol-stage-timeline { overflow-x: auto; grid-template-columns: repeat(7, minmax(76px, 1fr)); padding-bottom: 6px; } }

.kol-communications-card { min-width: 0; overflow: hidden; border: 1px solid #e6e9ed; border-radius: 12px; background: #fff; box-shadow: 0 6px 20px rgba(15, 23, 42, .035); }
.kol-card-heading--communications { padding-bottom: 8px; }
.kol-communications-list { padding: 0 18px 10px; }
.kol-communication-row { display: grid; grid-template-columns: 34px minmax(0, 1fr) 86px; gap: 10px; align-items: start; padding: 14px 0; border-bottom: 1px solid #eef1f4; }
.kol-communication-row:last-child { border-bottom: 0; }
.kol-communication-mark { width: 26px; height: 26px; display: grid; place-items: center; border-radius: 7px; background: #e8f3ff; color: #2875b8; font-size: 10px; font-weight: 800; }
.kol-communication-mark.is-instagram_dm { background: linear-gradient(135deg, #7c3aed, #db2777 55%, #f59e0b); color: #fff; }
.kol-communication-mark.is-email { background: #dff1fb; color: #2875b8; font-size: 13px; }
.kol-communication-mark.is-x_dm { background: #111827; color: #fff; font-size: 13px; }
.kol-communication-mark.is-phone { background: #e9f7ec; color: #2f7d46; font-size: 12px; }
.kol-communication-main { min-width: 0; }
.kol-communication-title { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; }
.kol-communication-title strong { color: #202733; font-size: 13px; }
.kol-communication-title time { flex: 0 0 auto; color: #7b8794; font-size: 11px; white-space: nowrap; }
.kol-communication-main p { margin: 5px 0 0; color: #5f6b78; font-size: 12px; line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.kol-communication-meta { display: flex; flex-wrap: wrap; gap: 6px 12px; margin-top: 7px; color: #94a3b8; font-size: 10px; }
.kol-communication-owner { padding-top: 2px; color: #475569; font-size: 11px; text-align: right; white-space: nowrap; }
.kol-communications-empty { margin: 0 18px 18px; padding: 30px 18px; border: 1px dashed #d9dee5; border-radius: 10px; color: #98a2b3; font-size: 13px; text-align: center; }
@media (max-width: 760px) { .kol-communication-row { grid-template-columns: 30px minmax(0, 1fr); } .kol-communication-owner { grid-column: 2; text-align: left; } .kol-communication-title { align-items: flex-start; flex-direction: column; gap: 2px; } }

.kol-history-card { min-width: 0; overflow: hidden; margin-bottom: 16px; border: 1px solid #e6e9ed; border-radius: 12px; background: #fff; box-shadow: 0 6px 20px rgba(15, 23, 42, .035); }
.kol-card-heading--history { padding-bottom: 14px; }
.kol-history-scroll { overflow-x: auto; padding: 0 16px 16px; }
.kol-history-table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 12px; }
.kol-history-table th { padding: 10px 12px; border-bottom: 1px solid #e8edf1; background: #f8fafb; color: #667085; font-size: 11px; font-weight: 700; text-align: left; white-space: nowrap; }
.kol-history-table td { padding: 14px 12px; border-bottom: 1px solid #eef1f4; color: #344054; vertical-align: middle; }
.kol-history-table tbody tr:last-child td { border-bottom: 0; }
.kol-history-table tbody tr:hover { background: #fbfdfc; }
.kol-history-table td strong { color: #1f2937; font-size: 12px; font-weight: 700; }
.kol-history-stage { display: inline-flex; align-items: center; min-height: 24px; padding: 0 8px; border-radius: 999px; background: #eef6ff; color: #2d6fa8; font-size: 11px; font-weight: 700; }
.kol-history-status { display: inline-flex; align-items: center; min-height: 24px; padding: 0 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.kol-history-status.is-success { background: #e9f7ec; color: #2f7d46; }
.kol-history-status.is-failed { background: #fff0f0; color: #b42318; }
.kol-history-status.is-paused { background: #fff6df; color: #a15c00; }
.kol-history-status.is-neutral { background: #f1f5f9; color: #64748b; }
.kol-history-action { color: #0f766e; font-size: 12px; font-weight: 700; text-decoration: none; }
.kol-history-source { display: inline-flex; align-items: center; min-height: 24px; padding: 0 8px; border-radius: 999px; background: #f1f5f9; color: #64748b; font-size: 11px; font-weight: 600; white-space: nowrap; }
.kol-history-note { display: block; margin-top: 4px; color: #94a3b8; font-size: 11px; font-weight: 400; line-height: 1.45; }
.kol-history-status.is-legacy { background: #f1f5f9; color: #64748b; }
.kol-history-action:hover { text-decoration: underline; }
.kol-history-empty { padding: 32px 20px 36px; color: #98a2b3; font-size: 13px; text-align: center; }

.kol-supplemental { margin-top: 16px; padding-top: 2px; }
.kol-supplemental-heading { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
.kol-supplemental-heading h3 { margin: 0; color: #111827; font-size: 16px; font-weight: 700; }
.kol-supplemental-heading p { margin: 4px 0 0; color: #94a3b8; font-size: 11px; }
.kol-supplemental-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.kol-supplemental-card { min-width: 0; padding: 18px 20px; border: 1px solid #e6e9ed; border-radius: 12px; background: #fff; box-shadow: 0 4px 16px rgba(15, 23, 42, .025); }
.kol-supplemental-card h4 { margin: 0 0 14px; color: #334155; font-size: 13px; font-weight: 700; }
.kol-supplemental-card h4 span { margin-left: 6px; color: #94a3b8; font-size: 11px; font-weight: 500; }
.kol-supplemental-card--wide { grid-column: span 2; }
.kol-supplemental-card .detail-list div { padding: 4px 0; border-bottom: 1px solid #f4f6f8; }
.kol-supplemental-card .detail-list div:last-child { border-bottom: 0; }
.kol-supplemental-card .detail-list dt { min-width: 76px; }
.kol-supplemental-card .detail-list dd { min-width: 0; overflow-wrap: anywhere; font-size: 12px; color: #334155; }
@media (max-width: 1080px) { .kol-supplemental-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 680px) { .kol-supplemental-grid { grid-template-columns: 1fr; } .kol-supplemental-card--wide { grid-column: auto; } }

.detail-list { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }
.detail-list div { display: flex; gap: 8px; }
.detail-list dt { font-size: 12px; color: #64748b; min-width: 80px; }
.detail-list dd { font-size: 14px; margin: 0; }
.historical-count { font-weight: 600; }
.empty-state { font-size: 13px; color: #64748b; padding: 8px 0; }
.social-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.social-row:last-child { border-bottom: none; }
.social-platform { font-weight: 600; }
.badge-inactive { margin-left: 4px; padding: 1px 6px; background: #fee2e2; color: #991b1b; border-radius: 10px; font-size: 11px; font-weight: normal; }
.profile-link { color: #0f766e; text-decoration: underline; }
.followers { color: #374151; }
.measured-at { color: #94a3b8; }
.perf-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; padding: 6px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.perf-row:last-child { border-bottom: none; }
.perf-note { color: #64748b; }
.notes-text { white-space: pre-wrap; font-size: 14px; }
.planned-modules { opacity: 0.7; }
.planned-note { font-size: 13px; color: #64748b; }
.btn-danger { color: #dc2626; border-color: #dc2626; }
/* Region G visual/responsive closure */
.kol-basic-row--notes dd,
.kol-opportunity-title-row strong,
.kol-opportunity-facts dd,
.kol-next-action span,
.kol-history-table td,
.kol-supplemental-card .detail-list dd {
  overflow-wrap: anywhere;
  word-break: break-word;
}
.kol-basic-row--notes dd { white-space: pre-wrap; }
.kol-sales-history-list { display: flex; flex-direction: column; }
.kol-sales-history-row { display: grid; grid-template-columns: 72px 110px 120px minmax(0, 1fr); gap: 12px; padding: 9px 0; border-bottom: 1px solid #f1f5f9; align-items: start; font-size: 12px; }
.kol-sales-history-row:last-child { border-bottom: 0; }
.kol-sales-history-row strong { color: #1f2937; }
.kol-sales-history-row span { min-width: 0; color: #64748b; overflow-wrap: anywhere; }
.kol-supplemental-empty { color: #94a3b8; font-size: 12px; }
@media (max-width: 1080px) {
  .kol-profile-hero { gap: 20px; }
  .kol-hero-metrics { padding-left: 0; }
}
@media (max-width: 760px) {
  .kol-detail-page { padding-bottom: 20px; }
  .kol-detail-toolbar__actions { width: 100%; gap: 8px; }
  .kol-action-button { min-height: 36px; padding: 0 12px; }
  .kol-profile-name-row h2 { font-size: 21px; }
  .kol-profile-meta { gap: 8px 14px; }
  .kol-card-heading { padding-inline: 16px; }
  .kol-basic-list, .kol-social-list { padding-inline: 16px; }
  .kol-opportunity-body { margin-inline: 12px; padding: 15px; }
  .kol-communications-list { padding-inline: 14px; }
  .kol-history-scroll { padding-inline: 12px; }
  .kol-sales-history-row { grid-template-columns: 60px 94px minmax(0, 1fr); }
  .kol-sales-history-row span:last-child { grid-column: 1 / -1; }
}
@media (max-width: 520px) {
  .kol-detail-toolbar__trail { display: none; }
  .kol-detail-toolbar__actions { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 3px; }
  .kol-action-button { flex: 0 0 auto; }
  .kol-profile-hero { padding: 16px; }
  .kol-profile-identity { gap: 14px; }
  .kol-profile-meta { flex-direction: column; gap: 4px; }
  .kol-hero-metrics { gap: 10px; }
  .kol-stat { padding-left: 10px; }
  .kol-stat strong { font-size: 20px; }
  .kol-basic-row { grid-template-columns: 104px minmax(0, 1fr); }
  .kol-opportunity-title-row { align-items: flex-start; flex-direction: column; }
  .kol-stage-label { font-size: 10px; }
  .kol-sales-history-row { grid-template-columns: 1fr; gap: 4px; }
  .kol-sales-history-row span:last-child { grid-column: auto; }
}


.kol-collaboration-card { min-width: 0; overflow: hidden; border: 1px solid #e6e9ed; border-radius: 12px; background: #fff; box-shadow: 0 6px 20px rgba(15, 23, 42, .035); }
.kol-collaboration-detail { padding: 16px 18px 18px; }
.kol-collaboration-title-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.kol-collaboration-title-row strong { color: #1f2937; font-size: 15px; }
.kol-collaboration-products { display: flex; gap: 6px; margin-top: 12px; color: #475467; font-size: 12px; line-height: 1.6; }
</style>
