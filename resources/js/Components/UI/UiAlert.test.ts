import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import UiAlert from './UiAlert.vue'

describe('UiAlert', () => {
    it('maps error to alert/assertive and other tones to status/polite', () => {
        const error = mount(UiAlert, { props: { tone: 'error' }, slots: { default: '登录失败' } })
        expect(error.attributes('role')).toBe('alert')
        expect(error.attributes('aria-live')).toBe('assertive')
        const info = mount(UiAlert, { props: { tone: 'info' }, slots: { default: '信息' } })
        expect(info.attributes('role')).toBe('status')
        expect(info.attributes('aria-live')).toBe('polite')
    })

    it.each(['info', 'success', 'warning', 'error'] as const)('renders the finite %s tone with a textual status', (tone) => {
        const wrapper = mount(UiAlert, { props: { tone }, slots: { default: '内容' } })
        expect(wrapper.classes()).toContain(`ui-alert--${tone}`)
        expect(wrapper.text()).toMatch(/信息|成功|警告|错误/)
    })

    it('renders title props and slot overrides', () => {
        expect(mount(UiAlert, { props: { title: '标题' }, slots: { default: '内容' } }).text()).toContain('标题')
        expect(mount(UiAlert, { props: { title: '标题' }, slots: { title: '覆盖标题', default: '内容' } }).text()).toContain('覆盖标题')
    })

    it('only renders and emits dismiss when configured', async () => {
        expect(mount(UiAlert, { slots: { default: '内容' } }).find('button').exists()).toBe(false)
        const dismissible = mount(UiAlert, { props: { dismissible: true }, slots: { default: '内容' } })
        await dismissible.get('[aria-label="关闭提示"]').trigger('click')
        expect(dismissible.emitted('dismiss')).toHaveLength(1)
    })

    it('removes live-region behavior when live is off', () => {
        const wrapper = mount(UiAlert, { props: { live: 'off' }, slots: { default: '静态信息' } })
        expect(wrapper.attributes('role')).toBeUndefined()
        expect(wrapper.attributes('aria-live')).toBeUndefined()
    })

    it('preserves a caller region role when live is off', () => {
        const wrapper = mount(UiAlert, { props: { live: 'off' }, attrs: { role: 'region' }, slots: { default: '静态区域' } })
        expect(wrapper.attributes('role')).toBe('region')
        expect(wrapper.attributes('aria-live')).toBeUndefined()
    })

    it('rejects a caller alert role that conflicts with polite live behavior', () => {
        const wrapper = mount(UiAlert, { props: { live: 'polite' }, attrs: { role: 'alert', 'aria-live': 'assertive' }, slots: { default: '礼貌通知' } })
        expect(wrapper.attributes('role')).toBe('status')
        expect(wrapper.attributes('aria-live')).toBe('polite')
    })

    it('filters role, live and other unfrozen root attributes before final binding', async () => {
        const click = vi.fn()
        const wrapper = mount(UiAlert, {
            props: { live: 'polite' },
            attrs: { role: 'button', 'aria-live': 'assertive', tabindex: '0', title: 'unfrozen', 'data-test': 'alert', onClick: click },
            slots: { default: '通知' },
        })
        expect(wrapper.attributes('role')).toBe('status')
        expect(wrapper.attributes('aria-live')).toBe('polite')
        expect(wrapper.attributes('tabindex')).toBeUndefined()
        expect(wrapper.attributes('title')).toBeUndefined()
        expect(wrapper.attributes('data-test')).toBe('alert')
        await wrapper.trigger('click')
        expect(click).not.toHaveBeenCalled()
    })
})
