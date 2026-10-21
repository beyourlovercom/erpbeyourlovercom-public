import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Edit from './Edit.vue'

const post = vi.fn()
const patch = vi.fn()
const put = vi.fn()

const makeForm = (initial: Record<string, unknown>) => ({
  ...initial,
  processing: false,
  errors: {} as Record<string, string>,
  post,
  patch,
  put,
  reset: vi.fn(),
})

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn() },
  usePage: () => ({
    url: '/settings/users/1/edit',
    props: {
      auth: { user: { id: 99, name: 'Admin', email: 'admin@example.test' } },
      shellNavigation: [],
    },
  }),
  useForm: (initial: Record<string, unknown>) => makeForm(initial),
}))

vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

vi.mock('../../../Components/PermissionMatrix.vue', () => ({
  default: {
    props: ['modelValue', 'catalog', 'disabled'],
    emits: ['update:modelValue'],
    template: '<div data-testid="permission-matrix" :data-disabled="disabled">matrix</div>',
  },
}))

const baseUser = {
  id: 1,
  name: 'Test User',
  email: 'test@example.test',
  is_active: true,
  permission_mode: 'role',
  custom_permissions: [],
  role: { key: 'role_preset_writer', label: '写手', preset: 'writer' },
}

const catalog = {
  通用: { label: '通用', permissions: [{ key: 'dashboard.view', label: '查看仪表盘' }] },
  指南: { label: '指南', permissions: [{ key: 'guides.view', label: '查看指南' }] },
}

const rolePresets = [{ value: 'writer', label: '写手' }, { value: 'administrator', label: '管理员' }]
const rolePermissions = ['dashboard.view', 'guides.view']

describe('Edit page permission matrix section', () => {
  it('shows 跟随角色默认 radio selected by default for role mode user', () => {
    const wrapper = mount(Edit, {
      props: { user: baseUser, rolePresets, isSelf: false, permissionCatalog: catalog, rolePermissions },
    })
    const radios = wrapper.findAll('input[type="radio"]')
    const roleRadio = radios.find((r) => (r.element as HTMLInputElement).value === 'role')
    expect((roleRadio?.element as HTMLInputElement | undefined)?.checked).toBe(true)
  })

  it('shows 自定义权限 radio selected for custom mode user', () => {
    const customUser = { ...baseUser, permission_mode: 'custom', custom_permissions: ['dashboard.view'] }
    const wrapper = mount(Edit, {
      props: { user: customUser, rolePresets, isSelf: false, permissionCatalog: catalog, rolePermissions },
    })
    const radios = wrapper.findAll('input[type="radio"]')
    const customRadio = radios.find((r) => (r.element as HTMLInputElement).value === 'custom')
    expect((customRadio?.element as HTMLInputElement | undefined)?.checked).toBe(true)
  })

  it('renders PermissionMatrix as disabled in role mode', () => {
    const wrapper = mount(Edit, {
      props: { user: baseUser, rolePresets, isSelf: false, permissionCatalog: catalog, rolePermissions },
    })
    const matrix = wrapper.find('[data-testid="permission-matrix"]')
    expect(matrix.attributes('data-disabled')).toBe('true')
  })

  it('renders PermissionMatrix as enabled in custom mode', () => {
    const customUser = { ...baseUser, permission_mode: 'custom', custom_permissions: ['guides.view'] }
    const wrapper = mount(Edit, {
      props: { user: customUser, rolePresets, isSelf: false, permissionCatalog: catalog, rolePermissions },
    })
    const matrix = wrapper.find('[data-testid="permission-matrix"]')
    expect(matrix.attributes('data-disabled')).toBe('false')
  })

  it('shows 保存权限 and 恢复角色默认权限 buttons', () => {
    const wrapper = mount(Edit, {
      props: { user: baseUser, rolePresets, isSelf: false, permissionCatalog: catalog, rolePermissions },
    })
    expect(wrapper.text()).toContain('保存权限')
    expect(wrapper.text()).toContain('恢复角色默认权限')
  })

  it('calls post on 保存权限 button click', async () => {
    const wrapper = mount(Edit, {
      props: { user: baseUser, rolePresets, isSelf: false, permissionCatalog: catalog, rolePermissions },
    })
    const saveBtn = wrapper.findAll('button').find((b) => b.text() === '保存权限')
    expect(saveBtn).toBeTruthy()
    await saveBtn!.trigger('click')
    expect(post).toHaveBeenCalled()
  })
})
