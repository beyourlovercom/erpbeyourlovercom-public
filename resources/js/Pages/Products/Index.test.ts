import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { get: vi.fn(), delete: vi.fn() },
  usePage: () => ({ url: '/products/list', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const emptyPage = { data: [], current_page: 1, last_page: 1, total: 0, per_page: 50, from: null, to: null, links: [] }

const makeProduct = (overrides = {}) => ({
  id: 1, woocommerce_product_id: 9001, source_type: 'woocommerce', product_type: 'simple',
  sku: null, name: 'Test', unit: null, is_active: true,
  shipping_weight_kg: null, standard_production_cost: null, standard_packaging_cost: null,
  cost_currency: null, sale_price: null, regular_price: '89.00',
  image_url: null, woo_url: null,
  ...overrides,
})

describe('Products/Index', () => {
  it('shows Chinese column headers', () => {
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('SKU')
    expect(wrapper.text()).toContain('产品名称')
    expect(wrapper.text()).toContain('来源')
    expect(wrapper.text()).toContain('单位')
    expect(wrapper.text()).toContain('运重')
    expect(wrapper.text()).toContain('标准生产成本')
    expect(wrapper.text()).toContain('标准包材成本')
    expect(wrapper.text()).toContain('成本币种')
    expect(wrapper.text()).toContain('售价')
    expect(wrapper.text()).toContain('原价')
    expect(wrapper.text()).toContain('状态')
    expect(wrapper.text()).toContain('操作')
  })

  it('renders blank values as —', () => {
    const product = makeProduct()
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('—')
    expect(wrapper.text()).toContain('89.00')
  })

  it('shows empty state when no products', () => {
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('暂无产品')
  })

  it('shows total product count', () => {
    const page = { ...emptyPage, total: 116 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('116')
  })

  it('renders SKU as Product360 link when SKU exists', () => {
    const product = makeProduct({ sku: 'TEST-SKU' })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: true } })
    const link = wrapper.find('a[href="/products/1"]')
    expect(link.exists()).toBe(true)
    expect(link.text()).toBe('TEST-SKU')
  })

  it('renders SKU as Product360 link when canOperate is false', () => {
    const product = makeProduct({ sku: 'TEST-SKU' })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.find('a[href="/products/1"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('TEST-SKU')
  })

  it('shows 新增产品 and 导入 CSV action buttons when canOperate', () => {
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {}, canOperate: true } })
    expect(wrapper.text()).toContain('新增产品')
    expect(wrapper.text()).toContain('导入 CSV')
  })

  it('hides 新增产品 and 导入 CSV when view-only', () => {
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {}, canOperate: false } })
    expect(wrapper.text()).not.toContain('新增产品')
    expect(wrapper.text()).not.toContain('导入 CSV')
  })

  it('shows 编辑 and 删除 row actions when canOperate', () => {
    const product = makeProduct({ id: 5 })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: true } })
    expect(wrapper.find('a[href="/products/5/edit"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/products/5"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('删除')
  })

  it('hides 编辑 and 删除 row actions when view-only', () => {
    const product = makeProduct({ id: 5 })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.find('a[href="/products/5/edit"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/products/5"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('编辑')
    expect(wrapper.text()).not.toContain('删除')
  })

  it('shows delete confirmation dialog when 删除 clicked', async () => {
    const product = makeProduct({ id: 5 })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: true } })
    const deleteBtn = wrapper.findAll('button').find(b => b.text() === '删除')
    expect(deleteBtn).toBeDefined()
    await deleteBtn!.trigger('click')
    expect(wrapper.text()).toContain('确认删除此产品')
  })

  it('shows source label Woo for woocommerce source', () => {
    const product = makeProduct({ source_type: 'woocommerce' })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('Woo')
  })

  it('shows source label 手动 for manual source', () => {
    const product = makeProduct({ source_type: 'manual', woocommerce_product_id: null })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('手动')
  })

  it('shows 启用 for active products', () => {
    const product = makeProduct({ is_active: true })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('启用')
  })

  it('shows 停用 for inactive products', () => {
    const product = makeProduct({ is_active: false })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('停用')
  })

  it('renders real media and official product links in the sheet grid', () => {
    const p = makeProduct({
      sku: 'GRID-IMG', name: 'Image test',
      image_url: '/products/media/99',
      woo_url: 'https://www.beyourlover.com/image-test',
      units_per_carton: 24,
      available_stock: null,
    })
    const wrapper = mount(Index, { props: { products: { ...emptyPage, total: 1, data: [p] }, filters: {}, canOperate: false } })
    expect(wrapper.find('img[src="/products/media/99"]').exists()).toBe(true)
    expect(wrapper.find('a[href="https://www.beyourlover.com/image-test"]').attributes('rel')).toContain('noopener')
    expect(wrapper.text()).toContain('24')
  })

  it('exposes account-backed column settings, visible-column export and drag ordering', async () => {
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {}, canOperate: false } })
    expect(wrapper.find('a[href^="/products/list/export?"]').exists()).toBe(true)
    const settings = wrapper.find('button[aria-label="列设置"]')
    expect(settings.exists()).toBe(true)
    await settings.trigger('click')
    expect(wrapper.text()).toContain('恢复默认')
    expect(wrapper.find('input[aria-label="SKU固定显示"]').exists()).toBe(true)
  })

  it('supports page-size changes including all and preserves active search', async () => {
    const { router } = await import('@inertiajs/vue3')
    const wrapper = mount(Index, { props: { products: emptyPage, filters: { search: 'test' }, canOperate: false } })
    await wrapper.find('select[aria-label="每页显示条数"]').setValue('all')
    expect(router.get).toHaveBeenCalledWith(
      '/products/list',
      expect.objectContaining({ search: 'test', per_page: 'all', page: 1 }),
      expect.objectContaining({ preserveState: true }),
    )
  })

  it('places the styled search inside the page header rather than a separate toolbar row', async () => {
    const { router } = await import('@inertiajs/vue3')
    vi.mocked(router.get).mockClear()
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {}, canOperate: true } })
    const searchForm = wrapper.find('.mvp-page-heading form.product-search')
    expect(searchForm.exists()).toBe(true)
    expect(searchForm.find('svg.product-search-icon').exists()).toBe(true)
    expect(wrapper.find('.grid-toolbar').exists()).toBe(false)
    await searchForm.find('input').setValue('SKU-12')
    await searchForm.trigger('submit')
    expect(router.get).toHaveBeenCalledWith('/products/list', expect.objectContaining({ search: 'SKU-12', page: 1 }), expect.anything())
  })

  it('shows numeric pagination above the table with working page navigation', async () => {
    const { router } = await import('@inertiajs/vue3')
    vi.mocked(router.get).mockClear()
    const page = { ...emptyPage, current_page: 2, last_page: 5, total: 250, from: 51, to: 100 }
    const wrapper = mount(Index, { props: { products: page, filters: { search: 'grid', per_page: '50' }, canOperate: false } })
    const nav = wrapper.find('nav[aria-label="产品列表顶部分页"]')
    expect(nav.exists()).toBe(true)
    expect(nav.find('button[aria-current="page"]').text()).toBe('2')
    const thirdPage = nav.findAll('button').find(button => button.text() === '3')
    expect(thirdPage).toBeDefined()
    await thirdPage!.trigger('click')
    expect(router.get).toHaveBeenCalledWith('/products/list', expect.objectContaining({ page: 3, search: 'grid', per_page: '50' }), expect.anything())
    expect(wrapper.find('nav[aria-label="产品列表底部分页"]').exists()).toBe(true)
  })

  it('hides a broken image and shows an empty marker instead of a broken thumbnail', async () => {
    const product = makeProduct({ image_url: 'https://img.beyourlover.com/sku.jpg' })
    const wrapper = mount(Index, { props: { products: { ...emptyPage, total: 1, data: [product] }, filters: {}, canOperate: false } })
    expect(wrapper.find('.product-thumbnail').exists()).toBe(true)
    await wrapper.find('.product-thumbnail').trigger('error')
    expect(wrapper.find('.product-thumbnail').exists()).toBe(false)
    expect(wrapper.find('.product-image-empty').text()).toBe('—')
  })

})
