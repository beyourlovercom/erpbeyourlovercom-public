import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import RunShow from './RunShow.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const run = {
  id: 12,
  run_type: 'tracking_reconciliation',
  provider_key: 'woocommerce',
  connection_key: 'shop-a',
  status: 'partial',
  requested_items: 2,
  processed_items: 2,
  succeeded_items: 1,
  retrying_items: 0,
  failed_items: 0,
  conflict_items: 1,
  skipped_items: 0,
  initiator: 'Owner',
  started_at: '2026-08-28T04:00:00Z',
  completed_at: '2026-08-28T04:00:01Z',
}
const items = [
  {
    id: 1, sequence: 1, operation: 'woocommerce.tracking_reconcile', order_id: 101,
    shipment_id: 201, outbox_id: null, provider_reference: '7401', status: 'succeeded',
    result_code: 'matched', error_code: null, error_message: null,
    started_at: '2026-08-28T04:00:00Z', completed_at: '2026-08-28T04:00:00Z',
  },
  {
    id: 2, sequence: 2, operation: 'woocommerce.tracking_reconcile', order_id: 102,
    shipment_id: 202, outbox_id: null, provider_reference: '7402', status: 'conflict',
    result_code: 'remote_missing', error_code: 'remote_missing', error_message: 'Remote tracking is missing.',
    started_at: '2026-08-28T04:00:00Z', completed_at: '2026-08-28T04:00:01Z',
  },
]

describe('Sync Run detail evidence', () => {
  it('renders run summary and item-level reconciliation evidence', () => {
    const wrapper = mount(RunShow, { props: { run: { ...run, items } } })
    expect(wrapper.text()).toContain('Sync Run #12')
    expect(wrapper.text()).toContain('tracking_reconciliation')
    expect(wrapper.text()).toContain('partial')
    expect(wrapper.text()).toContain('matched')
    expect(wrapper.text()).toContain('remote_missing')
    expect(wrapper.text()).toContain('Remote tracking is missing.')
    expect(wrapper.find('a[href="/sync"]').exists()).toBe(true)
  })
})
