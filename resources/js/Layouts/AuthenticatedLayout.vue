<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import GlobalFeedbackIsland from '../Components/AppShell/GlobalFeedbackIsland.vue'
import GlobalNavigation from '../Components/AppShell/GlobalNavigation.vue'
import TopBar, { type BreadcrumbItem } from '../Components/AppShell/TopBar.vue'
import {
    canonicalFeedbackCopyText,
    copyFailureFeedback,
    copySuccessFeedback,
    feedbackFromFlash,
    globalFeedback,
    isCopyableFeedback,
    publishGlobalFeedback,
    scheduleGlobalFeedbackRestore,
} from '../lib/globalFeedback'
import type { ShellPageProps } from '../types/navigation'

defineProps<{ title: string; breadcrumbs: (string | BreadcrumbItem)[] }>()
const page = usePage<ShellPageProps>()
const sidebarCollapsed = ref(false)
const mobileOpen = ref(false)
const isNarrowScreen = ref(false)
let narrowScreenQuery: MediaQueryList | undefined
const storageKey = 'module_sidebar_collapsed'

const updateNarrowScreen = (event: MediaQueryListEvent | MediaQueryList) => {
    isNarrowScreen.value = event.matches
}
const readCollapsed = () => {
    try {
        return window.localStorage.getItem(storageKey) === 'true'
    } catch {
        return false
    }
}
const writeCollapsed = (collapsed: boolean) => {
    try {
        window.localStorage.setItem(storageKey, String(collapsed))
    } catch {
        return
    }
}
const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value
    writeCollapsed(sidebarCollapsed.value)
    publishGlobalFeedback({
        state: 'INFO',
        title: sidebarCollapsed.value ? '已收起导航' : '已展开导航',
        message: '',
    })
}
const toggleMobile = () => {
    mobileOpen.value = !mobileOpen.value
    publishGlobalFeedback({
        state: 'INFO',
        title: mobileOpen.value ? '已展开导航' : '已收起导航',
        message: '',
    })
}

const publishCurrentFlash = () => {
    const value = feedbackFromFlash(page.props.flash)
    if (value) publishGlobalFeedback(value)
}

watch(
    () => page.props.flash?.feedback_id,
    publishCurrentFlash,
    { immediate: true },
)

const copyGlobalFeedback = async () => {
    const original = globalFeedback.value
    if (!isCopyableFeedback(original)) return
    try {
        if (!navigator.clipboard?.writeText) throw new Error('CLIPBOARD_WRITE_UNAVAILABLE')
        await navigator.clipboard.writeText(canonicalFeedbackCopyText(original))
        if (globalFeedback.value !== original) return
        const overlay = copySuccessFeedback()
        publishGlobalFeedback(overlay)
        scheduleGlobalFeedbackRestore(overlay, original)
    } catch (error) {
        if (globalFeedback.value !== original) return
        publishGlobalFeedback(copyFailureFeedback(error))
    }
}

onMounted(() => {
    sidebarCollapsed.value = readCollapsed()
    narrowScreenQuery = window.matchMedia?.('(max-width: 800px)')
    if (!narrowScreenQuery) return
    updateNarrowScreen(narrowScreenQuery)
    narrowScreenQuery.addEventListener('change', updateNarrowScreen)
})
onBeforeUnmount(() => narrowScreenQuery?.removeEventListener('change', updateNarrowScreen))
const currentPath = computed(() => page.url.split('?')[0])
</script>

<template>
  <div class="authenticated-shell" :class="{ 'mobile-nav-open': mobileOpen, 'sidebar-collapsed': sidebarCollapsed }">
    <GlobalFeedbackIsland :feedback="globalFeedback" @copy="copyGlobalFeedback" />
    <div class="shell-mobile-backdrop" aria-hidden="true" @click="mobileOpen = false"></div>
    <GlobalNavigation
      :items="page.props.shellNavigation"
      :current-path="currentPath"
      :mobile-hidden="isNarrowScreen && !mobileOpen"
      :collapsed="sidebarCollapsed"
      :user="page.props.auth.user"
      @toggle-collapse="toggleSidebar"
    />
    <div class="shell-workspace">
      <TopBar
        :title="title"
        :breadcrumbs="breadcrumbs"
        :mobile-expanded="mobileOpen"
        :notification-center="page.props.notificationCenter"
        @toggle-mobile="toggleMobile"
      />
      <main id="main-content" class="shell-content" tabindex="-1"><slot /></main>
    </div>
  </div>
</template>
