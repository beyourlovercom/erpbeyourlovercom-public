import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PermissionMatrix from './PermissionMatrix.vue'

const catalog = {
  通用: { label: '通用', permissions: [{ key: 'dashboard.view', label: '查看仪表盘' }] },
  系统: { label: '系统', permissions: [{ key: 'system.permissions.manage', label: '管理系统权限' }] },
}

describe('PermissionMatrix', () => {
  it('renders Chinese group labels', () => {
    const wrapper = mount(PermissionMatrix, {
      props: { modelValue: [], catalog, disabled: false },
    })
    expect(wrapper.text()).toContain('通用')
    expect(wrapper.text()).toContain('系统')
  })

  it('renders Chinese permission labels', () => {
    const wrapper = mount(PermissionMatrix, {
      props: { modelValue: [], catalog, disabled: false },
    })
    expect(wrapper.text()).toContain('查看仪表盘')
    expect(wrapper.text()).toContain('管理系统权限')
  })

  it('shows checked state for modelValue keys', () => {
    const wrapper = mount(PermissionMatrix, {
      props: { modelValue: ['dashboard.view'], catalog, disabled: false },
    })
    const checkboxes = wrapper.findAll('input[type="checkbox"]')
    expect((checkboxes[0].element as HTMLInputElement).checked).toBe(true)
    expect((checkboxes[1].element as HTMLInputElement).checked).toBe(false)
  })

  it('emits update:modelValue on checkbox toggle', async () => {
    const wrapper = mount(PermissionMatrix, {
      props: { modelValue: [], catalog, disabled: false },
    })
    await wrapper.find('input[type="checkbox"]').trigger('change')
    const emitted = wrapper.emitted('update:modelValue')
    expect(emitted).toBeTruthy()
    expect(emitted![0][0]).toContain('dashboard.view')
  })

  it('全选 button selects all permissions', async () => {
    const wrapper = mount(PermissionMatrix, {
      props: { modelValue: [], catalog, disabled: false },
    })
    await wrapper.find('button').trigger('click')
    const emitted = wrapper.emitted('update:modelValue')
    expect(emitted).toBeTruthy()
    const val = emitted![0][0] as string[]
    expect(val).toContain('dashboard.view')
    expect(val).toContain('system.permissions.manage')
  })

  it('取消全选 button clears all permissions', async () => {
    const wrapper = mount(PermissionMatrix, {
      props: { modelValue: ['dashboard.view', 'system.permissions.manage'], catalog, disabled: false },
    })
    const buttons = wrapper.findAll('button')
    const clearBtn = buttons.find((b) => b.text() === '取消全选')
    expect(clearBtn).toBeTruthy()
    await clearBtn!.trigger('click')
    const emitted = wrapper.emitted('update:modelValue')
    expect(emitted).toBeTruthy()
    expect(emitted![0][0]).toEqual([])
  })

  it('disables checkboxes and hides action buttons in disabled mode', () => {
    const wrapper = mount(PermissionMatrix, {
      props: { modelValue: [], catalog, disabled: true },
    })
    const checkboxes = wrapper.findAll('input[type="checkbox"]')
    checkboxes.forEach((cb) => expect((cb.element as HTMLInputElement).disabled).toBe(true))
    expect(wrapper.find('.permission-matrix-actions').exists()).toBe(false)
  })

  it('does not emit on toggle when disabled', async () => {
    const wrapper = mount(PermissionMatrix, {
      props: { modelValue: [], catalog, disabled: true },
    })
    await wrapper.find('input[type="checkbox"]').trigger('change')
    expect(wrapper.emitted('update:modelValue')).toBeFalsy()
  })
})
