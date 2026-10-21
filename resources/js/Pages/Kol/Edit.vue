<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import axios from 'axios'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import SearchableSelect from '../../Components/SearchableSelect.vue'
import SkuCombobox from '../../Components/Products/SkuCombobox.vue'

type OwnerOption = { value: number; label: string }

const countryOptions = [
  { value: 'US', label: 'United States' },
  { value: 'GB', label: 'United Kingdom' },
  { value: 'DE', label: 'Germany' },
  { value: 'FR', label: 'France' },
  { value: 'CA', label: 'Canada' },
  { value: 'EU', label: 'Europe' },
  { value: 'AU', label: 'Australia' },
  { value: 'ASIA', label: '东南亚' },
  { value: 'PH', label: 'Philippines' },
  { value: 'OTHER', label: '其他' },
]
const languageOptions = [
  { value: 'en', label: 'English' },
  { value: 'es', label: 'Spanish' },
  { value: 'de', label: 'German' },
  { value: 'fr', label: 'French' },
  { value: 'it', label: 'Italian' },
  { value: 'zh', label: 'Chinese' },
  { value: 'other', label: '其他' },
]
type AffiliateCoupon = { coupon_id: number | null; code: string | null; provider_status: string | null; provisioning_status: string | null; sync_status: string | null }
type AffiliateAccount = {
  connection_key: string
  affiliate_id: number | null
  wordpress_user_id: number | null
  wordpress_user_login: string | null
  wordpress_user_email: string | null
  provider_status: string | null
  provisioning_status: string | null
  sync_status: string | null
  reconciliation_status: string | null
  last_successful_sync_at: string | null
  last_error_code: string | null
  coupon: AffiliateCoupon | null
}

type SocialMetric = { followers: number | null; raw_followers_value: string | null; measured_at: string | null }
type SocialAccount = { id: number; platform: string; handle: string | null; profile_url: string | null; is_active: boolean; latest_metric: SocialMetric | null }
type SalesPerformanceEvent = { id: number; order_count: number; recorded_at: string | null; source: string | null; note: string | null }
type OpportunityRecord = { id: number; title: string; cooperation_type: string | null; target_products: string[]; stage: string; owner_id: number | null; owner: { id: number; name: string } | null; estimated_cost: number | null; currency: string; expected_publish_at: string | null; next_follow_up_at: string | null; next_action: string | null; status: string; notes: string | null }
type CommunicationRecord = { id: number; opportunity_id: number | null; channel: string; direction: string; summary: string; contacted_at: string | null; next_action: string | null; next_follow_up_at: string | null; creator: { id: number; name: string } | null; opportunity: { id: number; title: string } | null }
type CollaborationProduct = { id?: number; product_id: number | null; sku: string; name?: string | null; quantity: number }
type FulfillmentOrder = { id:number; erp_order_id:string|null; order_status:string; shipment:{ shipment_status:string; warehouse_name:string|null; carrier:string|null; service:string|null; tracking_number:string|null; shipped_at:string|null }|null; manual_sync_status:string|null }
type CollaborationRecord = { id: number; opportunity_id: number | null; owner_id: number | null; order_id:number|null; owner: { id: number; name: string } | null; title: string | null; post_url: string | null; cooperation_type: string | null; status: string; agreement_amount: number | null; currency: string; agreed_at: string | null; planned_publish_at: string | null; deliverables: string | null; fulfillment_required: boolean; notes: string | null; products: CollaborationProduct[]; fulfillment_order:FulfillmentOrder|null }
type KolDetail = {
  id: number; display_name: string; avatar_url?: string | null; avatar_source?: string | null; avatar_updated_at?: string | null; legal_name: string | null; country_code: string | null; language: string | null
  status: string; relationship_stage: string; cooperation_type: string | null; owner_id: number | null
  approval_manager_id?: number | null; finance_owner_id?: number | null
  joined_at: string | null; notes: string | null
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
  opportunities?: OpportunityRecord[]
  communications?: CommunicationRecord[]
  collaborations?: CollaborationRecord[]
}

const props = withDefaults(defineProps<{
  kol: KolDetail
  affiliateAccount?: AffiliateAccount | null
  ownerOptions: OwnerOption[]
  approvalManagerOptions?: OwnerOption[]
  financeOwnerOptions?: OwnerOption[]
  canManageViews: boolean
  canCreateOrders?: boolean
}>(), { affiliateAccount: null, approvalManagerOptions: () => [], financeOwnerOptions: () => [] })

const affiliateAccountState = ref<AffiliateAccount | null>(props.affiliateAccount ?? null)
const accountIsBound = computed(() => Boolean(affiliateAccountState.value?.affiliate_id && affiliateAccountState.value?.wordpress_user_id))
const accountProvisioning = ref(false)
const accountError = ref('')
const accountMessage = ref('')
const accountPassword = ref('')
const accountPasswordConfirmation = ref('')
const accountCouponCode = ref('')
const passwordResetOpen = ref(false)
const passwordResetting = ref(false)
const passwordResetError = ref('')
const passwordResetMessage = ref('')
const newWordpressPassword = ref('')
const newWordpressPasswordConfirmation = ref('')
const accountEmail = computed(() => props.kol.contact_email?.trim() ?? '')

const apiErrorMessage = (error: any, fallback: string) => {
  const errors = error?.response?.data?.errors
  if (errors && typeof errors === 'object') return Object.values(errors).flat().join('；')
  return error?.response?.data?.message || fallback
}

const validWordpressPassword = (value: string) => value.length >= 12 && value.length <= 128 && /[A-Za-z]/.test(value) && /[0-9]/.test(value)

const provisionAffiliateAccount = async () => {
  accountError.value = ''
  accountMessage.value = ''
  if (!accountEmail.value) { accountError.value = '请先保存有效联系邮箱，再创建 WordPress / Affiliate 账号。'; return }
  if (!validWordpressPassword(accountPassword.value)) { accountError.value = 'Password 需为 12–128 位，并至少包含 1 个英文字母和 1 个数字。'; return }
  if (accountPassword.value !== accountPasswordConfirmation.value) { accountError.value = '两次输入的 Password 不一致。'; return }
  if (!accountCouponCode.value.trim()) { accountError.value = 'Code 必填。'; return }
  accountProvisioning.value = true
  try {
    const response = await axios.post(`/kol/library/${props.kol.id}/affiliate-account`, {
      password: accountPassword.value,
      password_confirmation: accountPasswordConfirmation.value,
      affiliate_coupon_code: accountCouponCode.value.trim(),
    })
    affiliateAccountState.value = response.data.affiliate_account as AffiliateAccount
    accountMessage.value = response.data.message || '账号操作完成。'
    if (response.data.success) {
      accountPassword.value = ''
      accountPasswordConfirmation.value = ''
      accountCouponCode.value = ''
    }
  } catch (error: any) {
    accountError.value = apiErrorMessage(error, 'WordPress / Affiliate 账号创建失败。')
  } finally { accountProvisioning.value = false }
}

const updateWordpressPassword = async () => {
  passwordResetError.value = ''
  passwordResetMessage.value = ''
  if (!validWordpressPassword(newWordpressPassword.value)) { passwordResetError.value = '新 Password 需为 12–128 位，并至少包含 1 个英文字母和 1 个数字。'; return }
  if (newWordpressPassword.value !== newWordpressPasswordConfirmation.value) { passwordResetError.value = '两次输入的新 Password 不一致。'; return }
  passwordResetting.value = true
  try {
    const response = await axios.post(`/kol/library/${props.kol.id}/wordpress-password`, {
      password: newWordpressPassword.value,
      password_confirmation: newWordpressPasswordConfirmation.value,
    })
    newWordpressPassword.value = ''
    newWordpressPasswordConfirmation.value = ''
    passwordResetMessage.value = response.data.message || 'WordPress 密码已更新。'
    passwordResetOpen.value = false
  } catch (error: any) {
    passwordResetError.value = apiErrorMessage(error, 'WordPress 密码更新失败。')
  } finally { passwordResetting.value = false }
}

const opportunityRows = ref<OpportunityRecord[]>([...(props.kol.opportunities ?? [])])
const opportunityModalOpen = ref(false)
const opportunitySaving = ref(false)
const opportunityError = ref('')
const editingOpportunityId = ref<number | null>(null)
const emptyOpportunityForm = () => ({
  title: '', cooperation_type: '免费发帖', target_sku: '', stage: 'pending_contact',
  owner_id: props.kol.owner_id ? String(props.kol.owner_id) : '', estimated_cost: '', currency: 'USD',
  expected_publish_at: '', next_follow_up_at: '', next_action: '', status: 'active', notes: '',
})
const opportunityForm = ref(emptyOpportunityForm())
const opportunityStageOptions = [
  ['pending_contact', '待联系'], ['contacted', '已联系'], ['communicating', '沟通中'], ['confirmed', '已确认'],
  ['waiting_sample', '等待寄样'], ['success', '成功'], ['failed', '失败'],
] as const
const opportunityStatusOptions = [
  ['active', '进行中'], ['completed', '已完成'], ['success', '成功'], ['failed', '失败'], ['paused', '暂停'], ['cancelled', '已取消'],
] as const
const opportunityStageLabel = (value: string) => opportunityStageOptions.find(([key]) => key === value)?.[1] ?? value
const opportunityStatusLabel = (value: string) => opportunityStatusOptions.find(([key]) => key === value)?.[1] ?? value

