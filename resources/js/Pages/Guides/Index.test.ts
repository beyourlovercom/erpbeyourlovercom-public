import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'
vi.mock('@inertiajs/vue3', () => ({ Head:{template:'<span />'}, Link:{props:['href'],template:'<a :href="href"><slot /></a>'} }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default:{template:'<div><slot /></div>'} }))
describe('Guides index', () => {
  it('renders frozen knowledge-base sections and management action', () => {
    const wrapper=mount(Index,{props:{title:'全部教程',contentType:null,canManage:true,guides:[{id:1,slug:'shipping',content_type:'manual',title:'Shipping SOP',summary:'How to ship',status:'draft',published_at:null,updated_at:null}]}})
    for(const href of ['/guides','/guides/videos','/guides/manuals','/settings/changelog','/guides/create','/guides/shipping']) expect(wrapper.find(`a[href="${href}"]`).exists()).toBe(true)
    expect(wrapper.text()).toContain('Shipping SOP'); expect(wrapper.text()).toContain('草稿')
  })
})
