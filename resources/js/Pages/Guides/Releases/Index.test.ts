import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' } }))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

describe('Read-only changelog', () => {
  it('switches Markdown entries locally without navigation', async () => {
    const wrapper = mount(Index, {
      props: {
        initialDate: '2026-09-07',
        entries: [
          { date: '2026-09-07', html: '<p>Today entry</p>' },
          { date: '2026-09-06', html: '<p>Yesterday entry</p>' },
        ],
      },
    })

    expect(wrapper.text()).toContain('2026-09-07')
    expect(wrapper.html()).toContain('Today entry')

    await wrapper.get('[data-date="2026-09-06"]').trigger('click')

    expect(wrapper.html()).toContain('Yesterday entry')
    expect(window.location.search).toContain('date=2026-09-06')
    expect(wrapper.find('a[href="/settings/changelog/create"]').exists()).toBe(false)
  })
})
