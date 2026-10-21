import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { routerPost, routerVisit } = vi.hoisted(() => ({ routerPost: vi.fn(), routerVisit: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props:['title'], template:'<title>{{ title }}</title>' },
  router: { post:routerPost, visit:routerVisit },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default:{ template:'<div><slot /></div>' } }))

const baseProps = {
  view:'pending',
  rows:{ data:[{
    line:1,order_id:1,erp_order_id:'007-0923-c50001',provider_reference:'C50001-007',order_url:'/customers/orders/1',
    recipient_name:'A',phone:'123',country:'DE',state:'DE-SN',city:'Dresden',postal_code:'01219',
    carrier:'4PX',service:'SD经济挂号-带电',tracking_number:null,shipment_status:'pending',shipped_at:null,
    review_status:'approved' as const,export_blockers:[],items:[{sku:'M2',quantity:1}],
  }],current_page:1,last_page:1,total:1 },
  viewCounts:{review:0,pending:1,'today-4px':0,'today-weship':0,exceptions:0,today:0,'4px':1,weship:0},
  canOperate:true,
  trackingImportProvider:'4px' as const,
  trackingImportPreview:{
    token:'a'.repeat(64),apply_count:1,noop_count:0,blocked_count:1,
    rows:[
      {row:2,order_id:1,order_number:'C50001-007',tracking_number:'4PX-1',carrier:'4PX',provider_status:'已出货',status:'apply',message:''},
      {row:3,order_id:2,order_number:'C50002-007',tracking_number:'',carrier:'4PX',provider_status:'已出货',status:'blocked',message:'尚未完成国内物流审核'},
    ],
  },
  providerStatus:{
    '4px':{export_ready:true,return_ready:true,label:'速达 / 4PX'},
    weship:{export_ready:true,return_ready:true,label:'纬狮 / Weship',note:'与速达共用 24 列下单模板 · XLSX 下载 · XLS/XLSX 回传'},
  },
}

describe('Domestic logistics production workbench', () => {
  beforeEach(() => { routerPost.mockClear(); routerVisit.mockClear() })

  it('shows the daily domestic flow with isolated 4PX and Weship outbound downloads', () => {
    const wrapper=mount(Index,{props:{...baseProps,view:'today-4px'}})
    expect(wrapper.text()).toContain('国内发货')
    expect(wrapper.text()).toContain('单个异常订单不会阻断其他正常订单')
    expect(wrapper.find('a[href="/logistics/china/export/4px"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('纬狮 / Weship')
    expect(wrapper.text()).not.toContain('Fail Closed')
    expect(wrapper.find('a[href="/logistics/china/export/weship"]').exists()).toBe(false)
    expect(wrapper.find('input[type="file"]').attributes('accept')).toBe('.xls,.xlsx')
  })


  it('shows only the matching provider operation card on provider-specific daily tabs', () => {
    const speeda=mount(Index,{props:{...baseProps,view:'today-4px',trackingImportPreview:null,trackingImportProvider:null}})
    expect(speeda.text()).toContain('速达 / 4PX')
    expect(speeda.text()).not.toContain('纬狮 / Weship')
    expect(speeda.find('a[href="/logistics/china/export/4px"]').exists()).toBe(true)

    const weship=mount(Index,{props:{...baseProps,view:'today-weship',trackingImportPreview:null,trackingImportProvider:null}})
    expect(weship.text()).toContain('纬狮 / Weship')
    expect(weship.text()).not.toContain('速达 / 4PX')
    expect(weship.text()).toContain('与速达共用 24 列下单模板')
    expect(weship.text()).not.toContain('Fail Closed')
    expect(weship.text()).toContain('下载纬狮下单表')
    expect(weship.text()).toContain('上传纬狮单号表')
    expect(weship.find('a[href="/logistics/china/export/weship"]').exists()).toBe(true)
  })

  it('shows a mixed-carrier ERP batch download/upload only on the pending work queue', () => {
    const pending=mount(Index,{props:{...baseProps,view:'pending',trackingImportPreview:null,trackingImportProvider:null}})
    expect(pending.find('a[href="/logistics/china/pending-batch/export"]').exists()).toBe(true)
    expect(pending.text()).toContain('上传批量单号表')
    expect(pending.text()).toContain('可混合承运商')
    expect(pending.text()).toContain('不替代承运商自己的下单模板')

    const history=mount(Index,{props:{...baseProps,view:'4px',trackingImportPreview:null,trackingImportProvider:null}})
    expect(history.find('a[href="/logistics/china/pending-batch/export"]').exists()).toBe(false)
    expect(history.text()).not.toContain('上传批量单号表')
  })

  it('keeps cumulative carrier history query-only and hides provider upload/download controls', () => {
    const speedaHistory=mount(Index,{props:{...baseProps,view:'4px',trackingImportPreview:null,trackingImportProvider:null}})
    expect(speedaHistory.find('.provider-grid').exists()).toBe(false)
    expect(speedaHistory.find('a[href="/logistics/china/export/4px"]').exists()).toBe(false)
    expect(speedaHistory.find('input[type="file"]').exists()).toBe(false)

    const weshipHistory=mount(Index,{props:{...baseProps,view:'weship',trackingImportPreview:null,trackingImportProvider:null}})
    expect(weshipHistory.find('.provider-grid').exists()).toBe(false)
    expect(weshipHistory.text()).not.toContain('上传纬狮单号表')
  })

  it('allows applying safe rows even when the preview contains blocked rows', async () => {
    const wrapper=mount(Index,{props:baseProps})
    expect(wrapper.text()).toContain('可应用 1 · 重复 0 · 阻断 1')
    const button=wrapper.findAll('button').find(b => b.text().includes('应用可安全行'))
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect(routerPost).toHaveBeenCalledWith(
      '/logistics/china/4px/tracking-import/apply',
      {token:'a'.repeat(64)},
      {preserveScroll:true},
    )
  })

  it('routes review approval and the Vita allocation queue without duplicating allocation logic', async () => {
    const wrapper=mount(Index,{props:{...baseProps,view:'review',rows:{...baseProps.rows,data:[{...baseProps.rows.data[0],review_status:'pending' as const}]}}})
    expect(wrapper.find('a[href="/logistics/wait-stock"]').text()).toContain('待分配')
    await wrapper.find('button.approve').trigger('click')
    expect(routerPost).toHaveBeenCalledWith('/logistics/china/orders/1/approve', {}, {preserveScroll:true,preserveState:true})
  })
})
