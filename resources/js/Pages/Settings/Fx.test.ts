import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Fx from './Fx.vue'

const put = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (value:any) => reactive({ ...value, processing:false, recentlySuccessful:false, errors:{}, put }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default:{ template:'<div><slot /></div>' } }))

const rows = [
  { currency:'USD', label:'美元', ecbRate:7.09, manualRate:7.12, automatic:false, effectiveRate:7.12, source:'manual', manualEditable:true },
  { currency:'EUR', label:'欧元', ecbRate:7.8, manualRate:null, automatic:true, effectiveRate:7.8, source:'ecb', manualEditable:true },
  { currency:'PHP', label:'菲律宾比索', ecbRate:0.122, manualRate:0.125, automatic:true, effectiveRate:0.122, source:'ecb', manualEditable:true },
  { currency:'CNY', label:'人民币', ecbRate:1, manualRate:null, automatic:false, effectiveRate:1, source:'fixed', manualEditable:false },
] as any
const reference = { source:'ECB', available:true, referenceDate:'2026-09-07', stale:false, refreshHours:12, policy:'explicit_mode' }

describe('Settings FX', () => {
  beforeEach(() => put.mockClear())

  it('renders explicit automatic/manual mode controls', () => {
    const wrapper = mount(Fx, { props:{ rows, reference } })
    expect(wrapper.text()).toContain('手动汇率设置')
    expect(wrapper.text()).toContain('按币种切换')
    expect(wrapper.text()).toContain('自动汇率')
    expect(wrapper.text()).toContain('手动汇率')
    expect(wrapper.findAll('[role="switch"]')).toHaveLength(3)
  })

  it('switches to automatic without clearing the saved manual rate', async () => {
    const wrapper = mount(Fx, { props:{ rows, reference } })
    const usdSwitch = wrapper.find('[aria-label="USD 切换自动汇率"]')
    const usdInput = wrapper.findAll('input').find((input) => input.element.value === '7.12')!

    expect(usdSwitch.attributes('aria-checked')).toBe('false')
    expect(wrapper.text()).toContain('7.1200')
    await usdSwitch.trigger('click')
    expect(usdSwitch.attributes('aria-checked')).toBe('true')
    expect(usdInput.element.value).toBe('7.12')
    expect(wrapper.text()).toContain('7.0900')

    const save = wrapper.findAll('button').find((button) => button.text() === '保存全部汇率')!
    await save.trigger('click')
    expect(put).toHaveBeenCalledWith('/settings/fx', { preserveScroll:true })
  })
})
