<script setup lang="ts">
import { computed } from 'vue'
import UiButton from '../UI/UiButton.vue'
import GlobalSearchOverlay from './GlobalSearchOverlay.vue'
import NotificationBell from './NotificationBell.vue'
import type { NotificationCenterSummary } from '../../types/navigation'

export type BreadcrumbItem = { label: string; href?: string }

const props = withDefaults(defineProps<{ title: string; breadcrumbs: (string | BreadcrumbItem)[]; mobileExpanded: boolean; notificationCenter?: NotificationCenterSummary }>(), { notificationCenter: () => ({ unread_count: 0, items: [] }) })
const emit = defineEmits<{ toggleMobile: [] }>()

const parentHref = computed<string | null>(() => {
  const withHref = props.breadcrumbs.filter(
    (c): c is BreadcrumbItem => typeof c === 'object' && !!c.href,
  )
  return withHref.length > 0 ? (withHref[withHref.length - 1].href ?? null) : null
})
</script>

<template>
  <header class="shell-topbar">
    <div class="shell-topbar-leading">
      <a v-if="parentHref" :href="parentHref" class="shell-back-control" aria-label="返回上一层">‹</a>
      <div>
        <div class="shell-breadcrumbs" aria-label="面包屑">
          <template v-for="(crumb, i) in breadcrumbs" :key="i">
            <a v-if="typeof crumb === 'object' && crumb.href" :href="crumb.href">{{ crumb.label }}</a>
            <span v-else>{{ typeof crumb === 'object' ? crumb.label : crumb }}</span>
          </template>
        </div>
        <h1>{{ title }}</h1>
      </div>
    </div>
    <div class="shell-topbar-trailing">
      <GlobalSearchOverlay />
      <NotificationBell :center="notificationCenter" />
      <UiButton class="shell-icon-button shell-mobile-toggle" tone="secondary" size="sm" :aria-expanded="mobileExpanded" :aria-label="mobileExpanded ? '关闭导航' : '打开导航'" @click="emit('toggleMobile')">☰</UiButton>
    </div>
  </header>
</template>
