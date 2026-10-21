import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Edit from './Edit.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  useForm: (initial: Record<string, unknown>) => {
    const data = { ...initial }
    const errors = {} as Record<string, string>
    return {
      ...data,
      errors,
      processing: false,
      transform: () => ({ patch: vi.fn() }),
      patch: vi.fn(),
    }
  },
  usePage: () => ({ url: '/kol/library/1/edit', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { name: 'AuthenticatedLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const baseKol = {
  id: 1,
  display_name: 'Jane KOL',
  legal_name: null,
  country_code: 'US',
  language: 'en',
  status: 'active',
  relationship_stage: 'prospect',
  cooperation_type: null,
  owner_id: 1,
  joined_at: null,
  notes: null,
  contact_email: null,
  contact_phone: null,
  contact_whatsapp: null,
  other_contact: null,
  preferred_contact_method: 'email',
  tax_id: null,
  payout_method: null,
  payout_account: null,
  shipping_recipient_name: null,
  shipping_country: null,
  shipping_province_state: null,
  shipping_city: null,
  shipping_postal_code: null,
  shipping_address_line_1: null,
  shipping_address_line_2: null,
  level: null,
  gender: null,
  categories: [],
  products: [],
  content_format: null,
  product_quantity: null,
  internal_contact: null,
  mcn_agency: null,
  is_mcn_managed: false,
  historical_order_count: 0,
  sales_performance_history: [],
  social_accounts: [],
  opportunities: [],
  communications: [],
  collaborations: [],
}

const defaultProps = {
  kol: baseKol,
  ownerOptions: [{ value: 1, label: 'Test User' }],
  canManageViews: true,
}

describe('Kol/Edit VI contract', () => {
  it('uses erp-form-page wrapper — no mvp-page', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.find('.erp-form-page').exists()).toBe(true)
    expect(wrapper.find('.mvp-page').exists()).toBe(false)
  })

  it('uses erp-form-content inner wrapper', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.find('.erp-form-content').exists()).toBe(true)
  })

  it('uses erp-form-card for section cards — no mvp-card', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.findAll('.erp-form-card').length).toBeGreaterThanOrEqual(5)
    expect(wrapper.find('.mvp-card').exists()).toBe(false)
  })

  it('uses erp-form-header-row for page header', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.find('.erp-form-header-row').exists()).toBe(true)
  })

  it('uses erp-form-title for page title', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.find('.erp-form-title').exists()).toBe(true)
    expect(wrapper.find('.erp-form-title').text()).toContain('Jane KOL')
  })

  it('uses sticky header actions instead of a bottom action bar', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.find('.kol-sticky-header').exists()).toBe(true)
    expect(wrapper.find('.kol-header-actions').exists()).toBe(true)
    expect(wrapper.find('.erp-form-actions').exists()).toBe(false)
  })

  it('primary submit button uses erp-btn erp-btn-primary', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    const btn = wrapper.find('button[type="submit"]')
    expect(btn.classes()).toContain('erp-btn')
    expect(btn.classes()).toContain('erp-btn-primary')
    expect(btn.text()).toContain('保存修改')
  })

  it('cancel link points back to kol detail and uses erp-btn-secondary', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    const cancelLinks = wrapper.findAll('a.erp-btn-secondary')
    expect(cancelLinks.some(a => a.attributes('href') === '/kol/library/1')).toBe(true)
  })

  it('uses a checkbox only for the M1.2A fulfillment requirement flag while social state stays segmented', async () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
    const createButton = wrapper.findAll('button').find(button => button.text() === '新增正式合作')
    expect(createButton).toBeTruthy()
    await createButton!.trigger('click')
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('仅需求标记，不会创建订单')
  })

  it('shows 业务画像 card heading', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.text()).toContain('业务画像')
  })

  it('shows the M1.2A formal collaboration section and explicit create action', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    const section = wrapper.find('#crm-collaborations')
    expect(section.exists()).toBe(true)
    expect(section.text()).toContain('正式合作')
    expect(section.text()).toContain('新增正式合作')
  })

  it('shows an explicit opportunity-to-collaboration conversion action only before conversion', () => {
    const props = {
      ...defaultProps,
      kol: {
        ...baseKol,
        opportunities: [{ id: 9, title: 'Confirmed deal', cooperation_type: '付费发帖', target_products: [], stage: 'confirmed', owner_id: 1, owner: { id: 1, name: 'Test User' }, estimated_cost: 100, currency: 'USD', expected_publish_at: null, next_follow_up_at: null, next_action: null, status: 'completed', notes: null }],
        collaborations: [],
      },
    }
    const wrapper = mount(Edit, { props })
    expect(wrapper.text()).toContain('转为正式合作')
  })

  it('shows 税务与收款 card heading', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.text()).toContain('税务与收款')
  })

  it('shows 收件地址 card heading', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.text()).toContain('收件地址')
  })

  it('shows 历史带货记录 card heading with the derived total', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.text()).toContain('历史带货记录')
  })

  it('shows 内部运营信息 card heading', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.text()).toContain('内部运营信息')
  })

  it('does not offer WeChat or Douyin or Telegram as contact fields', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.text()).not.toContain('微信号')
    expect(wrapper.text()).not.toContain('抖音')
    expect(wrapper.text()).not.toContain('Telegram')
  })

  it('keeps only phone and email contact inputs inside the shipping-address card', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    const addressCard = wrapper.findAll('.erp-form-card').find(card => card.text().includes('收件地址'))
    expect(addressCard).toBeTruthy()
    expect(addressCard!.text()).toContain('电话')
    expect(addressCard!.text()).toContain('邮箱')
    expect(wrapper.text()).not.toContain('WhatsApp')
    expect(wrapper.text()).not.toContain('其他联系方式')
    expect(addressCard!.find('.kol-address-grid').exists()).toBe(true)
    expect(addressCard!.findAll('.erp-form-field')).toHaveLength(8)
  })

  it('shows default primary Instagram and X rows when they do not exist', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    const platformSelects = wrapper.findAll('.kol-sa-row select')
    expect(platformSelects).toHaveLength(2)
    expect((platformSelects[0].element as HTMLSelectElement).value).toBe('instagram')
    expect((platformSelects[1].element as HTMLSelectElement).value).toBe('twitter')
    expect(wrapper.text()).not.toContain('法定名称')
  })

  it('shows payout fields including tax id', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.text()).toContain('Tax ID')
    expect(wrapper.text()).toContain('收款方式')
    expect(wrapper.text()).toContain('收款账户')
  })

  it('shows shipping address fields', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    expect(wrapper.text()).toContain('收件人')
    expect(wrapper.text()).toContain('详细地址')
    expect(wrapper.text()).not.toContain('地址行 2')
    expect(wrapper.text()).toContain('邮政编码')
  })

  it('status uses erp-segmented control', () => {
    const wrapper = mount(Edit, { props: defaultProps })
    const segmented = wrapper.findAll('.erp-segmented')
    expect(segmented.length).toBeGreaterThanOrEqual(1)
  })
})
