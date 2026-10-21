import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { globalFeedback, publishGlobalFeedback } from '../../lib/globalFeedback'
import Create from './Create.vue'

const postMock = vi.hoisted(() => vi.fn())

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  useForm: (initial: Record<string, unknown>) => ({
    ...initial,
    processing: false,
    errors: {},
    post: postMock,
  }),
  usePage: () => ({ url: '/customers/orders/create', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

vi.mock('../../Components/Products/SkuCombobox.vue', () => ({
  default: { props: ['modelValue', 'productId'], template: '<input class="sku-combobox-stub" />' },
}))

const customerProps = {
  defaultOrderKind: 'customer',
  orderKindLabel: '客户订单',
  generatedExternalOrderNumber: 'CUS20260825ABC007',
}

const kolProps = {
  defaultOrderKind: 'kol_fulfillment',
  orderKindLabel: '网红履约订单',
  generatedExternalOrderNumber: 'KOL20260825XYZ007',
}

describe('Orders/Create V2', () => {
  beforeEach(() => {
    postMock.mockReset()
    publishGlobalFeedback({ state: 'IDLE', title: '', message: '' })
  })

  afterEach(() => publishGlobalFeedback({ state: 'IDLE', title: '', message: '' }))

  it('renders 新建客户订单 title for customer route', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.text()).toContain('新建客户订单')
  })

  it('renders 新建网红履约订单 title for kol route', () => {
    const wrapper = mount(Create, { props: kolProps })
    expect(wrapper.text()).toContain('新建网红履约订单')
  })

  it('renders four business section cards', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.text()).toContain('订单信息')
    expect(wrapper.text()).toContain('收件与地址')
    expect(wrapper.text()).toContain('订单商品')
    expect(wrapper.text()).toContain('内部备注')
  })

  it('shows no identity strip on Create', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.ord-identity-strip').exists()).toBe(false)
  })

  it('shows 创建订单 as the only primary submit action', () => {
    const wrapper = mount(Create, { props: customerProps })
    const submitBtn = wrapper.find('button[type="submit"]')
    expect(submitBtn.exists()).toBe(true)
    expect(submitBtn.text()).toBe('创建订单')
  })

  it('disables submit button when processing', () => {
    const wrapper = mount(Create, { props: customerProps })
    const submitBtn = wrapper.find('button[type="submit"]')
    expect(submitBtn.attributes('disabled')).toBeUndefined()
  })

  it('shows 取消 secondary action linking to list', () => {
    const wrapper = mount(Create, { props: customerProps })
    const actionBar = wrapper.find('.erp-form-header-actions')
    const cancelLink = actionBar.find('a[href="/customers/orders"]')
    expect(cancelLink.exists()).toBe(true)
    expect(cancelLink.text()).toBe('取消')
  })

  it('shows 取消 linking to kol list for kol route', () => {
    const wrapper = mount(Create, { props: kolProps })
    const cancelLink = wrapper.find('a[href="/kol/orders"]')
    expect(cancelLink.exists()).toBe(true)
  })

  it('renders SKU combobox for product selection in items', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.sku-combobox-stub').exists()).toBe(true)
  })

  it('defaults manual orders to USD and labels unit price with currency', () => {
    const wrapper = mount(Create, { props: customerProps })
    const currencySelect = wrapper.find('select')
    expect(currencySelect.exists()).toBe(true)
    expect((currencySelect.element as HTMLSelectElement).value).toBe('USD')
    expect(wrapper.text()).toContain('单价（USD）')
  })

  it('renders order date field', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('input[type="date"]').exists()).toBe(true)
  })

  it('pre-fills generated external order number', () => {
    const wrapper = mount(Create, { props: customerProps })
    const input = wrapper.find('#external_order_number')
    expect(input.exists()).toBe(true)
    expect((input.element as HTMLInputElement).value).toBe('CUS20260825ABC007')
  })

  it('shows address fields', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.text()).toContain('收件人姓名')
    expect(wrapper.text()).toContain('联系电话')
    expect(wrapper.text()).toContain('国家')
    expect(wrapper.text()).toContain('省/州')
    expect(wrapper.text()).toContain('城市')
    expect(wrapper.text()).toContain('邮政编码')
    expect(wrapper.text()).toContain('地址行 1')
    expect(wrapper.text()).toContain('地址行 2')
  })

  it('renders note textarea', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('textarea').exists()).toBe(true)
  })

  it('shows add item button', () => {
    const wrapper = mount(Create, { props: customerProps })
    const addBtn = wrapper.findAll('button[type="button"]').find(b => b.text().includes('添加商品'))
    expect(addBtn).toBeTruthy()
  })

  // ERP V2 VI contract — no mvp-input dependency
  it('uses erp-form-page outer container', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.erp-form-page').exists()).toBe(true)
  })

  it('uses erp-form-card for section cards', () => {
    const wrapper = mount(Create, { props: customerProps })
    const cards = wrapper.findAll('.erp-form-card')
    expect(cards.length).toBeGreaterThanOrEqual(4)
  })

  it('uses erp-input for editable fields — no mvp-input class', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.mvp-input').exists()).toBe(false)
    expect(wrapper.findAll('.erp-input').length).toBeGreaterThan(0)
  })

  it('uses erp-form-label for field labels', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.erp-form-label').exists()).toBe(true)
    expect(wrapper.find('.mvp-label').exists()).toBe(false)
  })

  it('uses erp-btn for action buttons', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('.erp-btn').exists()).toBe(true)
    expect(wrapper.find('.mvp-button').exists()).toBe(false)
  })

  it('uses erp-textarea for note field', () => {
    const wrapper = mount(Create, { props: customerProps })
    expect(wrapper.find('textarea.erp-textarea').exists()).toBe(true)
  })

  it('shows readonly div for order kind (not editable input)', () => {
    const wrapper = mount(Create, { props: customerProps })
    const readonlyDivs = wrapper.findAll('.erp-readonly-input')
    const texts = readonlyDivs.map(d => d.text())
    expect(texts.some(t => t.includes('客户订单'))).toBe(true)
    expect(texts.some(t => t.includes('手动创建'))).toBe(true)
  })

  it('publishes submitting feedback when order creation starts', async () => {
    const wrapper = mount(Create, { props: customerProps })
    await wrapper.get('form').trigger('submit')

    const callbacks = postMock.mock.calls[0]?.[1] as { onStart?: () => void }
    callbacks.onStart?.()

    expect(globalFeedback.value).toMatchObject({
      state: 'SUBMITTING',
      title: '正在创建订单',
    })
  })

  it('maps missing Product validation to a human-readable blocker', async () => {
    const wrapper = mount(Create, { props: customerProps })
    await wrapper.get('form').trigger('submit')

    const callbacks = postMock.mock.calls[0]?.[1] as { onError?: (errors: Record<string, string>) => void }
    callbacks.onError?.({ 'items.0.product_id': 'The items.0.product_id field is required.' })

    expect(globalFeedback.value).toEqual({
      state: 'BLOCKED',
      title: '订单无法创建',
      message: '请先选择订单商品',
      next: '选择 SKU / 产品后重新提交',
    })
    expect(JSON.stringify(globalFeedback.value)).not.toContain('items.0.product_id')
  })

  it('posts customer and KOL forms to their existing endpoints', async () => {
    await mount(Create, { props: customerProps }).get('form').trigger('submit')
    expect(postMock.mock.calls[0]?.[0]).toBe('/customers/orders')

    postMock.mockReset()
    await mount(Create, { props: kolProps }).get('form').trigger('submit')
    expect(postMock.mock.calls[0]?.[0]).toBe('/kol/orders')
  })
})
