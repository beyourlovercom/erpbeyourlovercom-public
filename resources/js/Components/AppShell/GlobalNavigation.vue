<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import type { SafeUser, ShellNavigationItem } from '../../types/navigation'
import NavigationItem from './NavigationItem.vue'
import UserAccountMenu from './UserAccountMenu.vue'

defineProps<{ items: ShellNavigationItem[]; currentPath: string; mobileHidden: boolean; collapsed: boolean; user: SafeUser }>()
const emit = defineEmits<{ toggleCollapse: [] }>()
</script>

<template>
  <nav class="shell-primary-nav" aria-label="ERP主导航" :class="{ 'is-collapsed': collapsed }" :inert="mobileHidden ? true : undefined" :aria-hidden="mobileHidden">
    <div class="shell-brand-row">
      <Link class="shell-brand" href="/dashboard" aria-label="BYL ERP Dashboard"><strong>BYL</strong><span>ERP</span></Link>
      <button class="shell-collapse-button" type="button" :aria-expanded="!collapsed" :aria-label="collapsed ? '展开导航' : '收起导航'" :title="collapsed ? '展开导航' : '收起导航'" @click="emit('toggleCollapse')"><span aria-hidden="true">{{ collapsed ? '›' : '‹' }}</span></button>
    </div>
    <div class="shell-primary-links">
      <NavigationItem v-for="item in items" :key="item.key" :item="item" :current-path="currentPath" :collapsed="collapsed" />
    </div>
    <UserAccountMenu :user="user" :navigation-hidden="mobileHidden" />
  </nav>
</template>
