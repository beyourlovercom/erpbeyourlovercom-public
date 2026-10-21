<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ReleaseNote = { id:number; version:string; title:string; summary:string|null; released_on:string|null }
const props = withDefaults(defineProps<{ currentRelease:ReleaseNote|null; recentReleases:ReleaseNote[]; orderIdentity?: { prefix_token:string; example:string } }>(), { orderIdentity: () => ({ prefix_token: '007erp', example: '007erp-0901-c3721' }) })
const prefix = reactive({ value: props.orderIdentity.prefix_token })
const example = () => `${prefix.value.trim().toLowerCase() || '007erp'}-0901-c3721`
const savePrefix = () => router.put('/settings/order-identity', { prefix_token: prefix.value }, { preserveScroll: true })
const changelogHref = (date: string | null) => date ? `/settings/changelog?date=${encodeURIComponent(date)}` : '/settings/changelog'
</script>

<template>
  <Head title="设置 · BYL ERP" />
  <AuthenticatedLayout title="设置" :breadcrumbs="['BYL ERP', '设置']">
    <section class="mvp-page settings-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">ERP SETTINGS</p><h2>系统设置</h2><p>集中管理 ERP 配置，并查看最新开发记录。</p></div></div>
      <div class="settings-grid">
        <article class="mvp-card order-identity-card"><h3>订单编号前缀</h3><p>用于新生成的 ERP 订单号，不会改写历史订单号。</p><label>订单前缀<input v-model="prefix.value" maxlength="24" pattern="[A-Za-z0-9]+" aria-label="订单编号前缀"></label><small>示例：{{ example() }}</small><div><button class="mvp-button mvp-button-primary" type="button" @click="savePrefix">保存前缀</button></div></article>
        <article class="mvp-card release-card"><p class="mvp-kicker">LATEST CHANGELOG</p><h3>{{ currentRelease?.version || '尚无版本记录' }}</h3><p>{{ currentRelease?.title || '更新版本日志后，这里会显示最新记录。' }}</p><Link href="/settings/changelog">查看完整版本历史</Link></article>
        <article class="mvp-card"><h3>常用设置</h3><div class="settings-links"><Link href="/settings/users">用户</Link><Link href="/settings/roles-permissions">角色与权限</Link><Link href="/settings/fx">汇率计算</Link><Link href="/settings/warehouses">仓库设置</Link><Link href="/settings/statuses">状态设置</Link><Link href="/settings/backup">备份与迁移</Link></div></article>
      </div>
      <div class="mvp-card"><div class="recent-heading"><h3>近期版本记录</h3></div><div v-if="recentReleases.length" class="recent-list"><Link v-for="note in recentReleases" :key="note.id" :href="changelogHref(note.released_on)"><strong>{{ note.version }}</strong><span>{{ note.title }}</span><small>{{ note.released_on }}</small></Link></div><p v-else>暂无版本记录。</p></div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}.settings-links,.recent-list{display:grid;gap:10px}.recent-heading{display:flex;justify-content:space-between;align-items:center}.recent-list a{display:grid;grid-template-columns:100px 1fr auto;gap:12px;text-decoration:none;padding:10px 0;border-top:1px solid var(--border,#dbe2e8)}@media(max-width:800px){.settings-grid{grid-template-columns:1fr}.recent-list a{grid-template-columns:1fr}}
</style>
