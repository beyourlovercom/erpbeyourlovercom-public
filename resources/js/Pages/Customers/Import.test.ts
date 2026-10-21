import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const source = readFileSync('resources/js/Pages/Customers/Import.vue', 'utf8')

describe('Customer import workspace contract', () => {
  it('contains both template formats, preview and blocker-gated confirm', () => {
    expect(source).toContain('format=csv')
    expect(source).toContain('format=xlsx')
    expect(source).toContain('/customers/import/preview')
    expect(source).toContain('batch.preview_error_rows > 0')
    expect(source).toContain('最近导入批次')
    expect(source).toContain('身份')
    expect(source).toContain('original_filename')
    expect(source).toContain('creator?.name')
    expect(source).toContain('confirmer?.name')
    expect(source).toContain('confirmed_at')
    expect(source).toContain('router.reload')
  })
})
