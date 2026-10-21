import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({ Head:{template:'<span />'}, Link:{props:['href'],template:'<a :href="href"><slot /></a>'}, router:{put:vi.fn()} }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default:{template:'<div><slot /></div>'} }))

describe('Settings changelog summary', () => {
  it('links the latest Markdown changelog without exposing an editor', () => {
    const release = { id: 20260907, version: '2026-09-07', title: 'ERP 开发记录', summary: null, released_on: '2026-09-07' }
    const wrapper = mount(Index, { props: { currentRelease: release, recentReleases: [release] } })

    expect(wrapper.text()).toContain('2026-09-07')
    expect(wrapper.find('a[href="/settings/changelog"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/settings/changelog?date=2026-09-07"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/settings/fx"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/settings/changelog/create"]').exists()).toBe(false)
  })
})