const openNewOpportunity = () => {
  editingOpportunityId.value = null
  opportunityForm.value = emptyOpportunityForm()
  opportunityError.value = ''
  opportunityModalOpen.value = true
}
const openEditOpportunity = (item: OpportunityRecord) => {
  editingOpportunityId.value = item.id
  opportunityForm.value = {
    title: item.title, cooperation_type: item.cooperation_type ?? '免费发帖', target_sku: item.target_products[0] ?? '', stage: item.stage,
    owner_id: item.owner_id ? String(item.owner_id) : '', estimated_cost: item.estimated_cost !== null ? String(item.estimated_cost) : '', currency: item.currency || 'USD',
    expected_publish_at: item.expected_publish_at ?? '', next_follow_up_at: item.next_follow_up_at ?? '', next_action: item.next_action ?? '', status: item.status, notes: item.notes ?? '',
  }
  opportunityError.value = ''
  opportunityModalOpen.value = true
}
const saveOpportunity = async () => {
  opportunitySaving.value = true
  opportunityError.value = ''
  const payload = {
    title: opportunityForm.value.title,
    cooperation_type: opportunityForm.value.cooperation_type || null,
    target_products: opportunityForm.value.target_sku ? [opportunityForm.value.target_sku] : [],
    stage: opportunityForm.value.stage,
    owner_id: opportunityForm.value.owner_id ? Number(opportunityForm.value.owner_id) : null,
    estimated_cost: opportunityForm.value.estimated_cost !== '' ? Number(opportunityForm.value.estimated_cost) : null,
    currency: opportunityForm.value.currency.toUpperCase(),
    expected_publish_at: opportunityForm.value.expected_publish_at || null,
    next_follow_up_at: opportunityForm.value.next_follow_up_at || null,
    next_action: opportunityForm.value.next_action || null,
    status: opportunityForm.value.status,
    notes: opportunityForm.value.notes || null,
  }
  try {
    const url = editingOpportunityId.value
      ? `/kol/library/${props.kol.id}/opportunities/${editingOpportunityId.value}`
      : `/kol/library/${props.kol.id}/opportunities`
    const response = editingOpportunityId.value ? await axios.patch(url, payload) : await axios.post(url, payload)
    const saved = response.data.opportunity as OpportunityRecord
    const index = opportunityRows.value.findIndex(item => item.id === saved.id)
    if (index >= 0) opportunityRows.value.splice(index, 1, saved)
    else opportunityRows.value.unshift(saved)
    opportunityModalOpen.value = false
  } catch (error: any) {
    const errors = error?.response?.data?.errors
    opportunityError.value = errors ? Object.values(errors).flat().join('；') : (error?.response?.data?.message || '合作机会保存失败，请检查填写内容')
  } finally {
    opportunitySaving.value = false
  }
}

const collaborationRows = ref<CollaborationRecord[]>([...(props.kol.collaborations ?? [])])
const collaborationModalOpen = ref(false)
const collaborationSaving = ref(false)
const collaborationError = ref('')
const editingCollaborationId = ref<number | null>(null)
const emptyCollaborationForm = () => ({
  opportunity_id: '', post_url: '', cooperation_type: '免费发帖', status: 'draft', owner_id: props.kol.owner_id ? String(props.kol.owner_id) : '', agreement_amount: '', currency: 'USD', agreed_at: '', planned_publish_at: '', deliverables: '', fulfillment_required: false, notes: '',
  products: [{ product_id: null as number | null, sku: '', quantity: 1 }],
})
const collaborationForm = ref(emptyCollaborationForm())
const collaborationStatusOptions = [['draft','草稿'],['confirmed','已确认'],['in_progress','进行中'],['completed','已完成'],['cancelled','已取消']] as const
const collaborationStatusLabel = (v:string) => collaborationStatusOptions.find(([key]) => key === v)?.[1] ?? v
const openNewCollaboration = () => { editingCollaborationId.value=null; collaborationForm.value=emptyCollaborationForm(); collaborationError.value=''; collaborationModalOpen.value=true }
const openEditCollaboration = (item: CollaborationRecord) => {
  editingCollaborationId.value=item.id
  collaborationForm.value={ opportunity_id:item.opportunity_id ? String(item.opportunity_id) : '', post_url:item.post_url ?? '', cooperation_type:item.cooperation_type ?? '免费发帖', status:item.status, owner_id:item.owner_id ? String(item.owner_id) : '', agreement_amount:item.agreement_amount === null ? '' : String(item.agreement_amount), currency:item.currency || 'USD', agreed_at:item.agreed_at ?? '', planned_publish_at:item.planned_publish_at ?? '', deliverables:item.deliverables ?? '', fulfillment_required:item.fulfillment_required, notes:item.notes ?? '', products:item.products.length ? item.products.map(p=>({product_id:p.product_id,sku:p.sku,quantity:p.quantity})) : [{product_id:null,sku:'',quantity:1}] }
  collaborationError.value=''; collaborationModalOpen.value=true
}
const addCollaborationProduct = () => collaborationForm.value.products.push({ product_id:null, sku:'', quantity:1 })
const removeCollaborationProduct = (i:number) => { if (collaborationForm.value.products.length > 1) collaborationForm.value.products.splice(i,1) }
const saveCollaboration = async () => {
  collaborationSaving.value=true; collaborationError.value=''
  const payload={ opportunity_id: collaborationForm.value.opportunity_id ? Number(collaborationForm.value.opportunity_id) : null, owner_id: collaborationForm.value.owner_id ? Number(collaborationForm.value.owner_id) : null, post_url:collaborationForm.value.post_url || null, cooperation_type:collaborationForm.value.cooperation_type || null, status:collaborationForm.value.status, agreement_amount:collaborationForm.value.agreement_amount === '' ? null : Number(collaborationForm.value.agreement_amount), currency:collaborationForm.value.currency.toUpperCase(), agreed_at:collaborationForm.value.agreed_at || null, planned_publish_at:collaborationForm.value.planned_publish_at || null, deliverables:collaborationForm.value.deliverables || null, fulfillment_required:collaborationForm.value.fulfillment_required, notes:collaborationForm.value.notes || null, products:collaborationForm.value.products.filter(p=>p.product_id && p.sku).map(p=>({product_id:p.product_id,sku:p.sku,quantity:Number(p.quantity)||1})) }
  try { const url=editingCollaborationId.value ? `/kol/library/${props.kol.id}/collaborations/${editingCollaborationId.value}` : `/kol/library/${props.kol.id}/collaborations`; const response=editingCollaborationId.value ? await axios.patch(url,payload) : await axios.post(url,payload); const saved=response.data.collaboration as CollaborationRecord; const i=collaborationRows.value.findIndex(x=>x.id===saved.id); if(i>=0) collaborationRows.value.splice(i,1,saved); else collaborationRows.value.unshift(saved); collaborationModalOpen.value=false }
  catch(error:any){ const errors=error?.response?.data?.errors; collaborationError.value=errors ? Object.values(errors).flat().join('；') : (error?.response?.data?.message || '正式合作保存失败') }
  finally{ collaborationSaving.value=false }
}

const fulfillmentCreatingId = ref<number | null>(null)
const createFulfillmentOrder = async (item: CollaborationRecord) => {
  if (!item.fulfillment_required || item.fulfillment_order || fulfillmentCreatingId.value) return
  fulfillmentCreatingId.value = item.id
  collaborationError.value = ''
  try {
    const response = await axios.post(`/kol/library/${props.kol.id}/collaborations/${item.id}/fulfillment-order`)
    item.order_id = response.data.order.id
    item.fulfillment_order = response.data.order as FulfillmentOrder
  } catch (error:any) {
    collaborationError.value = error?.response?.data?.message || '履约订单创建失败'
  } finally { fulfillmentCreatingId.value = null }
}

const convertOpportunity = async (item: OpportunityRecord) => {
  if (collaborationRows.value.some(c=>c.opportunity_id===item.id)) return
  try { const response=await axios.post(`/kol/library/${props.kol.id}/opportunities/${item.id}/convert-to-collaboration`); collaborationRows.value.unshift(response.data.collaboration as CollaborationRecord); item.stage='confirmed'; item.status='completed' }
  catch(error:any){ opportunityError.value=error?.response?.data?.message || '转为正式合作失败' }
}

