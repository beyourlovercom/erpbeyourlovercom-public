import { defineComponent, reactive } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { useAccountGridPreferences } from './useAccountGridPreferences'

const { get, put } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))
vi.mock('axios', () => ({ default: { get, put } }))

describe('useAccountGridPreferences required columns', () => {
  it('sanitizes stored preferences and keeps required keys visible', async () => {
    const Host = defineComponent({
      setup() {
        const grid = reactive(useAccountGridPreferences({
          namespace: 'grid.test.required.v1',
          definitions: [{ key: 'order_number', label: 'ERP订单号' }, { key: 'status', label: '状态' }],
          requiredKeys: ['order_number'],
          serverValue: { columns: [{ key: 'order_number', visible: false }, { key: 'status', visible: false }, { key: 'unknown', visible: true }] },
        }))
        return { grid }
      },
      template: '<div><span class="visible">{{ grid.visibleColumns.map(column => column.key).join(",") }}</span><button @click="grid.toggle(\'order_number\')">toggle</button></div>',
    })

    const wrapper = mount(Host)
    await flushPromises()
    expect(wrapper.get('.visible').text()).toBe('order_number')
    await wrapper.get('button').trigger('click')
    expect(wrapper.get('.visible').text()).toBe('order_number')
    expect(put).not.toHaveBeenCalled()
    expect(get).not.toHaveBeenCalled()
  })
})
