import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

const patchMock = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<title />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (initial: Record<string, unknown>) => ({ ...initial, errors: {}, processing: false, patch: patchMock }),
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const procurementOrder = {
  id: 4,
  procurement_number: 'PO-20260904-0001',
  status: 'pending',
  status_label: '待采购',
  carrier: '顺丰',
  tracking_number: 'SF123456',
  source_order_id: 7,
  source_order_erp_id: '007-0908-c7',
  created_by_name: 'Andy',
  updated_by_name: 'Zhang',
  created_at: '2026-09-04T08:00:00Z',
  updated_at: '2026-09-04T09:00:00Z',
  status_updated_at: '2026-09-04T09:00:00Z',
  completion_reason: null,
  completed_at: null,
  items: [{ id: 10, sku: 'SKU-10', product_summary: '测试产品', source_quantity: 2, purchase_url: 'https://shop.test/item', purchase_size: 'M', purchase_quantity: 2 }],
}
const statusOptions = [{ value: 'pending', label: '待采购' }, { value: 'ordered', label: '已下单' }]

describe('Procurement/Orders/Show', () => {
  it('lets procurement operator edit status and logistics', async () => {
    patchMock.mockClear()
    const wrapper = mount(Show, { props: { procurementOrder, statusOptions, canOperate: true } })
    expect(wrapper.text()).toContain('PO-20260904-0001')
    expect(wrapper.text()).toContain('采购进度与物流')
    const selects = wrapper.findAll('select')
    expect(selects).toHaveLength(2)
    expect(wrapper.findAll('input')).toHaveLength(1)
    expect(selects[1].text()).toContain('顺丰速运')
    expect(selects[1].text()).toContain('京东物流')
    expect(selects[1].text()).toContain('中通快递')
    expect(selects[1].text()).toContain('邮政 EMS')
    expect(selects[1].text()).toContain('顺丰')
    await selects[0].setValue('ordered')
    await selects[1].setValue('顺丰速运')
    await wrapper.find('form').trigger('submit')
    expect(patchMock).toHaveBeenCalledWith('/purchase/orders/4', { preserveScroll: true })
    expect(wrapper.find('a[href="/customers/orders/7"]').exists()).toBe(true)
    const purchaseLink = wrapper.find('a[href="https://shop.test/item"]')
    expect(purchaseLink.attributes('target')).toBe('_blank')
    expect(purchaseLink.text()).toBe('https://shop.test/item')
    expect(purchaseLink.attributes('title')).toBe('https://shop.test/item')
  })


  it('opens Kuaidi100 and copies the tracking number', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    const wrapper = mount(Show, { props: { procurementOrder, statusOptions, canOperate: true } })

    const button = wrapper.get('[data-testid="query-tracking"]')
    expect(button.attributes('disabled')).toBeUndefined()
    await button.trigger('click')

    expect(open).toHaveBeenCalledWith('https://www.kuaidi100.com/', '_blank', 'noopener,noreferrer')
    expect(writeText).toHaveBeenCalledWith('SF123456')
    await vi.waitFor(() => expect(wrapper.text()).toContain('快递单号已复制，请在快递100粘贴查询'))
    open.mockRestore()
  })

  it('falls back to execCommand copy when Clipboard API is unavailable', async () => {
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined })
    const execCommand = vi.fn().mockReturnValue(true)
    Object.defineProperty(document, 'execCommand', { configurable: true, value: execCommand })
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    const wrapper = mount(Show, { props: { procurementOrder, statusOptions, canOperate: true } })

    await wrapper.get('[data-testid="query-tracking"]').trigger('click')

    expect(execCommand).toHaveBeenCalledWith('copy')
    expect(open).toHaveBeenCalledWith('https://www.kuaidi100.com/', '_blank', 'noopener,noreferrer')
    expect(wrapper.text()).toContain('快递单号已复制，请在快递100粘贴查询')
    Object.defineProperty(document, 'execCommand', { configurable: true, value: undefined })
    open.mockRestore()
  })

  it('disables one-click tracking query when tracking number is empty', () => {
    const wrapper = mount(Show, {
      props: { procurementOrder: { ...procurementOrder, tracking_number: null }, statusOptions, canOperate: true },
    })
    expect(wrapper.get('[data-testid="query-tracking"]').attributes('disabled')).toBeDefined()
  })


  it('shows automatic completion evidence as read-only record data', () => {
    const wrapper = mount(Show, { props: {
      procurementOrder: { ...procurementOrder, status: 'completed', status_label: '已完成', completion_reason: 'source_order_shipped', completed_at: '2026-09-05T03:00:00Z' },
      statusOptions,
      canOperate: true,
    } })
    expect(wrapper.text()).toContain('完成方式')
    expect(wrapper.text()).toContain('源订单已发货自动完成')
    expect(wrapper.text()).toContain('完成时间')
  })

  it('keeps workspace read only without operate permission', () => {
    const wrapper = mount(Show, { props: { procurementOrder, statusOptions, canOperate: false } })
    expect(wrapper.findAll('select')).toHaveLength(0)
    expect(wrapper.findAll('input')).toHaveLength(0)
    expect(wrapper.text()).toContain('待采购')
    expect(wrapper.text()).toContain('顺丰')
    expect(wrapper.text()).toContain('SF123456')
  })
})
