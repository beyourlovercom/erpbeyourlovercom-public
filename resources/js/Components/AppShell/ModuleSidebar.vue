<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import type { ShellNavigationItem } from '../../types/navigation'

defineProps<{ item?: ShellNavigationItem; currentPath: string; collapsed: boolean }>()
</script>

<template>
  <aside class="shell-module-sidebar" :class="{ 'is-collapsed': collapsed }" :hidden="collapsed" aria-label="模块导航">
    <div v-if="item" class="shell-module-heading"><span>当前模块</span><strong>{{ item.label }}</strong></div>
    <nav v-if="item?.children.length" aria-label="模块页面">
      <Link v-for="child in item.children" :key="child.key" :href="child.path" class="shell-module-link" :class="{ 'is-active': currentPath === child.path }" :aria-current="currentPath === child.path ? 'page' : undefined">{{ child.label }}</Link>
    </nav>
    <p v-else-if="item" class="shell-module-empty">{{ item.placeholder ? '模块将在后续工作包中实现。' : '模块概览' }}</p>
  </aside>
</template>
