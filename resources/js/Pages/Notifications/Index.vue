<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import type { ShellNotificationItem } from '../../types/navigation'

const props = defineProps<{ notificationPage: { unread_count: number; items: ShellNotificationItem[] } }>()
const icon = (category: string) => ({ order: '🛒', inventory: '⚠', procurement: '📦', shipment: '🚚', customer: '👤' }[category] ?? '•')
const openItem = (item: ShellNotificationItem) => router.visit(`/notifications/${item.id}`)
const markAllRead = () => router.post('/notifications/read-all')
</script>

<template>
  <AuthenticatedLayout title="通知" :breadcrumbs="[{ label: 'BYL ERP', href: '/dashboard' }, '通知']">
    <section class="notification-page">
      <div class="notification-page-heading">
        <div><p class="mvp-kicker">GLOBAL NOTIFICATION CENTER</p><h2>通知</h2><p>需要你知道、处理或稍后回看的业务事件。</p></div>
        <button v-if="props.notificationPage.unread_count" class="mvp-button" type="button" @click="markAllRead">全部标记已读</button>
      </div>
      <div class="notification-page-card">
        <button v-for="item in props.notificationPage.items" :key="item.id" type="button" class="notification-page-row" :class="{ unread: !item.read }" @click="openItem(item)">
          <span class="notification-category-icon large" aria-hidden="true">{{ icon(item.category) }}</span>
          <span class="notification-copy"><strong>{{ item.title }}</strong><span>{{ item.message }}</span><small>{{ item.created_at ? new Date(item.created_at).toLocaleString() : '' }}</small></span>
          <span v-if="!item.read" class="notification-unread-dot" aria-label="未读"></span>
          <span class="notification-row-arrow" aria-hidden="true">›</span>
        </button>
        <p v-if="!props.notificationPage.items.length" class="notification-empty large">暂无通知</p>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
