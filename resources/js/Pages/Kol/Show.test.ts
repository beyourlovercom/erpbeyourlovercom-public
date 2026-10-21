import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const source = readFileSync('resources/js/Pages/Kol/Show.vue', 'utf8')

describe('KOL Show formal collaboration projection', () => {
  it('keeps the latest formal collaboration visible and uses post URL as the primary value', () => {
    expect(source).toContain('props.kol.collaborations[0] ?? null')
    expect(source).toContain('currentCollaboration.post_url')
    expect(source).toContain('待补合作发帖 URL')
    expect(source).toContain('当前正式合作')
  })

  it('projects M1.3 content separately from formal collaboration', () => {
    expect(source).toContain('props.kol.contents ?? []')
    expect(source).toContain('内容与发布')
    expect(source).toContain('content.evidence_classification')
    expect(source).toContain('content.publication_status')
  })

})
