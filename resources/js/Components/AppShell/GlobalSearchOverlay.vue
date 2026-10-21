<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'

type SearchItem = {
  id: string
  label: string
  subtitle: string
  href: string
  meta: Record<string, string | number | null>
}
type SearchGroup = {
  key: string
  label: string
  view_all_href: string
  items: SearchItem[]
}
type SearchResponse = {
  query: string
  min_query_length: number
  groups: SearchGroup[]
}

const opened = ref(false)
const query = ref('')
const groups = ref<SearchGroup[]>([])
const loading = ref(false)
const error = ref('')
const activeIndex = ref(0)
const input = ref<HTMLInputElement | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined
let controller: AbortController | undefined

const flatItems = computed(() => groups.value.flatMap(group => group.items))
const hasQuery = computed(() => query.value.trim().length > 0)
const shortQuery = computed(() => {
  const length = query.value.trim().length
  return length > 0 && length < 2
})

const show = () => {
  opened.value = true
  nextTick(() => input.value?.focus())
}

const close = () => {
  opened.value = false
  error.value = ''
  activeIndex.value = 0
}

const reset = () => {
  query.value = ''
  groups.value = []
  error.value = ''
  activeIndex.value = 0
  controller?.abort()
  clearTimeout(timer)
  nextTick(() => input.value?.focus())
}

const search = async () => {
  const q = query.value.trim()
  if (q.length < 2) {
    groups.value = []
    loading.value = false
    activeIndex.value = 0
    return
  }

  controller?.abort()
  controller = new AbortController()
  loading.value = true
  error.value = ''

  try {
    const response = await fetch(`/search/global?q=${encodeURIComponent(q)}`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
    if (!response.ok) throw new Error(`HTTP_${response.status}`)
    const payload = await response.json() as SearchResponse
    groups.value = payload.groups
    activeIndex.value = 0
  } catch (exception) {
    if (exception instanceof DOMException && exception.name === 'AbortError') return
    groups.value = []
    error.value = '搜索暂时不可用，请稍后重试。'
  } finally {
    if (!controller?.signal.aborted) loading.value = false
  }
}

const onInput = () => {
  clearTimeout(timer)
  error.value = ''
  if (query.value.trim().length < 2) {
    controller?.abort()
    groups.value = []
    loading.value = false
    activeIndex.value = 0
    return
  }
  timer = setTimeout(search, 180)
}

const openItem = (item: SearchItem) => {
  close()
  router.visit(item.href)
}

const onInputKeydown = (event: KeyboardEvent) => {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    if (flatItems.value.length > 0) activeIndex.value = (activeIndex.value + 1) % flatItems.value.length
    return
  }
  if (event.key === 'ArrowUp') {
    event.preventDefault()
    if (flatItems.value.length > 0) activeIndex.value = (activeIndex.value - 1 + flatItems.value.length) % flatItems.value.length
    return
  }
  if (event.key === 'Enter') {
    const item = flatItems.value[activeIndex.value]
    if (item) {
      event.preventDefault()
      openItem(item)
    }
  }
}

const itemIndex = (groupIndex: number, itemIndexInGroup: number) => {
  let index = itemIndexInGroup
  for (let i = 0; i < groupIndex; i++) index += groups.value[i].items.length
  return index
}

const onGlobalKeydown = (event: KeyboardEvent) => {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    show()
    return
  }
  if (event.key === 'Escape' && opened.value) close()
}

onMounted(() => window.addEventListener('keydown', onGlobalKeydown))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onGlobalKeydown)
  controller?.abort()
  clearTimeout(timer)
})
</script>

