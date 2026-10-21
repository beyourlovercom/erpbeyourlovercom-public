import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const source = readFileSync('resources/js/Pages/Kol/Content.vue', 'utf8')

describe('KOL M1.3 Content Editor UI V2 contract', () => {
  it('uses the frozen right drawer VI and simplified publication controls', () => {
    expect(source).toContain('content-drawer-backdrop')
    expect(source).toContain('content-drawer')
    expect(source).toContain('保存修改')
    expect(source).toContain("platform:'instagram'")
    expect(source).toContain("content_type:'post'")
    expect(source).toContain("publication_status:'unpublished'")
    expect(source).toContain('status-toggle')
    expect(source).toContain("setPublicationStatus('published')")
    expect(source).toContain("setPublicationStatus('unpublished')")
  })

  it('uses date-only fields and removes Content notes', () => {
    expect(source).toContain('type="date"')
    expect(source).not.toContain('datetime-local')
    expect(source).not.toContain('form.notes')
    expect(source).not.toContain('<label>备注')
  })

  it('keeps collaboration URL reference manual and supports paste/upload/sync screenshots', () => {
    expect(source).toContain('复制到 Content URL')
    expect(source).toContain('copyPrimaryUrl')
    expect(source).toContain('不会自动覆盖')
    expect(source).toContain('@paste.stop="handlePaste"')
    expect(source).toContain("window.addEventListener('paste', handleWindowPaste)")
    expect(source).toContain('Ctrl + V / ⌘V 粘贴，或点击上传')
    expect(source).toContain('/sync-post-image')
    expect(source).toContain('同步帖子图片')
    expect(source).toContain('发布素材截图')
  })

  it('retains paginated content and server-side KOL search', () => {
    expect(source).toContain('contents:Paginated<ContentRow>')
    expect(source).toContain("axios.get('/kol/content/options'")
    expect(source).toContain('contents.data')
    expect(source).toContain('contents.last_page')
  })
})
