<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type NavItem = {
  key: string
  label: string
  path: string
  route?: string | null
  group?: boolean
  children?: NavItem[]
}

const props = defineProps<{
  module: NavItem & { emoji?: string | null; children: NavItem[] }
  description: string
}>()

const entries = computed(() => props.module.children.filter((item) => item.path !== props.module.path || item.group))
</script>

<template>
  <Head :title="`${module.label} · BYL ERP`" />
  <AuthenticatedLayout :title="module.label" :breadcrumbs="['BYL ERP', module.label]">
    <section class="mvp-page module-overview">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">MODULE OVERVIEW</p>
          <h2>{{ module.label }}</h2>
          <p>{{ description }}</p>
        </div>
      </div>

      <div v-if="entries.length" class="module-overview-grid">
        <template v-for="entry in entries" :key="entry.key">
          <section v-if="entry.group" class="module-overview-card">
            <h3>{{ entry.label }}</h3>
            <div class="module-overview-links">
              <Link v-for="child in entry.children ?? []" :key="child.key" :href="child.path">{{ child.label }}</Link>
            </div>
          </section>
          <Link v-else class="module-overview-card module-overview-link" :href="entry.path">
            <h3>{{ entry.label }}</h3>
            <p>进入 {{ entry.label }}</p>
          </Link>
        </template>
      </div>

      <div v-else class="mvp-empty module-overview-empty">
        当前账号暂无可用的二级入口；此一级页面已作为正式模块入口启用。
      </div>
    </section>
  </AuthenticatedLayout>
</template>
