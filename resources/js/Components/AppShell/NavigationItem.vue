<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import type { ShellNavigationItem } from '../../types/navigation'

defineProps<{ item: ShellNavigationItem; currentPath: string; collapsed: boolean }>()

const isActive = (item: ShellNavigationItem, currentPath: string): boolean => {
    if (item.group) return false
    return item.path === '/dashboard'
        ? currentPath === '/dashboard'
        : currentPath === item.path || currentPath.startsWith(`${item.path}/`)
}

const hasActiveChild = (item: ShellNavigationItem, currentPath: string): boolean => item.children.some((child) => isActive(child, currentPath) || hasActiveChild(child, currentPath))
</script>

<template>
  <div class="shell-navigation-group">
    <Link v-if="!item.group" :href="item.path" class="shell-primary-link" :class="{ 'is-active': isActive(item, currentPath) }" :aria-current="isActive(item, currentPath) ? 'page' : undefined" :aria-label="collapsed ? item.label : undefined" :title="collapsed ? item.label : undefined">
      <span class="shell-nav-marker" aria-hidden="true"></span>
      <span class="shell-primary-emoji" aria-hidden="true">{{ item.emoji ?? '' }}</span>
      <span class="shell-primary-label">{{ item.label }}</span>
    </Link>
    <div v-else class="shell-primary-link shell-primary-link-group" role="presentation">
      <span class="shell-nav-marker" aria-hidden="true"></span>
      <span class="shell-primary-emoji" aria-hidden="true">{{ item.emoji ?? '' }}</span>
      <span class="shell-primary-label">{{ item.label }}</span>
    </div>
    <nav v-if="!collapsed && item.children.length && (isActive(item, currentPath) || hasActiveChild(item, currentPath))" class="shell-child-links" :aria-label="`${item.label}子导航`">
      <NavigationItem v-for="child in item.children" :key="child.key" :item="child" :current-path="currentPath" :collapsed="false" />
    </nav>
  </div>
</template>
