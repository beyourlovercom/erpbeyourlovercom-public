import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import BossDashboard from './BossDashboard.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: vi.fn(), post: vi.fn() },
  usePage: () => ({ url: '/marketing/traffic', props: {
    auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } },
    notificationCenter: { unread_count: 0, items: [] },
    shellNavigation: [{ key:'marketing', label:'营销', path:'/marketing', route:'shell.marketing', placeholder:false, children:[{ key:'marketing-traffic', label:'流量总览', path:'/marketing/traffic', route:'traffic.boss-dashboard', placeholder:false, children:[] }] }],
  } }),
}))

const props = {
  project: { key:'yby-irrigation', name:'YBY Irrigation' },
  filters: { date_from:'2026-09-08', date_to:'2026-09-13' },
  summary: { currency_code:'CNY', mixed_currency:false, impressions:702, clicks:109, spend:'880.68', provider_conversions:27, inquiries:27, qualified_inquiries:null, qualification_state:'unavailable_until_case_truth', cost_per_inquiry:'32.62', cost_per_qualified_inquiry:null, ctr_percent:15.53, cpc:'8.08' },
  trend: [{ date:'2026-09-08', impressions:100, clicks:12, spend:'100.00', provider_conversions:4, inquiries:4, qualified_inquiries:null }, { date:'2026-09-13', impressions:602, clicks:97, spend:'780.68', provider_conversions:23, inquiries:23, qualified_inquiries:null }],
  campaigns: [{ external_campaign_id:'24023703280', name:'YBY-Search-IRR-SOLUTION-Tanzania', status:'enabled', advertising_channel_type:'SEARCH', impressions:702, clicks:109, spend:'880.68', provider_conversions:27 }],
  sources: [{ source_type:'google_ads_form_lead', inquiries:26, qualified_inquiries:null }, { source_type:'google_ads_whatsapp_lead', inquiries:1, qualified_inquiries:null }],
  data_health: { traffic_rows:18, inquiry_rows:12, data_from:'2026-09-08', data_to:'2026-09-13', account_last_synced_at:'2026-09-14T08:00:00.000000Z', sync_mode:'manual', automatic_sync_enabled:false, qualification_state:'unavailable_until_case_truth', attribution_scope:'project_source_only' },
}

describe('Traffic Boss Dashboard', () => {
  it('renders boss KPIs, campaign evidence, inquiry sources and qualification boundary', () => {
    const wrapper = mount(BossDashboard, { props })
    expect(wrapper.text()).toContain('YBY Irrigation · 流量总览')
    expect(wrapper.text()).toContain('¥880.68')
    expect(wrapper.text()).toContain('27')
    expect(wrapper.text()).toContain('每询盘成本')
    expect(wrapper.text()).toContain('Case Truth 建立后开放')
    expect(wrapper.text()).toContain('YBY-Search-IRR-SOLUTION-Tanzania')
    expect(wrapper.text()).toContain('表单 Lead')
    expect(wrapper.text()).toContain('WhatsApp Lead')
    expect(wrapper.text()).toContain('当前尚未启用 Google Ads Production 自动同步')
  })
})
