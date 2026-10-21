import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'
vi.mock('@inertiajs/vue3', () => ({ Head:{template:'<span />'}, Link:{props:['href'],template:'<a :href="href"><slot /></a>'} }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default:{template:'<div><slot /></div>'} }))
describe('Guide reader', () => {
  it('renders server-provided H2 toc and rendered markdown', () => {
    const wrapper=mount(Show,{props:{guide:{slug:'shipping',content_type:'tutorial',title:'Shipping',summary:null,video_url:null,status:'published',published_at:'2026-08-28',updated_at:null},html:'<h2 id="step-one">Step One</h2><p>Body</p>',toc:[{id:'step-one',label:'Step One'}],canManage:false}})
    expect(wrapper.find('a[href="#step-one"]').text()).toBe('Step One'); expect(wrapper.html()).toContain('id="step-one"'); expect(wrapper.text()).toContain('Body')
  })
})