const communicationRows = ref<CommunicationRecord[]>([...(props.kol.communications ?? [])])
const communicationModalOpen = ref(false)
const communicationSaving = ref(false)
const communicationError = ref('')
const editingCommunicationId = ref<number | null>(null)
const emptyCommunicationForm = () => ({ opportunity_id: '', channel: 'email', direction: 'outbound', summary: '', contacted_at: new Date().toISOString().slice(0,16), next_action: '', next_follow_up_at: '' })
const communicationForm = ref(emptyCommunicationForm())
const communicationChannelOptions = [['email','Email'],['instagram_dm','Instagram'],['x_dm','X'],['whatsapp','WhatsApp'],['phone','Phone'],['other','其他']] as const
const communicationDirectionLabel = (value: string) => value === 'inbound' ? '对方联系我方' : '我方联系对方'
const communicationChannelLabel = (value: string) => communicationChannelOptions.find(([key]) => key === value)?.[1] ?? value
const openNewCommunication = () => { editingCommunicationId.value = null; communicationForm.value = emptyCommunicationForm(); communicationError.value = ''; communicationModalOpen.value = true }
const openEditCommunication = (item: CommunicationRecord) => { editingCommunicationId.value = item.id; communicationForm.value = { opportunity_id: item.opportunity_id ? String(item.opportunity_id) : '', channel: item.channel, direction: item.direction || 'outbound', summary: item.summary, contacted_at: item.contacted_at ?? '', next_action: item.next_action ?? '', next_follow_up_at: item.next_follow_up_at ?? '' }; communicationError.value=''; communicationModalOpen.value=true }
const saveCommunication = async () => {
  communicationSaving.value = true; communicationError.value = ''
  const payload = { opportunity_id: communicationForm.value.opportunity_id ? Number(communicationForm.value.opportunity_id) : null, channel: communicationForm.value.channel, direction: communicationForm.value.direction, summary: communicationForm.value.summary, contacted_at: communicationForm.value.contacted_at, next_action: communicationForm.value.next_action || null, next_follow_up_at: communicationForm.value.next_follow_up_at || null }
  try {
    const url = editingCommunicationId.value ? `/kol/library/${props.kol.id}/communications/${editingCommunicationId.value}` : `/kol/library/${props.kol.id}/communications`
    const response = editingCommunicationId.value ? await axios.patch(url, payload) : await axios.post(url, payload)
    const saved = response.data.communication as CommunicationRecord
    const index = communicationRows.value.findIndex(item => item.id === saved.id)
    if (index >= 0) communicationRows.value.splice(index,1,saved); else communicationRows.value.unshift(saved)
    communicationModalOpen.value = false
  } catch (error: any) { const errors = error?.response?.data?.errors; communicationError.value = errors ? Object.values(errors).flat().join('；') : (error?.response?.data?.message || '沟通记录保存失败') }
  finally { communicationSaving.value = false }
}

const avatarUrl = ref(props.kol.avatar_url)
const avatarPasteActive = ref(false)
const avatarUploading = ref(false)
const avatarError = ref('')
const avatarInput = ref<HTMLInputElement | null>(null)

const uploadAvatar = async (file: File) => {
  if (!file.type.startsWith('image/')) {
    avatarError.value = '请粘贴或选择图片文件'
    return
  }
  if (file.size > 5 * 1024 * 1024) {
    avatarError.value = '头像图片不能超过 5MB'
    return
  }

  avatarUploading.value = true
  avatarError.value = ''
  const data = new FormData()
  data.append('avatar', file)

  try {
    const response = await axios.post(`/kol/library/${props.kol.id}/avatar`, data, {
      headers: { 'Content-Type': 'multipart/form-data', 'X-Requested-With': 'XMLHttpRequest' },
    })
    avatarUrl.value = response.data.avatar_url
    avatarPasteActive.value = false
  } catch (error: any) {
    avatarError.value = error?.response?.data?.message || '头像上传失败，请重试'
  } finally {
    avatarUploading.value = false
  }
}

const handleAvatarPaste = (event: ClipboardEvent) => {
  const item = Array.from(event.clipboardData?.items ?? []).find(entry => entry.type.startsWith('image/'))
  const file = item?.getAsFile()
  if (file) {
    event.preventDefault()
    void uploadAvatar(file)
  } else {
    avatarError.value = '剪贴板中没有图片，请先复制头像图片'
  }
}

const handleAvatarFile = (event: Event) => {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (file) void uploadAvatar(file)
}

const handleAvatarDrop = (event: DragEvent) => {
  const file = event.dataTransfer?.files?.[0]
  if (file) void uploadAvatar(file)
}

const form = useForm({
  display_name: props.kol.display_name,
  country_code: props.kol.country_code ?? 'US',
  language: props.kol.language ?? 'en',
  status: props.kol.status,
  owner_id: props.kol.owner_id ? String(props.kol.owner_id) : '',
  approval_manager_id: props.kol.approval_manager_id ? String(props.kol.approval_manager_id) : '',
  finance_owner_id: props.kol.finance_owner_id ? String(props.kol.finance_owner_id) : '',
  relationship_stage: props.kol.relationship_stage,
  cooperation_type: ['免费发帖', '长期合作', '付费发帖'].includes(props.kol.cooperation_type ?? '') ? props.kol.cooperation_type! : '免费发帖',
  joined_at: props.kol.joined_at ?? '',
  notes: props.kol.notes ?? '',
  contact_email: props.kol.contact_email ?? '',
  contact_phone: props.kol.contact_phone ?? '',
  contact_whatsapp: props.kol.contact_whatsapp ?? '',
  other_contact: props.kol.other_contact ?? '',
  preferred_contact_method: (props.kol.preferred_contact_method || 'email') as 'phone' | 'email' | 'whatsapp' | 'other',
  tax_id: props.kol.tax_id ?? '',
  payout_method: (props.kol.payout_method ?? '') as '' | 'paypal' | 'bank' | 'other',
  payout_account: props.kol.payout_account ?? '',
  shipping_recipient_name: props.kol.shipping_recipient_name ?? '',
  shipping_country: props.kol.shipping_country ?? '',
  shipping_province_state: props.kol.shipping_province_state ?? '',
  shipping_city: props.kol.shipping_city ?? '',
  shipping_postal_code: props.kol.shipping_postal_code ?? '',
  shipping_address_line_1: props.kol.shipping_address_line_1 ?? '',
  shipping_address_line_2: props.kol.shipping_address_line_2 ?? '',
  level: (props.kol.level ?? '') as '' | '头部' | '腰部' | '尾部' | '素人',
  gender: (props.kol.gender ?? '') as '' | '男' | '女' | '女同' | '男同' | '其他',
  categories: (props.kol.categories ?? [])[0] ?? '',
  products: (props.kol.products ?? [])[0] ?? '',
  content_format: (props.kol.content_format ?? '') as '' | '图文' | '短视频' | '直播' | '图文+视频' | '混合',
  product_quantity: props.kol.product_quantity !== null ? String(props.kol.product_quantity) : '',
  internal_contact: props.kol.internal_contact ?? '',
  mcn_agency: props.kol.mcn_agency ?? '',
  is_mcn_managed: props.kol.is_mcn_managed,
  social_accounts: (() => {
    const existing = props.kol.social_accounts.map(sa => ({
      id: sa.id as number | undefined,
      platform: sa.platform,
      handle: sa.handle ?? '',
      profile_url: sa.profile_url ?? '',
      is_active: sa.is_active,
      followers: sa.latest_metric?.followers !== null && sa.latest_metric?.followers !== undefined ? String(sa.latest_metric.followers) : '',
      raw_followers_value: sa.latest_metric?.raw_followers_value ?? '',
    }))
    const defaults = ['instagram', 'twitter']
      .filter(platform => !existing.some(sa => sa.platform === platform))
      .map(platform => ({ id: undefined, platform, handle: '', profile_url: '', is_active: true, followers: '', raw_followers_value: '' }))
    return [...defaults, ...existing]
  })(),
  sales_performance_entries: [] as Array<{
    order_count: string
    recorded_at: string
    source: string
    note: string
  }>,
})

const addSocialAccount = () => {
  form.social_accounts.push({ id: undefined, platform: 'instagram', handle: '', profile_url: '', is_active: true, followers: '', raw_followers_value: '' })
}
const removeSocialAccount = (index: number) => {
  form.social_accounts.splice(index, 1)
}

const addSalesPerformance = () => {
  form.sales_performance_entries.push({ order_count: '', recorded_at: new Date().toISOString().slice(0, 10), source: '', note: '' })
}
const removeSalesPerformance = (index: number) => {
  form.sales_performance_entries.splice(index, 1)
}

const submit = () => {
  form.transform(data => ({
    ...data,
    owner_id: data.owner_id ? Number(data.owner_id) : null,
    approval_manager_id: data.approval_manager_id ? Number(data.approval_manager_id) : null,
    finance_owner_id: data.finance_owner_id ? Number(data.finance_owner_id) : null,
    level: data.level || null,
    content_format: data.content_format || null,
    product_quantity: data.product_quantity !== '' ? Number(data.product_quantity) : null,
    gender: data.gender || null,
    categories: data.categories ? [data.categories] : [],
    products: data.products ? [data.products] : [],
    social_accounts: data.social_accounts.map(sa => ({
      ...sa,
      followers: sa.followers !== '' ? Number(sa.followers) : null,
    })),
    sales_performance_entries: data.sales_performance_entries
      .filter(e => e.order_count !== '')
      .map(e => ({ ...e, order_count: Number(e.order_count) })),
  })).patch(`/kol/library/${props.kol.id}`)
}

const platformOptions = [
  { value: 'instagram', label: 'Instagram' },
  { value: 'tiktok', label: 'TikTok' },
  { value: 'youtube', label: 'YouTube' },
  { value: 'xiaohongshu', label: '小红书' },
  { value: 'weibo', label: '微博' },
  { value: 'wechat', label: '微信' },
  { value: 'twitter', label: 'X/Twitter' },
  { value: 'facebook', label: 'Facebook' },
  { value: 'other', label: '其他' },
]

const breadcrumbs = [
  { label: 'BYL ERP', href: '/dashboard' },
  { label: '红人库', href: '/kol/library' },
  { label: props.kol.display_name, href: `/kol/library/${props.kol.id}` },
  '编辑',
]
</script>

