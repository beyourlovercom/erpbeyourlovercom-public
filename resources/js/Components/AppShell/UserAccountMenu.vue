<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import type { SafeUser } from '../../types/navigation'

const props = defineProps<{ user: SafeUser; navigationHidden: boolean }>()
const open = ref(false)
const root = ref<HTMLElement>()
const trigger = ref<HTMLButtonElement>()
const firstMenuItem = ref<HTMLButtonElement>()
const menuId = `shell-account-menu-${useId()}`

const initials = computed(() => {
    const words = props.user.name.trim().split(/\s+/u).filter(Boolean)
    if (words.length === 0) return '?'
    if (words.length === 1) return Array.from(words[0])[0]?.toUpperCase() ?? '?'

    return words.slice(0, 2).map((word) => Array.from(word)[0]?.toUpperCase() ?? '').join('') || '?'
})

const close = (restoreFocus = false) => {
    if (!open.value) return
    open.value = false
    if (restoreFocus) nextTick(() => trigger.value?.focus())
}

const toggle = async () => {
    open.value = !open.value
    if (open.value) await nextTick(() => firstMenuItem.value?.focus())
}

const onDocumentPointerDown = (event: PointerEvent) => {
    if (open.value && event.target instanceof Node && !root.value?.contains(event.target)) close()
}

const onDocumentKeydown = (event: KeyboardEvent) => {
    if (open.value && event.key === 'Escape') {
        event.preventDefault()
        close(true)
    }
}

watch(() => props.navigationHidden, (hidden) => {
    if (hidden) close()
})
onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown)
    document.addEventListener('keydown', onDocumentKeydown)
})
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown)
    document.removeEventListener('keydown', onDocumentKeydown)
})

const visit = (path: string) => { close(); router.visit(path) }
const logout = () => router.post('/logout')
</script>

<template>
  <div ref="root" class="shell-account-dock">
    <button ref="trigger" class="shell-account-trigger" type="button" :aria-expanded="open" :aria-controls="menuId" aria-haspopup="menu" :aria-label="open ? '关闭账户菜单' : '打开账户菜单'" @click="toggle">
      <span class="shell-account-avatar" aria-hidden="true">{{ initials }}</span>
      <span class="shell-account-trigger-name">{{ user.name || '未命名用户' }}账户菜单</span>
    </button>
    <div v-if="open" :id="menuId" class="shell-account-menu" role="menu" aria-label="用户账户">
      <div class="shell-account-identity" role="presentation">
        <span class="shell-account-avatar" aria-hidden="true">{{ initials }}</span>
        <span><strong>{{ user.name || '未命名用户' }}</strong><small>{{ user.email }}</small></span>
      </div>
      <div class="shell-account-actions" role="presentation">
        <button ref="firstMenuItem" class="shell-account-action" type="button" role="menuitem" @click="visit('/account/profile')">个人信息</button>
        <button class="shell-account-action" type="button" role="menuitem" @click="visit('/account/password')">修改密码</button>
        <button class="shell-account-action shell-account-logout" type="button" role="menuitem" @click="logout">退出登录</button>
      </div>
    </div>
  </div>
</template>
