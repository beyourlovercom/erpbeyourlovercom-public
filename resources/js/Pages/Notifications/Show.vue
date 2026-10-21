<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import type { ShellNotificationItem } from '../../types/navigation'
const props = defineProps<{ notification: ShellNotificationItem }>()
const icon = (category: string) => ({ order:'🛒', procurement:'📦', inventory:'⚠', shipment:'🚚', customer:'👤' }[category] ?? '•')
const cls = (category: string) => ['order','procurement','inventory','shipment','customer'].includes(category) ? category : 'other'
const time = (value: string | null) => value ? new Date(value).toLocaleString() : ''
const destroyItem = () => router.delete(`/notifications/${props.notification.id}`, { onSuccess: () => router.visit('/notifications') })
</script>

<template>
  <AuthenticatedLayout title="通知详情" :breadcrumbs="[{ label: 'BYL ERP', href: '/dashboard' }, { label: '通知', href: '/notifications' }, '详情']">
    <section class="notification-detail-page">
      <article class="notification-detail-card notification-standalone-design">
        <header class="notification-detail-header">
          <a href="/notifications" class="notification-back-button" aria-label="返回通知列表">←</a>
          <strong>通知详情</strong>
          <div class="notification-detail-header-actions">
            <button type="button" class="notification-delete-button" aria-label="删除通知" @click="destroyItem">🗑</button>
            <span class="notification-read-state">{{ notification.read ? '已读' : '未读' }}</span>
          </div>
        </header>
        <div class="notification-design-detail">
          <div class="notification-detail-hero">
            <span class="notification-category-icon large" :class="cls(notification.category)" aria-hidden="true">{{ icon(notification.category) }}</span>
            <div><h3>{{ notification.title }}</h3><time>{{ time(notification.created_at) }}</time></div>
          </div>
          <section class="notification-detail-content-section"><h4>内容</h4><p>{{ notification.message }}</p></section>
          <dl class="notification-design-facts">
            <div v-for="link in notification.related_links" :key="`${link.label}-${link.url}`"><dt>{{ link.label }}</dt><dd><a :href="link.url">{{ link.value }} <span>→</span></a></dd></div>
            <div v-if="!notification.related_links.length && notification.action_url"><dt>相关业务</dt><dd><a :href="notification.action_url">查看业务记录 <span>→</span></a></dd></div>
            <div><dt>创建人</dt><dd>{{ notification.actor_name || 'System' }}</dd></div>
            <div><dt>时间</dt><dd>{{ time(notification.created_at) }}</dd></div>
          </dl>
        </div>
      </article>
    </section>
  </AuthenticatedLayout>
</template>