<template>
  <Head :title="`编辑 ${kol.display_name} · BYL ERP`" />
  <AuthenticatedLayout :title="`编辑 ${kol.display_name}`" :breadcrumbs="breadcrumbs">
    <div class="erp-form-page">
      <div class="erp-form-content">

        <!-- Page header -->
        <div class="erp-form-header-row kol-sticky-header">
          <div>
            <p class="kol-kicker">编辑 KOL</p>
            <h1 class="erp-form-title">{{ kol.display_name }}</h1>
            <p class="erp-form-subtitle">修改红人资料、业务画像、联系与收货信息后点击「保存更改」</p>
          </div>
          <div class="kol-header-actions page-header-actions">
            <a href="/kol/library" class="erp-btn erp-btn-secondary">返回列表</a>
            <a :href="`/kol/library/${kol.id}`" class="erp-btn erp-btn-secondary">取消</a>
            <button type="submit" form="kol-edit-form" class="erp-btn erp-btn-primary">保存修改</button>
            <a :href="`/kol/library/${kol.id}`" class="erp-btn erp-btn-secondary">返回预览</a>
          </div>
        </div>


        <form id="kol-edit-form" @submit.prevent="submit">

          <!-- Card 1: 基本信息 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">基本信息</h2>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">显示名称 <span class="erp-form-required">*</span></label>
                <input v-model="form.display_name" type="text" class="erp-input" :class="{ 'has-error': form.errors.display_name }" maxlength="255" required>
                <p v-if="form.errors.display_name" class="erp-field-error">{{ form.errors.display_name }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">国家/地区</label>
                <SearchableSelect v-model="form.country_code" :options="countryOptions" placeholder="搜索国家/地区" />
                <p v-if="form.errors.country_code" class="erp-field-error">{{ form.errors.country_code }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">语言</label>
                <select v-model="form.language" class="erp-select" :class="{ 'has-error': form.errors.language }">
                  <option v-for="language in languageOptions" :key="language.value" :value="language.value">{{ language.label }}</option>
                </select>
                <p v-if="form.errors.language" class="erp-field-error">{{ form.errors.language }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">合作类型</label>
                <select v-model="form.cooperation_type" class="erp-select" :class="{ 'has-error': form.errors.cooperation_type }">
                  <option value="免费发帖">免费发帖</option>
                  <option value="长期合作">长期合作</option>
                  <option value="付费发帖">付费发帖</option>
                </select>
                <p v-if="form.errors.cooperation_type" class="erp-field-error">{{ form.errors.cooperation_type }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">加入时间</label>
                <input v-model="form.joined_at" type="date" class="erp-input" :class="{ 'has-error': form.errors.joined_at }">
                <p v-if="form.errors.joined_at" class="erp-field-error">{{ form.errors.joined_at }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">状态 <span class="erp-form-required">*</span></label>
                <div class="erp-segmented">
                  <button type="button" class="erp-segmented-option" :class="{ selected: form.status === 'active' }" @click="form.status = 'active'">活跃</button>
                  <button type="button" class="erp-segmented-option" :class="{ selected: form.status === 'inactive' }" @click="form.status = 'inactive'">停用</button>
                </div>
                <p v-if="form.errors.status" class="erp-field-error">{{ form.errors.status }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">合作阶段 <span class="erp-form-required">*</span></label>
                <select v-model="form.relationship_stage" class="erp-select" :class="{ 'has-error': form.errors.relationship_stage }">
                  <option value="prospect">潜在</option>
                  <option value="contacted">已联系</option>
                  <option value="negotiating">谈判中</option>
                  <option value="cooperating">合作中</option>
                  <option value="long_term">长期合作</option>
                  <option value="paused">暂停</option>
                  <option value="ended">已结束</option>
                </select>
                <p v-if="form.errors.relationship_stage" class="erp-field-error">{{ form.errors.relationship_stage }}</p>
              </div>
              <div v-if="canManageViews" class="erp-form-field">
                <label class="erp-form-label">负责人</label>
                <select v-model="form.owner_id" class="erp-select" :class="{ 'has-error': form.errors.owner_id }">
                  <option value="">未分配</option>
                  <option v-for="o in ownerOptions" :key="o.value" :value="String(o.value)">{{ o.label }}</option>
                </select>
                <p v-if="form.errors.owner_id" class="erp-field-error">{{ form.errors.owner_id }}</p>
              </div>
              <div v-if="canManageViews" class="erp-form-field">
                <label class="erp-form-label">审批经理</label>
                <select v-model="form.approval_manager_id" class="erp-select"><option value="">未分配</option><option v-for="o in approvalManagerOptions" :key="o.value" :value="String(o.value)">{{ o.label }}</option></select>
                <p class="erp-form-helper">审批中心仅显示分配给该经理的记录。</p>
              </div>
              <div v-if="canManageViews" class="erp-form-field">
                <label class="erp-form-label">财务负责人</label>
                <select v-model="form.finance_owner_id" class="erp-select"><option value="">未分配</option><option v-for="o in financeOwnerOptions" :key="o.value" :value="String(o.value)">{{ o.label }}</option></select>
                <p class="erp-form-helper">Finance 仅能看到并完成分配给自己的付款记录。</p>
              </div>
            </div>
          </div>

          <!-- Restored: WordPress / Affiliate 账号 -->
          <div class="erp-form-card kol-account-card">
            <div class="kol-card-header-row">
              <div>
                <h2 class="erp-form-card-title">WordPress / Affiliate 账号</h2>
                <p class="erp-form-card-helper">账号操作与「保存修改」完全分离。普通保存不会创建账号、修改 AffiliateWP/Coupon，也不会修改 WordPress 密码。</p>
              </div>
              <span :class="['kol-account-badge', accountIsBound ? 'is-bound' : 'is-unbound']">{{ accountIsBound ? '已绑定' : '未开通' }}</span>
            </div>

            <template v-if="accountIsBound && affiliateAccountState">
              <div class="kol-account-facts">
                <div><span>WordPress Username</span><strong>{{ affiliateAccountState.wordpress_user_login || '—' }}</strong></div>
                <div><span>WordPress User ID</span><strong>{{ affiliateAccountState.wordpress_user_id ? `#${affiliateAccountState.wordpress_user_id}` : '—' }}</strong></div>
                <div><span>WordPress Email</span><strong>{{ affiliateAccountState.wordpress_user_email || kol.contact_email || '—' }}</strong></div>
                <div><span>AffiliateWP ID</span><strong>{{ affiliateAccountState.affiliate_id ? `#${affiliateAccountState.affiliate_id}` : '—' }}</strong></div>
                <div><span>Affiliate 状态</span><strong>{{ affiliateAccountState.provider_status || affiliateAccountState.provisioning_status || '—' }}</strong></div>
                <div><span>Coupon Code</span><strong>{{ affiliateAccountState.coupon?.code || '—' }}</strong></div>
                <div><span>Coupon 状态</span><strong>{{ affiliateAccountState.coupon?.provider_status || affiliateAccountState.coupon?.provisioning_status || '—' }}</strong></div>
                <div><span>最近同步</span><strong>{{ affiliateAccountState.last_successful_sync_at ? affiliateAccountState.last_successful_sync_at.replace('T', ' ').replace('Z', '') : (affiliateAccountState.sync_status || '—') }}</strong></div>
              </div>
              <p v-if="affiliateAccountState.last_error_code" class="kol-account-warning">最近账号异常：{{ affiliateAccountState.last_error_code }}</p>
              <div class="kol-account-actions">
                <button type="button" class="erp-btn erp-btn-secondary" @click="passwordResetOpen = !passwordResetOpen; passwordResetError = ''; passwordResetMessage = ''">{{ passwordResetOpen ? '取消设置新密码' : '设置新的 WordPress 密码' }}</button>
              </div>
              <div v-if="passwordResetOpen" class="kol-account-password-panel">
                <div class="erp-form-grid">
                  <div class="erp-form-field">
                    <label class="erp-form-label">新 Password <span class="erp-form-required">*</span></label>
                    <input v-model="newWordpressPassword" type="password" autocomplete="new-password" class="erp-input" minlength="12" maxlength="128">
                  </div>
                  <div class="erp-form-field">
                    <label class="erp-form-label">确认新 Password <span class="erp-form-required">*</span></label>
                    <input v-model="newWordpressPasswordConfirmation" type="password" autocomplete="new-password" class="erp-input" minlength="12" maxlength="128">
                  </div>
                </div>
                <p class="erp-form-helper">12–128 位，至少包含 1 个英文字母和 1 个数字；仅用于本次修改，不保存到 ERP。</p>
                <p v-if="passwordResetError" class="erp-field-error">{{ passwordResetError }}</p>
                <button type="button" class="erp-btn erp-btn-primary" :disabled="passwordResetting" @click="updateWordpressPassword">{{ passwordResetting ? '更新中…' : '更新 WordPress 密码' }}</button>
              </div>
              <p v-if="passwordResetMessage" class="kol-account-success">{{ passwordResetMessage }}</p>
            </template>

            <template v-else>
              <p v-if="affiliateAccountState?.provisioning_status && affiliateAccountState.provisioning_status !== 'active'" class="kol-account-warning">上次开户状态：{{ affiliateAccountState.provisioning_status }}<span v-if="affiliateAccountState.last_error_code"> · {{ affiliateAccountState.last_error_code }}</span>。可使用原始凭据重试。</p>
              <div class="erp-form-field">
                <label class="erp-form-label">Email <span class="erp-form-required">*</span></label>
                <input :value="accountEmail" type="email" class="erp-input" readonly placeholder="请先在本页收件地址中保存联系邮箱">
                <p class="erp-form-helper">使用已保存的 KOL 联系邮箱，同时作为 WordPress User 邮箱与 AffiliateWP payment email。若要修改，请先保存 KOL 资料。</p>
              </div>
              <div class="erp-form-grid kol-account-password-grid">
                <div class="erp-form-field">
                  <label class="erp-form-label">Password <span class="erp-form-required">*</span></label>
                  <input v-model="accountPassword" type="password" autocomplete="new-password" class="erp-input" minlength="12" maxlength="128">
                </div>
                <div class="erp-form-field">
                  <label class="erp-form-label">确认 Password <span class="erp-form-required">*</span></label>
                  <input v-model="accountPasswordConfirmation" type="password" autocomplete="new-password" class="erp-input" minlength="12" maxlength="128">
                </div>
              </div>
              <p class="erp-form-helper">12–128 位，至少包含 1 个英文字母和 1 个数字；仅用于本次创建，不保存到 ERP。</p>
              <div class="erp-form-field">
                <label class="erp-form-label">Code <span class="erp-form-required">*</span></label>
                <input v-model="accountCouponCode" type="text" autocomplete="off" class="erp-input" maxlength="255" placeholder="例如 ANDY15">
                <p class="erp-form-helper">创建前同时检查 ERP Coupon mirror 与实时 WooCommerce；冲突只阻止，不覆盖现有 Coupon。</p>
              </div>
              <p v-if="accountError" class="erp-field-error">{{ accountError }}</p>
              <p v-if="accountMessage" :class="affiliateAccountState?.last_error_code ? 'kol-account-warning' : 'kol-account-success'">{{ accountMessage }}</p>
              <div class="kol-account-actions">
                <button type="button" class="erp-btn erp-btn-primary" :disabled="accountProvisioning || !accountEmail" @click="provisionAffiliateAccount">{{ accountProvisioning ? '创建中…' : '创建 WordPress / Affiliate 账号' }}</button>
              </div>
            </template>
          </div>

          <!-- Card 2: 收件地址 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">收件地址</h2>
            <p class="erp-form-card-helper">联系方式与收件信息集中维护。</p>
            <div class="erp-form-grid kol-address-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">电话</label>
                <input v-model="form.contact_phone" type="text" class="erp-input" :class="{ 'has-error': form.errors.contact_phone }" maxlength="64" placeholder="可选">
                <p v-if="form.errors.contact_phone" class="erp-field-error">{{ form.errors.contact_phone }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">邮箱</label>
                <input v-model="form.contact_email" type="email" class="erp-input" :class="{ 'has-error': form.errors.contact_email }" maxlength="255" placeholder="可选">
                <p v-if="form.errors.contact_email" class="erp-field-error">{{ form.errors.contact_email }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">收件人</label>
                <input v-model="form.shipping_recipient_name" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_recipient_name }" maxlength="255" placeholder="可选">
                <p v-if="form.errors.shipping_recipient_name" class="erp-field-error">{{ form.errors.shipping_recipient_name }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">国家</label>
                <input v-model="form.shipping_country" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_country }" maxlength="8" placeholder="例如：US">
                <p v-if="form.errors.shipping_country" class="erp-field-error">{{ form.errors.shipping_country }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">省/州</label>
                <input v-model="form.shipping_province_state" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_province_state }" maxlength="128" placeholder="可选">
                <p v-if="form.errors.shipping_province_state" class="erp-field-error">{{ form.errors.shipping_province_state }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">城市</label>
                <input v-model="form.shipping_city" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_city }" maxlength="128" placeholder="可选">
                <p v-if="form.errors.shipping_city" class="erp-field-error">{{ form.errors.shipping_city }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">邮政编码</label>
                <input v-model="form.shipping_postal_code" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_postal_code }" maxlength="32" placeholder="可选">
                <p v-if="form.errors.shipping_postal_code" class="erp-field-error">{{ form.errors.shipping_postal_code }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">详细地址</label>
                <input v-model="form.shipping_address_line_1" type="text" class="erp-input" :class="{ 'has-error': form.errors.shipping_address_line_1 }" maxlength="255" placeholder="可选">
                <p v-if="form.errors.shipping_address_line_1" class="erp-field-error">{{ form.errors.shipping_address_line_1 }}</p>
              </div>
            </div>
          </div>

          <!-- Card 3: 社交账号 -->
          <div class="erp-form-card">
            <div class="kol-card-header-row">
              <h2 class="erp-form-card-title">社交账号</h2>
              <button type="button" class="erp-btn erp-btn-secondary" @click="addSocialAccount">添加账号</button>
            </div>
            <p v-if="form.social_accounts.length === 0" class="kol-empty-state">暂无社交账号</p>
            <div v-for="(sa, idx) in form.social_accounts" :key="idx" class="kol-sa-row">
              <div class="erp-form-field">
                <label class="erp-form-label">平台</label>
                <select v-model="sa.platform" class="erp-select">
                  <option v-for="p in platformOptions" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
                <p v-if="(form.errors as Record<string, string>)[`social_accounts.${idx}.platform`]" class="erp-field-error">{{ (form.errors as Record<string, string>)[`social_accounts.${idx}.platform`] }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">账号名 / Handle</label>
                <input v-model="sa.handle" type="text" class="erp-input" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">主页链接</label>
                <input v-model="sa.profile_url" type="url" class="erp-input" placeholder="可选">
                <p v-if="(form.errors as Record<string, string>)[`social_accounts.${idx}.profile_url`]" class="erp-field-error">{{ (form.errors as Record<string, string>)[`social_accounts.${idx}.profile_url`] }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">粉丝数</label>
                <input v-model="sa.followers" type="number" min="0" class="erp-input" placeholder="整数，可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">原始值</label>
                <input v-model="sa.raw_followers_value" type="text" class="erp-input" maxlength="32" placeholder="如 318.2K">
              </div>
              <div class="erp-form-field kol-sa-status">
                <label class="erp-form-label">状态</label>
                <div class="erp-segmented">
                  <button type="button" class="erp-segmented-option" :class="{ selected: sa.is_active }" @click="sa.is_active = true">启用</button>
                  <button type="button" class="erp-segmented-option" :class="{ selected: !sa.is_active }" @click="sa.is_active = false">停用</button>
                </div>
              </div>
              <div class="kol-sa-remove">
                <button type="button" class="erp-btn erp-btn-secondary" @click="removeSocialAccount(idx)">删除</button>
              </div>
            </div>
          </div>

          <!-- Card 4: 业务画像 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">网红业务画像</h2>
            <div class="kol-avatar-business-row">
              <div
                class="kol-avatar-editor"
                :class="{ 'is-active': avatarPasteActive, 'is-uploading': avatarUploading }"
                tabindex="0"
                role="button"
                aria-label="编辑网红头像"
                @click="avatarPasteActive = true"
                @focus="avatarPasteActive = true"
                @paste="handleAvatarPaste"
                @dragover.prevent
                @drop.prevent="handleAvatarDrop"
              >
                <img v-if="avatarUrl" :src="avatarUrl" :alt="`${kol.display_name} 头像`" class="kol-avatar-image">
                <span v-else class="kol-avatar-placeholder">{{ kol.display_name.trim().charAt(0).toUpperCase() || '?' }}</span>
                <span v-if="avatarUploading" class="kol-avatar-overlay">上传中…</span>
              </div>
              <div class="kol-avatar-editor-copy">
                <strong v-if="avatarPasteActive">粘贴图片即可 Ctrl+V / ⌘V</strong>
                <span v-else>点击头像后可直接粘贴，也支持拖拽或选择图片</span>
                <button type="button" class="erp-btn erp-btn-secondary kol-avatar-choose" @click="avatarInput?.click()">选择图片</button>
                <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="kol-avatar-file" @change="handleAvatarFile">
                <p v-if="avatarError" class="erp-field-error">{{ avatarError }}</p>
              </div>
            </div>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">网红分级</label>
                <select v-model="form.level" class="erp-select"><option value="">未分级</option><option value="头部">头部</option><option value="腰部">腰部</option><option value="尾部">尾部</option><option value="素人">素人</option></select>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">性别</label>
                <select v-model="form.gender" class="erp-select"><option value="">未指定</option><option value="男">男</option><option value="女">女</option><option value="女同">女同</option><option value="男同">男同</option><option value="其他">其他</option></select>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">主营品类</label>
                <select v-model="form.categories" class="erp-select"><option value="">未指定</option><option value="Lifestyle">Lifestyle</option><option value="Beauty">Beauty</option><option value="Fitness">Fitness</option><option value="漫画">漫画</option><option value="家居">家居</option><option value="色情">色情</option><option value="其他">其他</option></select>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">内容形式</label>
                <select v-model="form.content_format" class="erp-select"><option value="">未指定</option><option value="图文">图文</option><option value="短视频">短视频</option><option value="直播">直播</option><option value="图文+视频">图文+视频</option><option value="混合">混合</option></select>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">主推产品</label>
                <SkuCombobox v-model="form.products" />
                <p v-if="form.errors.products" class="erp-field-error">{{ form.errors.products }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">产品数量</label>
                <input v-model="form.product_quantity" type="number" min="0" class="erp-input" placeholder="可选">
              </div>
            </div>
          </div>

          <!-- M1.1 CRM: 合作机会 -->
          <div id="crm-opportunities" class="erp-form-card">
            <div class="kol-card-header-row">
              <div>
                <h2 class="erp-form-card-title">合作机会</h2>
                <p class="erp-form-card-helper">维护真实 CRM 机会；进行中机会显示在预览页「当前合作机会」，其他状态进入历史机会。</p>
              </div>
              <button type="button" class="erp-btn erp-btn-secondary" @click="openNewOpportunity">新增合作机会</button>
            </div>
            <div v-if="opportunityRows.length" class="kol-opportunity-list">
              <div v-for="item in opportunityRows" :key="item.id" class="kol-opportunity-row">
                <div class="kol-opportunity-main">
                  <strong>{{ item.title }}</strong>
                  <span>{{ opportunityStageLabel(item.stage) }}</span>
                  <span>{{ item.target_products.length ? item.target_products.join('、') : '未指定 SKU' }}</span>
                </div>
                <div class="kol-opportunity-meta">
                  <span :class="['kol-opportunity-status', `is-${item.status}`]">{{ opportunityStatusLabel(item.status) }}</span>
                  <span>{{ item.owner?.name ?? '未分配' }}</span>
                  <span>{{ item.next_follow_up_at ? item.next_follow_up_at.replace('T', ' ') : '无下次跟进' }}</span>
                  <button v-if="!collaborationRows.some(c => c.opportunity_id === item.id)" type="button" class="erp-btn erp-btn-primary" @click="convertOpportunity(item)">转为正式合作</button>
                  <button type="button" class="erp-btn erp-btn-secondary kol-opportunity-edit" @click="openEditOpportunity(item)">编辑</button>
                </div>
              </div>
            </div>
            <div v-else class="kol-empty-state kol-opportunity-empty">暂无合作机会，点击「新增合作机会」开始记录。</div>
          </div>


          <!-- M1.2A: 正式合作 -->
          <div id="crm-collaborations" class="erp-form-card">
            <div class="kol-card-header-row">
              <div><h2 class="erp-form-card-title">正式合作</h2><p class="erp-form-card-helper">仅维护已确认的合作事实；需要寄样/发货只做标记，实际履约由 M1.2B 接入 canonical KOL Order。</p></div>
              <button type="button" class="erp-btn erp-btn-secondary" @click="openNewCollaboration">新增正式合作</button>
            </div>
            <div v-if="collaborationRows.length" class="kol-collaboration-list">
              <div v-for="item in collaborationRows" :key="item.id" class="kol-collaboration-row">
                <div><strong>{{ item.post_url || '待补合作发帖 URL' }}</strong><span>{{ collaborationStatusLabel(item.status) }}</span><span>{{ item.products.length ? item.products.map(p => `${p.sku} ×${p.quantity}`).join('、') : '未指定产品' }}</span></div>
                <div><span>{{ item.agreement_amount === null ? '—' : `${item.currency} ${item.agreement_amount}` }}</span><span>{{ item.fulfillment_required ? '需要寄样/发货' : '无需寄样/发货' }}</span><a v-if="item.fulfillment_order" class="erp-btn erp-btn-secondary" :href="`/kol/orders/${item.fulfillment_order.id}`">履约单 {{ item.fulfillment_order.erp_order_id ?? '—' }}</a><button v-else-if="item.fulfillment_required && canCreateOrders" type="button" class="erp-btn erp-btn-primary" :disabled="fulfillmentCreatingId===item.id" @click="createFulfillmentOrder(item)">{{ fulfillmentCreatingId===item.id ? '创建中…' : '创建履约订单' }}</button><button type="button" class="erp-btn erp-btn-secondary" @click="openEditCollaboration(item)">编辑</button></div>
              </div>
            </div>
            <p v-if="collaborationError" class="erp-field-error">{{ collaborationError }}</p><div v-if="!collaborationRows.length" class="kol-empty-state">暂无正式合作。可从已确认机会点击「转为正式合作」，或直接新增。</div>
          </div>

          <!-- M1.1 CRM: 沟通记录 -->
          <div id="crm-communications" class="erp-form-card">
            <div class="kol-card-header-row">
              <div><h2 class="erp-form-card-title">沟通记录</h2><p class="erp-form-card-helper">同一个网红可由多人持续记录沟通；最新记录自动展示在预览页「最近沟通」。</p></div>
              <button type="button" class="erp-btn erp-btn-secondary" @click="openNewCommunication">新增沟通记录</button>
            </div>
            <div v-if="communicationRows.length" class="kol-communication-admin-list">
              <div v-for="item in communicationRows" :key="item.id" class="kol-communication-admin-row">
                <div><strong>{{ communicationChannelLabel(item.channel) }}</strong><span>{{ communicationDirectionLabel(item.direction) }}</span><span>{{ item.contacted_at?.replace('T',' ') ?? '—' }}</span></div>
                <p>{{ item.summary }}</p>
                <div><span>{{ item.creator?.name ?? '—' }}</span><span v-if="item.opportunity">{{ item.opportunity.title }}</span><button type="button" class="erp-btn erp-btn-secondary" @click="openEditCommunication(item)">编辑</button></div>
              </div>
            </div>
            <div v-else class="kol-empty-state">暂无沟通记录。</div>
          </div>

          <!-- Card 5: 税务与收款 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">税务与收款</h2>
            <p class="erp-form-card-helper">税务标识符和收款账户为敏感信息，不会显示在红人列表中。</p>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">税务标识符（Tax ID）</label>
                <input v-model="form.tax_id" type="text" class="erp-input" :class="{ 'has-error': form.errors.tax_id }" maxlength="128" placeholder="可选">
                <p v-if="form.errors.tax_id" class="erp-field-error">{{ form.errors.tax_id }}</p>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">收款方式</label>
                <select v-model="form.payout_method" class="erp-select" :class="{ 'has-error': form.errors.payout_method }">
                  <option value="">不指定</option>
                  <option value="paypal">PayPal</option>
                  <option value="bank">银行转账</option>
                  <option value="other">其他</option>
                </select>
                <p v-if="form.errors.payout_method" class="erp-field-error">{{ form.errors.payout_method }}</p>
              </div>
              <div class="erp-form-field kol-span-2">
                <label class="erp-form-label">收款账户</label>
                <input v-model="form.payout_account" type="text" class="erp-input" :class="{ 'has-error': form.errors.payout_account }" maxlength="512" placeholder="PayPal 邮箱 / 银行账号等，可选">
                <p v-if="form.errors.payout_account" class="erp-field-error">{{ form.errors.payout_account }}</p>
              </div>
            </div>
          </div>

          <!-- Card 6: 历史带货记录 -->
          <div class="erp-form-card">
            <div class="kol-card-header-row">
              <h2 class="erp-form-card-title">历史带货记录</h2>
              <button type="button" class="erp-btn erp-btn-secondary" @click="addSalesPerformance">添加记录</button>
            </div>
            <p class="erp-form-card-helper">历史带货数量（当前：{{ kol.historical_order_count }}）为已保存记录之和；已保存记录不可编辑，新增记录会累加到总数中。</p>
            <div v-if="kol.sales_performance_history.length > 0" class="kol-perf-history">
              <div v-for="event in kol.sales_performance_history" :key="event.id" class="kol-perf-history-row">
                <span class="kol-perf-count">{{ event.order_count }}</span>
                <span>{{ event.recorded_at ?? '—' }}</span>
                <span>{{ event.source ?? '—' }}</span>
                <span class="kol-perf-note">{{ event.note ?? '' }}</span>
              </div>
            </div>
            <p v-if="form.sales_performance_entries.length === 0" class="kol-empty-state">点击「添加记录」新增带货事件。</p>
            <div v-for="(perf, idx) in form.sales_performance_entries" :key="idx" class="kol-perf-row">
              <div class="erp-form-field">
                <label class="erp-form-label">数量</label>
                <input v-model="perf.order_count" type="number" min="1" class="erp-input" placeholder="订单数量">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">日期</label>
                <input v-model="perf.recorded_at" type="date" class="erp-input">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">来源</label>
                <input v-model="perf.source" type="text" class="erp-input" maxlength="64" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">备注</label>
                <input v-model="perf.note" type="text" class="erp-input" maxlength="1000" placeholder="可选">
              </div>
              <div class="kol-sa-remove">
                <button type="button" class="erp-btn erp-btn-secondary" @click="removeSalesPerformance(idx)">删除</button>
              </div>
            </div>
          </div>

          <!-- Card 7: 内部运营信息 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">内部运营信息</h2>
            <div class="erp-form-grid">
              <div class="erp-form-field">
                <label class="erp-form-label">内部联系人</label>
                <input v-model="form.internal_contact" type="text" class="erp-input" maxlength="255" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">MCN 机构</label>
                <input v-model="form.mcn_agency" type="text" class="erp-input" maxlength="255" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">是否 MCN 管理</label>
                <div class="erp-segmented">
                  <button type="button" class="erp-segmented-option" :class="{ selected: form.is_mcn_managed }" @click="form.is_mcn_managed = true">是</button>
                  <button type="button" class="erp-segmented-option" :class="{ selected: !form.is_mcn_managed }" @click="form.is_mcn_managed = false">否</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Card 8: 备注 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">备注</h2>
            <div class="erp-form-field">
              <label class="erp-form-label">备注内容</label>
              <textarea v-model="form.notes" class="erp-textarea" maxlength="5000" placeholder="可选" />
              <p v-if="form.errors.notes" class="erp-field-error">{{ form.errors.notes }}</p>
            </div>
          </div>

        </form>

        <div v-if="opportunityModalOpen" class="kol-modal-backdrop" @click.self="opportunityModalOpen = false">
          <section class="kol-modal" role="dialog" aria-modal="true" aria-labelledby="kol-opportunity-modal-title">
            <div class="kol-modal-header">
              <div>
                <p class="kol-kicker">M1.1 CRM</p>
                <h2 id="kol-opportunity-modal-title">{{ editingOpportunityId ? '编辑合作机会' : '新增合作机会' }}</h2>
              </div>
              <button type="button" class="kol-modal-close" aria-label="关闭" @click="opportunityModalOpen = false">×</button>
            </div>
            <div class="kol-modal-grid">
              <div class="erp-form-field kol-span-2">
                <label class="erp-form-label">机会标题 *</label>
                <input v-model="opportunityForm.title" type="text" class="erp-input" maxlength="255" placeholder="例如：2026 秋季 Instagram 合作">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">当前阶段 *</label>
                <select v-model="opportunityForm.stage" class="erp-select">
                  <option v-for="option in opportunityStageOptions" :key="option[0]" :value="option[0]">{{ option[1] }}</option>
                </select>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">状态 *</label>
                <select v-model="opportunityForm.status" class="erp-select">
                  <option v-for="option in opportunityStatusOptions" :key="option[0]" :value="option[0]">{{ option[1] }}</option>
                </select>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">合作类型</label>
                <select v-model="opportunityForm.cooperation_type" class="erp-select"><option value="免费发帖">免费发帖</option><option value="长期合作">长期合作</option><option value="付费发帖">付费发帖</option></select>
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">目标 SKU</label>
                <SkuCombobox v-model="opportunityForm.target_sku" />
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">预计费用</label>
                <input v-model="opportunityForm.estimated_cost" type="number" min="0" step="0.01" class="erp-input" placeholder="可选">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">币种</label>
                <input v-model="opportunityForm.currency" type="text" class="erp-input" maxlength="3" placeholder="USD">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">预计发布时间</label>
                <input v-model="opportunityForm.expected_publish_at" type="date" class="erp-input">
              </div>
              <div class="erp-form-field">
                <label class="erp-form-label">下次跟进</label>
                <input v-model="opportunityForm.next_follow_up_at" type="datetime-local" class="erp-input">
              </div>
              <div v-if="canManageViews" class="erp-form-field kol-span-2">
                <label class="erp-form-label">负责人</label>
                <select v-model="opportunityForm.owner_id" class="erp-select"><option value="">未分配</option><option v-for="owner in ownerOptions" :key="owner.value" :value="String(owner.value)">{{ owner.label }}</option></select>
              </div>
              <div class="erp-form-field kol-span-2">
                <label class="erp-form-label">下一步</label>
                <input v-model="opportunityForm.next_action" type="text" class="erp-input" maxlength="2000" placeholder="例如：确认报价后发送样品">
              </div>
              <div class="erp-form-field kol-span-2">
                <label class="erp-form-label">备注</label>
                <textarea v-model="opportunityForm.notes" class="erp-textarea" maxlength="5000" placeholder="可选" />
              </div>
            </div>
            <p v-if="opportunityError" class="erp-field-error kol-modal-error">{{ opportunityError }}</p>
            <div class="kol-modal-actions">
              <button type="button" class="erp-btn erp-btn-secondary" :disabled="opportunitySaving" @click="opportunityModalOpen = false">取消</button>
              <button type="button" class="erp-btn erp-btn-primary" :disabled="opportunitySaving || !opportunityForm.title.trim()" @click="saveOpportunity">{{ opportunitySaving ? '保存中…' : '保存合作机会' }}</button>
            </div>
          </section>
        </div>

        <div v-if="collaborationModalOpen" class="kol-modal-backdrop" @click.self="collaborationModalOpen = false">
          <section class="kol-modal" role="dialog" aria-modal="true" aria-labelledby="kol-collaboration-modal-title">
            <div class="kol-modal-header"><div><p class="kol-kicker">M1.2A Collaboration</p><h2 id="kol-collaboration-modal-title">{{ editingCollaborationId ? '编辑正式合作' : '新增正式合作' }}</h2></div><button type="button" class="kol-modal-close" @click="collaborationModalOpen=false">×</button></div>
            <div class="kol-modal-grid">
              <div class="erp-form-field kol-span-2"><label class="erp-form-label">合作发帖 URL</label><input v-model="collaborationForm.post_url" type="url" class="erp-input" maxlength="2048" placeholder="https://…"><small class="erp-form-helper">非必填；未填写的正式合作可在合作记录中单独筛选跟进。</small></div>
              <div class="erp-form-field"><label class="erp-form-label">来源机会</label><select v-model="collaborationForm.opportunity_id" class="erp-select"><option value="">不关联</option><option v-for="o in opportunityRows" :key="o.id" :value="String(o.id)">{{ o.title }}</option></select></div>
              <div class="erp-form-field"><label class="erp-form-label">状态 *</label><select v-model="collaborationForm.status" class="erp-select"><option v-for="o in collaborationStatusOptions" :key="o[0]" :value="o[0]">{{ o[1] }}</option></select></div>
              <div class="erp-form-field"><label class="erp-form-label">合作类型</label><select v-model="collaborationForm.cooperation_type" class="erp-select"><option value="免费发帖">免费发帖</option><option value="长期合作">长期合作</option><option value="付费发帖">付费发帖</option></select></div>
              <div v-if="canManageViews" class="erp-form-field"><label class="erp-form-label">负责人</label><select v-model="collaborationForm.owner_id" class="erp-select"><option value="">未分配</option><option v-for="owner in ownerOptions" :key="owner.value" :value="String(owner.value)">{{ owner.label }}</option></select></div>
              <div class="erp-form-field"><label class="erp-form-label">合作金额</label><input v-model="collaborationForm.agreement_amount" type="number" min="0" step="0.01" class="erp-input"></div>
              <div class="erp-form-field"><label class="erp-form-label">币种</label><input v-model="collaborationForm.currency" maxlength="3" class="erp-input"></div>
              <div class="erp-form-field"><label class="erp-form-label">确认日期</label><input v-model="collaborationForm.agreed_at" type="date" class="erp-input"></div>
              <div class="erp-form-field"><label class="erp-form-label">计划发布时间</label><input v-model="collaborationForm.planned_publish_at" type="date" class="erp-input"></div>
              <div class="erp-form-field kol-span-2"><label class="erp-form-label">约定交付内容</label><textarea v-model="collaborationForm.deliverables" class="erp-textarea" maxlength="5000" /></div>
              <div class="erp-form-field kol-span-2"><label class="erp-form-label">合作产品</label><div v-for="(product,idx) in collaborationForm.products" :key="idx" class="kol-collab-product-row"><SkuCombobox v-model="product.sku" v-model:product-id="product.product_id"/><input v-model="product.quantity" type="number" min="1" max="9999" class="erp-input" aria-label="产品数量"><button type="button" class="erp-btn erp-btn-secondary" :disabled="collaborationForm.products.length===1" @click="removeCollaborationProduct(idx)">删除</button></div><button type="button" class="erp-btn erp-btn-secondary" @click="addCollaborationProduct">添加产品</button></div>
              <div class="erp-form-field kol-span-2"><label class="erp-checkbox-label"><input v-model="collaborationForm.fulfillment_required" type="checkbox"> 需要寄样 / 发货（仅需求标记，不会创建订单）</label></div>
              <div class="erp-form-field kol-span-2"><label class="erp-form-label">备注</label><textarea v-model="collaborationForm.notes" class="erp-textarea" maxlength="5000" /></div>
            </div>
            <p v-if="collaborationError" class="erp-field-error kol-modal-error">{{ collaborationError }}</p>
            <div class="kol-modal-actions"><button type="button" class="erp-btn erp-btn-secondary" :disabled="collaborationSaving" @click="collaborationModalOpen=false">取消</button><button type="button" class="erp-btn erp-btn-primary" :disabled="collaborationSaving" @click="saveCollaboration">{{ collaborationSaving ? '保存中…' : '保存正式合作' }}</button></div>
          </section>
        </div>

        <div v-if="communicationModalOpen" class="kol-modal-backdrop" @click.self="communicationModalOpen = false">
          <section class="kol-modal" role="dialog" aria-modal="true" aria-labelledby="kol-communication-modal-title">
            <div class="kol-modal-header"><div><p class="kol-kicker">M1.1 CRM</p><h2 id="kol-communication-modal-title">{{ editingCommunicationId ? '编辑沟通记录' : '新增沟通记录' }}</h2></div><button type="button" class="kol-modal-close" @click="communicationModalOpen = false">×</button></div>
            <div class="kol-modal-grid">
              <div class="erp-form-field"><label class="erp-form-label">沟通时间 *</label><input v-model="communicationForm.contacted_at" type="datetime-local" class="erp-input"></div>
              <div class="erp-form-field"><label class="erp-form-label">渠道 *</label><select v-model="communicationForm.channel" class="erp-select"><option v-for="o in communicationChannelOptions" :key="o[0]" :value="o[0]">{{ o[1] }}</option></select></div>
              <div class="erp-form-field"><label class="erp-form-label">方向 *</label><select v-model="communicationForm.direction" class="erp-select"><option value="outbound">我方联系对方</option><option value="inbound">对方联系我方</option></select></div>
              <div class="erp-form-field"><label class="erp-form-label">关联合作机会</label><select v-model="communicationForm.opportunity_id" class="erp-select"><option value="">不关联</option><option v-for="o in opportunityRows" :key="o.id" :value="String(o.id)">{{ o.title }}</option></select></div>
              <div class="erp-form-field kol-span-2"><label class="erp-form-label">沟通内容 *</label><textarea v-model="communicationForm.summary" class="erp-textarea" maxlength="5000" /></div>
              <div class="erp-form-field"><label class="erp-form-label">下一步</label><input v-model="communicationForm.next_action" type="text" class="erp-input" maxlength="2000"></div>
              <div class="erp-form-field"><label class="erp-form-label">下次跟进</label><input v-model="communicationForm.next_follow_up_at" type="datetime-local" class="erp-input"></div>
            </div>
            <p v-if="communicationError" class="erp-field-error kol-modal-error">{{ communicationError }}</p>
            <div class="kol-modal-actions"><button type="button" class="erp-btn erp-btn-secondary" @click="communicationModalOpen = false">取消</button><button type="button" class="erp-btn erp-btn-primary" :disabled="communicationSaving || !communicationForm.summary.trim()" @click="saveCommunication">{{ communicationSaving ? '保存中…' : '保存沟通记录' }}</button></div>
          </section>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.kol-sticky-header {
  position: sticky;
  top: 0;
  z-index: 30;
  background: var(--erp-page-bg, #f8fafc);
  padding-block: 12px;
}

.kol-header-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: flex-end;
}

.kol-kicker {
  font-size: 13px;
  color: #7a8c94;
  margin: 0 0 4px;
}

.kol-address-column {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 12px;
  max-width: 760px;
}

.kol-card-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.kol-card-header-row .erp-form-card-title {
  margin: 0;
}

.kol-empty-state {
  font-size: 13px;
  color: #7a8c94;
  padding: 8px 0;
}

.kol-sa-row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 1fr 1fr auto auto;
  gap: 12px;
  align-items: end;
  padding: 12px 0;
  border-top: 1px solid #e4eaed;
}

.kol-sa-row:first-of-type {
  border-top: none;
}

.kol-perf-row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 2fr auto;
  gap: 12px;
  align-items: end;
  padding: 12px 0;
  border-top: 1px solid #e4eaed;
}

.kol-perf-row:first-of-type {
  border-top: none;
}

.kol-perf-history {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 8px;
}

.kol-perf-history-row {
  display: grid;
  grid-template-columns: 60px 100px 100px 1fr;
  gap: 12px;
  font-size: 13px;
  padding: 4px 0;
  border-bottom: 1px solid #f1f5f9;
}

.kol-perf-count {
  font-weight: 600;
}

.kol-perf-note {
  color: #64748b;
}

.kol-sa-status {
  min-width: 100px;
}

.kol-sa-remove {
  display: flex;
  align-items: flex-end;
  padding-bottom: 0;
}

.kol-address-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.kol-span-2 {
  grid-column: span 2;
}

.kol-action-bar {
  justify-content: flex-end;
}

.kol-avatar-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 28px;
}

.kol-avatar-business-row { display: flex; align-items: center; gap: 18px; margin: 4px 0 18px; padding-bottom: 18px; border-bottom: 1px solid #e5eaed; }
.kol-avatar-editor { position: relative; width: 96px; height: 96px; flex: 0 0 96px; display: grid; place-items: center; border-radius: 50%; border: 2px dashed #cbd5e1; background: #f8fafc; cursor: pointer; overflow: hidden; outline: none; transition: border-color .15s ease, box-shadow .15s ease; }
.kol-avatar-editor.is-active, .kol-avatar-editor:focus { border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, .12); }
.kol-avatar-image { width: 100%; height: 100%; object-fit: cover; }
.kol-avatar-placeholder { font-size: 32px; font-weight: 700; color: #0f766e; }
.kol-avatar-overlay { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(15, 23, 42, .62); color: #fff; font-size: 12px; font-weight: 600; }
.kol-avatar-editor-copy { display: flex; flex-direction: column; align-items: flex-start; gap: 7px; color: #64748b; font-size: 13px; }
.kol-avatar-editor-copy strong { color: #0f766e; }
.kol-avatar-choose { min-height: 34px; padding: 6px 12px; }
.kol-avatar-file { display: none; }

.kol-opportunity-list { display: flex; flex-direction: column; border: 1px solid #e5eaed; border-radius: 10px; overflow: hidden; }
.kol-opportunity-row { display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 14px 16px; border-top: 1px solid #edf1f3; background: #fff; }
.kol-opportunity-row:first-child { border-top: 0; }
.kol-opportunity-main { display: flex; align-items: center; gap: 12px; min-width: 0; }
.kol-opportunity-main strong { min-width: 180px; color: #1f2937; }
.kol-opportunity-main span { color: #64748b; font-size: 13px; }
.kol-opportunity-meta { display: flex; align-items: center; justify-content: flex-end; gap: 12px; color: #64748b; font-size: 13px; white-space: nowrap; }
.kol-opportunity-status { padding: 3px 9px; border-radius: 999px; background: #eef2f6; color: #475569; font-weight: 600; }
.kol-opportunity-status.is-active, .kol-opportunity-status.is-success { background: #e8f6f1; color: #16745f; }
.kol-opportunity-status.is-failed, .kol-opportunity-status.is-cancelled { background: #fff0f0; color: #b54747; }
.kol-opportunity-status.is-paused { background: #fff7e6; color: #9a6700; }
.kol-opportunity-edit { min-height: 32px; padding: 5px 10px; }
.kol-opportunity-empty { border: 1px dashed #d6dee2; border-radius: 10px; padding: 22px; text-align: center; }
.kol-modal-backdrop { position: fixed; inset: 0; z-index: 80; display: grid; place-items: center; padding: 24px; background: rgba(15, 23, 42, .48); }
.kol-modal { width: min(760px, 100%); max-height: calc(100vh - 48px); overflow: auto; border-radius: 14px; background: #fff; box-shadow: 0 24px 60px rgba(15, 23, 42, .22); padding: 22px; }
.kol-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
.kol-modal-header h2 { margin: 0; font-size: 20px; color: #1f2937; }
.kol-modal-close { border: 0; background: transparent; color: #64748b; font-size: 28px; line-height: 1; cursor: pointer; }
.kol-modal-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 16px; }
.kol-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
.kol-modal-error { margin-top: 12px; }


.kol-communication-admin-list { display: flex; flex-direction: column; }
.kol-communication-admin-row { display: grid; grid-template-columns: 1.2fr 2fr 1fr; gap: 14px; align-items: center; padding: 12px 0; border-top: 1px solid #e5eaed; }
.kol-communication-admin-row:first-child { border-top: 0; }
.kol-communication-admin-row > div { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 13px; color: #64748b; }
.kol-communication-admin-row p { margin: 0; color: #334155; font-size: 13px; }

.kol-account-card { border-color: #d9e4e2; }
.kol-account-badge { display: inline-flex; align-items: center; padding: 5px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }
.kol-account-badge.is-bound { background: #e8f6f1; color: #16745f; }
.kol-account-badge.is-unbound { background: #f1f5f9; color: #64748b; }
.kol-account-facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
.kol-account-facts > div { min-height: 70px; padding: 12px 14px; border: 1px solid #e4eaed; border-radius: 10px; background: #f8fafc; }
.kol-account-facts span { display: block; margin-bottom: 6px; color: #7a8c94; font-size: 12px; }
.kol-account-facts strong { display: block; overflow-wrap: anywhere; color: #263238; font-size: 14px; }
.kol-account-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.kol-account-password-panel { margin-top: 16px; padding: 16px; border: 1px solid #dbe5e8; border-radius: 10px; background: #f8fafc; }
.kol-account-warning { margin: 12px 0 0; padding: 10px 12px; border-radius: 8px; background: #fff7e6; color: #9a6700; font-size: 13px; }
.kol-account-success { margin: 12px 0 0; padding: 10px 12px; border-radius: 8px; background: #e8f6f1; color: #16745f; font-size: 13px; }
.kol-account-password-grid { margin-top: 12px; }

@media (max-width: 767px) {
  .kol-account-facts { grid-template-columns: 1fr; }
  .kol-avatar-business-row { align-items: flex-start; flex-direction: column; }
  .kol-address-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.kol-span-2 {
    grid-column: span 1;
  }

  .kol-opportunity-row, .kol-opportunity-main, .kol-opportunity-meta { align-items: flex-start; flex-direction: column; white-space: normal; }
  .kol-opportunity-main strong { min-width: 0; }
  .kol-modal-grid { grid-template-columns: 1fr; }
  .kol-modal-grid .kol-span-2 { grid-column: span 1; }
  .kol-sa-row,
  .kol-perf-row,
  .kol-perf-history-row,
  .kol-communication-admin-row {
    grid-template-columns: 1fr;
  }
}

.kol-collaboration-list{display:grid;gap:10px;margin-top:14px}.kol-collaboration-row{display:flex;justify-content:space-between;gap:16px;padding:12px 14px;border:1px solid var(--erp-border);border-radius:12px}.kol-collaboration-row>div{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.kol-collaboration-row span{color:var(--erp-text-muted);font-size:13px}.kol-collab-product-row{display:grid;grid-template-columns:minmax(0,1fr) 110px auto;gap:8px;margin-bottom:8px}.erp-checkbox-label{display:flex;align-items:center;gap:8px}@media(max-width:800px){.kol-collaboration-row{flex-direction:column}.kol-collab-product-row{grid-template-columns:1fr}}
</style>
