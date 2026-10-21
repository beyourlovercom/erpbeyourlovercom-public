import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import WarehouseShow from './WarehouseShow.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (initial: Record<string, any>) => ({ ...initial, processing: false, errors: {}, post, patch: vi.fn(), delete: vi.fn(), reset: vi.fn(), defaults: vi.fn(), transform(fn: (data: any) => any) { Object.assign(this, fn(this)); return this } }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }))
vi.mock('./components/InventorySkuCombobox.vue', () => ({ default: { template: '<input />' } }))

const warehouse: any = { id:1,slug:'us-la',public_slug:'us-la',code:'US-LA',name:'美西 MorePlus 仓',name_en:'US West · MorePlus',role_label:'海外履约仓',bill_import:{provider:'moreplus',label:'MorePlus',extension:'xlsx',format_label:'XLSX',accept:'.xlsx',max_kb:16384},is_fulfillment_warehouse:true,is_inventory_only_warehouse:false,country_code:'US',address_line1:null,address_line2:null,city:null,state_region:null,postal_code:null,timezone:null,type:'warehouse',ownership_type:'owned',is_domestic:false,is_active:true,priority:10,fulfillment_available_on:null,fulfillment_state:'ready',fulfillment_state_label:'可履约',replenishment_lead_time_days:null,replenishment_safety_stock_days:null,replenishment_target_cover_days:null,billing_markdown:null,service_regions:['US'],last_inventory_sync_at:null,inventories:[] }
const costCenter = { months:[{ month:'2026-09',label:'2026年09月',currencies:[{currency:'USD',total:180,shipping:100,parcel_outbound:50,storage:30,other:0}],entries:[{id:1,category:'shipping',category_label:'Shipping / 运费',amount:100,currency:'USD',note:'test',source_type:'manual',source_reference:null,created_at:null}]},{month:'2026-08',label:'2026年08月',currencies:[],entries:[]},{month:'2026-07',label:'2026年07月',currencies:[],entries:[]}] }
const baseProps={warehouse,clearanceProducts:[],clearanceCandidates:[],costCenter,billImportPreview:null,canOperate:false}

describe('Warehouse Cost Center P3',()=>{
  it('shows three month tabs and the four canonical cost KPIs directly',()=>{
    const wrapper=mount(WarehouseShow,{props:{...baseProps,canManage:false}})
    expect(wrapper.text()).toContain('最近 3 个月仓库费用')
    expect(wrapper.text()).toContain('2026年09月')
    expect(wrapper.text()).toContain('2026年08月')
    expect(wrapper.text()).toContain('2026年07月')
    expect(wrapper.text()).toContain('Total')
    expect(wrapper.text()).toContain('180.00')
    expect(wrapper.text()).toContain('Shipping / 运费')
    expect(wrapper.text()).toContain('Parcel Outbound / 出库处理')
    expect(wrapper.text()).toContain('Storage / 仓储费')
    expect(wrapper.find('input[type="month"]').exists()).toBe(false)
  })

  it('allows warehouse managers to see the manual cost entry form',()=>{
    const wrapper=mount(WarehouseShow,{props:{...baseProps,canManage:true}})
    expect(wrapper.find('input[type="month"]').exists()).toBe(true)
    expect(wrapper.find('input[placeholder="USD"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('记录费用')
  })

  it('renders bill import from warehouse capability instead of slug hard-code',()=>{
    const enabled=mount(WarehouseShow,{props:{...baseProps,canManage:true}})
    expect(enabled.text()).toContain('导入 MorePlus 仓库账单')
    const generic={...warehouse,slug:'us-ny',public_slug:'us-ny',code:'US-NY',name:'美东纽约仓',bill_import:null}
    const disabled=mount(WarehouseShow,{props:{...baseProps,warehouse:generic,canManage:true}})
    expect(disabled.text()).not.toContain('导入 MorePlus 仓库账单')
    expect(disabled.find('.cost-import-panel').exists()).toBe(false)
  })
})
