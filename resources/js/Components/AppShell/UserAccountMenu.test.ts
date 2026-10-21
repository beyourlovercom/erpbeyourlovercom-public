import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import UserAccountMenu from './UserAccountMenu.vue'

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn(), visit: vi.fn() } }))

const user = { id: 1, name: 'Test User', email: 'test@example.com' }

describe('UserAccountMenu', () => {
  beforeEach(() => vi.clearAllMocks())

  it.each([
    ['Test User', 'TU'],
    ['张三', '张'],
    ['', '?'],
  ])('derives the frozen initials for %s', (name, initials) => {
    const wrapper = mount(UserAccountMenu, { props: { user: { ...user, name }, navigationHidden: false } })
    expect(wrapper.get('.shell-account-trigger .shell-account-avatar').text()).toBe(initials)
  })

  it('opens and closes by click with a keyboard-accessible button contract', async () => {
    const wrapper = mount(UserAccountMenu, { attachTo: document.body, props: { user, navigationHidden: false } })
    const trigger = wrapper.get<HTMLButtonElement>('.shell-account-trigger')
    expect(trigger.element.tagName).toBe('BUTTON')
    expect(trigger.attributes('type')).toBe('button')
    expect(trigger.attributes('aria-expanded')).toBe('false')
    expect(trigger.attributes('aria-controls')).toBeTruthy()

    await trigger.trigger('click')
    expect(trigger.attributes('aria-expanded')).toBe('true')
    expect(wrapper.get('[role="menu"]').attributes('id')).toBe(trigger.attributes('aria-controls'))
    expect(wrapper.get('[role="menu"]').text()).toContain('Test User')
    expect(wrapper.get('[role="menu"]').text()).toContain('test@example.com')
    expect(document.activeElement).toBe(wrapper.findAll('.shell-account-action')[0].element)
    expect(wrapper.get('[role="menu"]').text()).toContain('个人信息')
    expect(wrapper.get('[role="menu"]').text()).toContain('修改密码')

    await trigger.trigger('click')
    expect(trigger.attributes('aria-expanded')).toBe('false')
    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('closes on Escape and restores focus to the trigger', async () => {
    const wrapper = mount(UserAccountMenu, { attachTo: document.body, props: { user, navigationHidden: false } })
    await wrapper.get('.shell-account-trigger').trigger('click')
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await wrapper.vm.$nextTick()
    expect(wrapper.get('.shell-account-trigger').attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(wrapper.get('.shell-account-trigger').element)
    wrapper.unmount()
  })

  it('closes when a pointer event occurs outside', async () => {
    const wrapper = mount(UserAccountMenu, { attachTo: document.body, props: { user, navigationHidden: false } })
    await wrapper.get('.shell-account-trigger').trigger('click')
    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
    await wrapper.vm.$nextTick()
    expect(wrapper.get('.shell-account-trigger').attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('opens self-service profile and password routes', async () => {
    const wrapper = mount(UserAccountMenu, { props: { user, navigationHidden: false } })
    await wrapper.get('.shell-account-trigger').trigger('click')
    const actions = wrapper.findAll('.shell-account-action')
    await actions[0].trigger('click')
    expect(router.visit).toHaveBeenCalledWith('/account/profile')

    await wrapper.get('.shell-account-trigger').trigger('click')
    await wrapper.findAll('.shell-account-action')[1].trigger('click')
    expect(router.visit).toHaveBeenCalledWith('/account/password')
  })

  it('posts logout through the existing route', async () => {
    const wrapper = mount(UserAccountMenu, { props: { user, navigationHidden: false } })
    await wrapper.get('.shell-account-trigger').trigger('click')
    await wrapper.get('.shell-account-logout').trigger('click')
    expect(router.post).toHaveBeenCalledWith('/logout')
  })

  it('closes its menu when the containing mobile navigation becomes hidden', async () => {
    const wrapper = mount(UserAccountMenu, { props: { user, navigationHidden: false } })
    await wrapper.get('.shell-account-trigger').trigger('click')
    expect(wrapper.find('[role="menu"]').exists()).toBe(true)
    await wrapper.setProps({ navigationHidden: true })
    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    expect(wrapper.get('.shell-account-trigger').attributes('aria-expanded')).toBe('false')
  })
})