<template>
  <button type="button" class="global-search-trigger" aria-label="全局搜索" title="全局搜索 · ⌘/Ctrl + K" @click="show">
    <span aria-hidden="true">⌕</span>
    <kbd>⌘K</kbd>
  </button>

  <Teleport to="body">
    <div v-if="opened" class="global-search-backdrop" role="presentation" @mousedown.self="close">
      <section class="global-search-dialog" role="dialog" aria-modal="true" aria-label="全局搜索">
        <header class="global-search-head">
          <span class="global-search-icon" aria-hidden="true">⌕</span>
          <input
            ref="input"
            v-model="query"
            class="global-search-input"
            type="search"
            autocomplete="off"
            spellcheck="false"
            placeholder="搜索产品、SKU、订单号、Tracking、库存…"
            aria-label="搜索关键词"
            @input="onInput"
            @keydown="onInputKeydown"
          >
          <button v-if="hasQuery" type="button" class="global-search-clear" aria-label="清空搜索" @click="reset">×</button>
          <button type="button" class="global-search-close" aria-label="关闭搜索" @click="close">Esc</button>
        </header>

        <div class="global-search-body">
          <p v-if="!hasQuery" class="global-search-hint">输入至少 2 个字符。精确 SKU、订单号、Tracking 会优先显示。</p>
          <p v-else-if="shortQuery" class="global-search-hint">再输入 1 个字符开始搜索。</p>
          <p v-else-if="loading" class="global-search-hint">搜索中…</p>
          <p v-else-if="error" class="global-search-error">{{ error }}</p>
          <template v-else-if="groups.length">
            <section v-for="(group, groupIndex) in groups" :key="group.key" class="global-search-group">
              <div class="global-search-group-head">
                <strong>{{ group.label }}</strong>
                <a :href="group.view_all_href">查看全部</a>
              </div>
              <button
                v-for="(item, itemIndexInGroup) in group.items"
                :key="item.id"
                type="button"
                class="global-search-result"
                :class="{ active: itemIndex(groupIndex, itemIndexInGroup) === activeIndex }"
                @mouseenter="activeIndex = itemIndex(groupIndex, itemIndexInGroup)"
                @click="openItem(item)"
              >
                <span class="global-search-result-copy">
                  <strong>{{ item.label }}</strong>
                  <small>{{ item.subtitle }}</small>
                </span>
                <span aria-hidden="true">→</span>
              </button>
            </section>
          </template>
          <p v-else class="global-search-hint">没有找到匹配结果。</p>
        </div>

        <footer class="global-search-foot">
          <span><kbd>↑</kbd><kbd>↓</kbd> 选择</span>
          <span><kbd>Enter</kbd> 打开</span>
          <span><kbd>Esc</kbd> 关闭</span>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.global-search-trigger{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 10px;border:1px solid var(--byl-border-subtle);border-radius:10px;background:var(--byl-surface);color:var(--byl-text);cursor:pointer}
.global-search-trigger:hover{background:var(--byl-surface-muted)}
.global-search-trigger>span{font-size:19px;line-height:1}
.global-search-trigger kbd,.global-search-foot kbd{border:1px solid var(--byl-border-subtle);border-bottom-width:2px;border-radius:6px;background:var(--byl-surface-muted);padding:2px 5px;font:inherit;font-size:10px;color:var(--byl-text-muted)}
.global-search-backdrop{position:fixed;inset:0;z-index:2200;display:flex;align-items:flex-start;justify-content:center;padding:10vh 18px 24px;background:rgba(10,26,34,.42);backdrop-filter:blur(5px)}
.global-search-dialog{width:min(720px,100%);max-height:min(76vh,760px);display:flex;flex-direction:column;overflow:hidden;border:1px solid var(--byl-border-subtle);border-radius:18px;background:var(--byl-surface);box-shadow:0 30px 80px rgba(8,32,43,.28)}
.global-search-head{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--byl-border-subtle)}
.global-search-icon{font-size:22px;color:var(--byl-text-muted)}
.global-search-input{flex:1;min-width:0;height:42px;border:0;outline:0;background:transparent;color:var(--byl-text);font-size:16px}
.global-search-input::-webkit-search-cancel-button{display:none}
.global-search-clear,.global-search-close{border:0;background:transparent;color:var(--byl-text-muted);cursor:pointer}
.global-search-clear{font-size:22px}
.global-search-close{padding:5px 8px;border:1px solid var(--byl-border-subtle);border-radius:7px;font-size:11px}
.global-search-body{overflow-y:auto;padding:10px}
.global-search-hint,.global-search-error{margin:0;padding:34px 18px;text-align:center;color:var(--byl-text-muted);font-size:13px}
.global-search-error{color:#b42318}
.global-search-group{padding:6px 0}
.global-search-group+.global-search-group{border-top:1px solid var(--byl-border-subtle)}
.global-search-group-head{display:flex;align-items:center;justify-content:space-between;padding:8px 10px}
.global-search-group-head strong{font-size:12px;letter-spacing:.04em;color:var(--byl-text-muted)}
.global-search-group-head a{font-size:11px;color:#267568;text-decoration:none}
.global-search-result{width:100%;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:11px 12px;border:0;border-radius:10px;background:transparent;color:var(--byl-text);text-align:left;cursor:pointer}
.global-search-result.active,.global-search-result:hover{background:#eef7f5}
.global-search-result-copy{min-width:0;display:grid;gap:3px}
.global-search-result-copy strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}
.global-search-result-copy small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--byl-text-muted);font-size:11px}
.global-search-foot{display:flex;gap:16px;padding:10px 16px;border-top:1px solid var(--byl-border-subtle);color:var(--byl-text-muted);font-size:10px}
.global-search-foot span{display:inline-flex;align-items:center;gap:4px}
@media(max-width:700px){
  .global-search-trigger kbd{display:none}
  .global-search-backdrop{padding:0}
  .global-search-dialog{width:100%;height:100dvh;max-height:none;border:0;border-radius:0}
  .global-search-head{padding:12px 14px}
  .global-search-input{font-size:16px}
  .global-search-body{flex:1}
  .global-search-foot{padding-bottom:max(12px,env(safe-area-inset-bottom))}
}
</style>