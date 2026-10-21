<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type UserRow = {
  id: number
  name: string
  email: string
  is_active: boolean
  role: { key: string; label: string; preset: string | null } | null
  created_at: string | null
}
const props = defineProps<{ users: UserRow[]; rolePresets: { value: string; label: string }[]; selfId: number | null }>()

const isSelf = (user: UserRow): boolean => props.selfId !== null && user.id === props.selfId

const toggleActive = (user: UserRow) => {
  const action = user.is_active ? '停用' : '启用'
  if (!window.confirm(`确认${action}用户「${user.name}」？`)) return
  router.post(`/settings/users/${user.id}/${user.is_active ? 'disable' : 'enable'}`)
}

const destroy = (user: UserRow) => {
  if (!window.confirm(`确认删除用户「${user.name}」？此操作不可恢复。`)) return
  router.delete(`/settings/users/${user.id}`)
}
</script>
<template>
  <Head title="用户 · BYL ERP" />
  <AuthenticatedLayout title="用户" :breadcrumbs="['BYL ERP', '设置', '用户']">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">系统设置</p><h2>用户</h2><p>管理 ERP 用户账户、状态与角色权限模板。</p></div>
        <Link class="mvp-button mvp-button-primary" href="/settings/users/create">新增用户</Link>
      </div>
      <div class="mvp-table-wrap">
        <table class="mvp-table">
          <thead><tr><th>姓名</th><th>邮箱</th><th>角色</th><th>状态</th><th></th></tr></thead>
          <tbody>
            <tr v-for="user in users" :key="user.id">
              <td>{{ user.name }}</td>
              <td>{{ user.email }}</td>
              <td>{{ user.role?.label ?? '未分配' }}</td>
              <td><span class="mvp-status">{{ user.is_active ? '已启用' : '已停用' }}</span></td>
              <td>
                <Link :href="`/settings/users/${user.id}/edit`">编辑</Link>
                <button class="mvp-button" type="button" :disabled="isSelf(user) && user.is_active" @click="toggleActive(user)">
                  {{ user.is_active ? '停用' : '启用' }}
                </button>
                <button class="mvp-button" type="button" :disabled="isSelf(user)" @click="destroy(user)">删除</button>
              </td>
            </tr>
            <tr v-if="users.length === 0"><td colspan="5" class="mvp-empty">暂无用户</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
