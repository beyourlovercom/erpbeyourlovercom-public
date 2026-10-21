import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApprovalCenter from './ApprovalCenter.vue'

const { postMock } = vi.hoisted(() => ({ postMock: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props:['title'], template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: postMock },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const kolWorkspace = {
  module: 'kols' as const, pending_count: 1, history_count: 1,
  pending: [{ id:5,title:'Pending KOL',detail_href:'/kol/library/5',owner_name:'Owner A',country_code:'US',platform:'instagram',handle:'@pending',reason_code:'policy_all_requires_manager',created_at:'2026-09-01T01:00:00+00:00',submitted_at:'2026-09-01T01:10:00+00:00' }],
  history: [{ record_key:'kol:20',id:9,title:'Approved Creator',detail_href:'/kol/library/9',outcome:'approved' as const,actor_name:'Manager A',decided_at:'2026-09-01T04:00:00+00:00',source:'manager_manual',reason:'资料完整，批准合作',meta:['US','instagram'] }],
}
const collaborationWorkspace = {
  module:'collaborations' as const,pending_count:1,history_count:1,
  pending:[{id:8,title:'Paid post',detail_href:'/kol/library/5',kol_id:5,kol_name:'Pending KOL',owner_name:'Owner A',cooperation_type:'付费发帖',amount:'250.00',currency:'USD',deliverables:'发布 1 条评测视频',created_at:'2026-09-01T02:00:00+00:00',submitted_at:'2026-09-01T02:05:00+00:00'}],
  history:[{record_key:'collaboration:21',id:13,title:'Rejected paid post',detail_href:'/kol/library/5',kol_id:5,kol_name:'Pending KOL',cooperation_type:'付费发帖',amount:'300.00',currency:'USD',outcome:'rejected' as const,actor_name:'Manager A',decided_at:'2026-09-01T05:00:00+00:00',source:'manager_rejected',reason:'预算不符'}],
}
const payoutWorkspace = {
  module:'payouts' as const,pending_count:1,history_count:0,
  pending:[{id:11,title:'Payout #11',detail_href:'/kol/payouts',kol_name:'Pending KOL',requester_name:'Specialist A',affiliate_id:7,amount:'10.000000',currency:'USD',referral_ids:[91,92],created_at:'2026-09-01T03:00:00+00:00',submitted_at:'2026-09-01T03:00:00+00:00'}],
  history:[],
}

describe('KOL Manager approval module pages', () => {
  beforeEach(() => postMock.mockReset())
  it('renders one approval module at a time with direct module navigation', () => {
    const wrapper=mount(ApprovalCenter,{props:{workspace:kolWorkspace}})
    expect(wrapper.text()).toContain('Pending KOL')
    expect(wrapper.text()).not.toContain('Paid post')
    expect(wrapper.find('a[href="/kol/approvals/kols"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/kol/approvals/collaborations"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/kol/approvals/payouts"]').exists()).toBe(true)
  })
  it('labels approved notes separately from rejection reasons', async () => {
    const wrapper=mount(ApprovalCenter,{props:{workspace:kolWorkspace}})
    await wrapper.findAll('button').find(button=>button.text().includes('审批记录'))!.trigger('click')
    expect(wrapper.text()).toContain('审批备注：资料完整，批准合作')
    expect(wrapper.text()).not.toContain('驳回原因：资料完整，批准合作')
  })
  it('approves a KOL from its dedicated pending list', async () => {
    const wrapper=mount(ApprovalCenter,{props:{workspace:kolWorkspace}})
    await wrapper.findAll('button').find(button=>button.text().includes('批准') && !button.text().includes('审批记录'))!.trigger('click')
    expect(postMock).toHaveBeenCalledWith('/kol/approvals/kols/5/approve',{}, {preserveScroll:true})
  })
  it('keeps paid collaboration history in the collaboration module without filters', async () => {
    const wrapper=mount(ApprovalCenter,{props:{workspace:collaborationWorkspace}})
    await wrapper.findAll('button').find(button=>button.text().includes('审批记录'))!.trigger('click')
    expect(wrapper.text()).toContain('Rejected paid post')
    expect(wrapper.text()).toContain('已驳回')
    expect(wrapper.text()).toContain('预算不符')
    expect(wrapper.text()).toContain('USD')
    expect(wrapper.findAll('select')).toHaveLength(0)
  })
  it('payout manager page exposes approval but not finance completion controls', () => {
    const wrapper=mount(ApprovalCenter,{props:{workspace:payoutWorkspace}})
    expect(wrapper.text()).toContain('批准付款')
    expect(wrapper.text()).toContain('Finance 已完成真实付款')
    expect(wrapper.text()).not.toContain('PayPal Transaction ID')
  })
})
