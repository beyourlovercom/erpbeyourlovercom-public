<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import type { NotificationCenterSummary, ShellNotificationItem } from '../../types/navigation'

const props = defineProps<{ center: NotificationCenterSummary }>()
const open = ref(false)
const selected = ref<ShellNotificationItem | null>(null)
const root = ref<HTMLElement | null>(null)
const locallyReadIds = ref<Set<string>>(new Set())

const isRead = (item: ShellNotificationItem) => item.read || locallyReadIds.value.has(item.id)
const displayUnreadCount = computed(() => Math.max(0, props.center.unread_count - locallyReadIds.value.size))

const categoryMeta = (category: string) => ({
  order: { icon: '🛒', cls: 'order' }, inventory: { icon: '⚠', cls: 'inventory' },
  procurement: { icon: '📦', cls: 'procurement' }, shipment: { icon: '🚚', cls: 'shipment' },
  customer: { icon: '👤', cls: 'customer' },
}[category] ?? { icon: '•', cls: 'other' })

const relativeTime = (value: string | null) => {
  if (!value) return ''
  const mins = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 60000))
  if (mins < 1) return '刚刚'
  if (mins < 60) return `${mins} 分钟前`
  const hours = Math.floor(mins / 60)
  if (hours < 24) return `${hours} 小时前`
  const days = Math.floor(hours / 24)
  return `${days} 天前`
}
const absoluteTime = (value: string | null) => value ? new Date(value).toLocaleString() : ''
const selectItem = (item: ShellNotificationItem) => {
  selected.value = item
  if (!isRead(item)) {
    const next = new Set(locallyReadIds.value)
    next.add(item.id)
    locallyReadIds.value = next
    router.post(`/notifications/${item.id}/read`, {}, { preserveScroll: true, preserveState: true })
  }
}
const closeDetails = () => { selected.value = null }
const markAllRead = () => {
  locallyReadIds.value = new Set(props.center.items.filter(item => !item.read).map(item => item.id))
  router.post('/notifications/read-all', {}, { preserveScroll: true, preserveState: true })
}
const destroyItem = (item: ShellNotificationItem) => router.delete(`/notifications/${item.id}`, { preserveScroll: true, onSuccess: closeDetails })
const closeOutside = (event: MouseEvent) => {
  const host = root.value
  if (!host) return
  const path = typeof event.composedPath === 'function' ? event.composedPath() : []
  if (path.includes(host) || host.contains(event.target as Node)) return
  open.value = false
  selected.value = null
}
const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') { if (selected.value) selected.value = null; else open.value = false } }
onMounted(() => { document.addEventListener('click', closeOutside); document.addEventListener('keydown', onKey) })
onBeforeUnmount(() => { document.removeEventListener('click', closeOutside); document.removeEventListener('keydown', onKey) })
</script>

<template>
  <div ref="root" class="notification-bell-host">
    <button type="button" class="notification-bell-trigger" :aria-expanded="open" aria-label="打开通知中心" @click.stop="open = !open">
      <span aria-hidden="true">🔔</span>
      <span v-if="displayUnreadCount > 0" class="notification-badge">{{ displayUnreadCount > 99 ? '99+' : displayUnreadCount }}</span>
    </button>

    <section v-if="open" class="notification-popover" aria-label="通知中心">
      <template v-if="!selected">
        <header class="notification-popover-header notification-list-header">
          <div class="notification-title-group"><strong>通知</strong><span v-if="displayUnreadCount" class="notification-header-badge">{{ displayUnreadCount }}</span></div>
          <div class="notification-header-actions">
            <button v-if="displayUnreadCount" type="button" @click="markAllRead">全部标记已读</button>
            <a href="/settings" class="notification-settings-link" aria-label="设置">⚙</a>
          </div>
        </header>

        <div v-if="center.items.length" class="notification-popover-list">
          <button v-for="item in center.items" :key="item.id" type="button" class="notification-list-row" :class="{ unread: !isRead(item) }" @click="selectItem(item)">
            <span class="notification-category-icon" :class="categoryMeta(item.category).cls" aria-hidden="true">{{ categoryMeta(item.category).icon }}</span>
            <span class="notification-copy"><strong>{{ item.title }}</strong><span>{{ item.message }}</span></span>
            <span class="notification-row-tail"><time>{{ relativeTime(item.created_at) }}</time><span v-if="!isRead(item)" class="notification-unread-dot" aria-label="未读"></span></span>
          </button>
        </div>
        <p v-else class="notification-empty">暂无通知</p>
        <a class="notification-view-all" href="/notifications">查看全部通知 <span>→</span></a>
      </template>

      <template v-else>
        <header class="notification-detail-header">
          <button type="button" class="notification-back-button" aria-label="返回通知列表" @click="closeDetails">←</button>
          <strong>通知详情</strong>
          <div class="notification-detail-header-actions">
            <button type="button" class="notification-delete-button" aria-label="删除通知" @click="destroyItem(selected)">🗑</button>
            <span class="notification-read-state">已读</span>
          </div>
        </header>

        <article class="notification-design-detail">
          <div class="notification-detail-hero">
            <span class="notification-category-icon large" :class="categoryMeta(selected.category).cls" aria-hidden="true">{{ categoryMeta(selected.category).icon }}</span>
            <div><h3>{{ selected.title }}</h3><time>{{ absoluteTime(selected.created_at) }}</time></div>
          </div>

          <section class="notification-detail-content-section">
            <h4>内容</h4>
            <p>{{ selected.message }}</p>
          </section>

          <dl class="notification-design-facts">
            <div v-for="link in selected.related_links" :key="`${link.label}-${link.url}`">
              <dt>{{ link.label }}</dt><dd><a :href="link.url">{{ link.value }} <span>→</span></a></dd>
            </div>
            <div v-if="!selected.related_links.length && selected.action_url">
              <dt>相关业务</dt><dd><a :href="selected.action_url">查看业务记录 <span>→</span></a></dd>
            </div>
            <div><dt>创建人</dt><dd>{{ selected.actor_name || 'System' }}</dd></div>
            <div><dt>时间</dt><dd>{{ absoluteTime(selected.created_at) }}</dd></div>
          </dl>
        </article>
      </template>
    </section>
  </div>
</template>
