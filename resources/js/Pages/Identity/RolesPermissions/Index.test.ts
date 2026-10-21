import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RolesPermissionsIndex from './Index.vue'

const put = vi.fn()
let forms: Record<string, { permissions: string[]; processing: boolean; errors: Record<string, string>; put: typeof put }>

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { put: vi.fn() },
  usePage: () => ({
    url: '/settings/roles-permissions',
    props: {
      auth: { user: { id: 1, name: 'Admin', email: 'admin@example.test' } },
      shellNavigation: [],
    },
  }),
  useForm: (initial: { permissions: string[] }) => {
    const form = { ...initial, processing: false, errors: {} as Record<string, string>, put }
    return form
  },
}))

vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

vi.mock('../../../Components/PermissionMatrix.vue', () => ({
  default: {
    props: ['modelValue', 'catalog', 'disabled'],
    emits: ['update:modelValue'],
    template: '<div data-testid="permission-matrix">matrix</div>',
  },
}))

const catalog = {
  通用: { label: '通用', permissions: [{ key: 'dashboard.view', label: '查看仪表盘' }] },
}

const presets = [
  { preset: 'logistics', label: '物流', permissions: ['dashboard.view'] },
  { preset: 'sales_representative', label: '业务员', permissions: [] },
  { preset: 'kol_specialist', label: '网红专员', permissions: [] },
  { preset: 'kol_manager', label: '网红经理', permissions: [] },
  { preset: 'finance', label: '财务', permissions: [] },
  { preset: 'operations', label: '运营', permissions: [] },
  { preset: 'writer', label: '写手', permissions: [] },
  { preset: 'administrator', label: '管理员', permissions: [] },
]

describe('RolesPermissions/Index', () => {
  it('renders all eight preset tabs with Chinese labels', () => {
    const wrapper = mount(RolesPermissionsIndex, {
      props: { presets, permissionCatalog: catalog },
    })
    const labels = ['物流', '业务员', '网红专员', '网红经理', '财务', '运营', '写手', '管理员']
    labels.forEach((label) => expect(wrapper.text()).toContain(label))
  })

  it('shows permission matrix for active preset', () => {
    const wrapper = mount(RolesPermissionsIndex, {
      props: { presets, permissionCatalog: catalog },
    })
    expect(wrapper.find('[data-testid="permission-matrix"]').exists()).toBe(true)
  })

  it('switches active preset on tab click', async () => {
    const wrapper = mount(RolesPermissionsIndex, {
      props: { presets, permissionCatalog: catalog },
    })
    const buttons = wrapper.findAll('.roles-tab-bar button')
    await buttons[1].trigger('click')
    expect(wrapper.text()).toContain('业务员 权限设置')
  })

  it('calls put on save button click', async () => {
    const wrapper = mount(RolesPermissionsIndex, {
      props: { presets, permissionCatalog: catalog },
    })
    await wrapper.find('.mvp-form-actions button').trigger('click')
    expect(put).toHaveBeenCalled()
  })
})
