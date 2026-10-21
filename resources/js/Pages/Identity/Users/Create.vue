<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

defineProps<{ rolePresets: { value: string; label: string }[] }>()
const form = useForm({ name: '', email: '', password: '', password_confirmation: '', role: '' })
const submit = () => form.post('/settings/users')
</script>
<template>
  <Head title="新增用户 · BYL ERP" />
  <AuthenticatedLayout title="新增用户" :breadcrumbs="['BYL ERP', '设置', '用户', '新增']">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">系统设置</p><h2>新增用户</h2></div><div class="mvp-form-actions page-header-actions"><a class="mvp-button" href="/settings/users">取消</a><a class="mvp-button" href="/settings/users">返回列表</a><button type="submit" form="user-create-form" class="mvp-button mvp-button-primary" :disabled="form.processing">保存用户</button></div></div>
      <form id="user-create-form" class="mvp-form" @submit.prevent="submit">
        <div class="mvp-form-grid">
          <label>姓名<input v-model="form.name" required></label>
          <span v-if="form.errors.name" class="mvp-field-error">{{ form.errors.name }}</span>
          <label>邮箱<input v-model="form.email" type="email" required></label>
          <span v-if="form.errors.email" class="mvp-field-error">{{ form.errors.email }}</span>
          <label>密码<input v-model="form.password" type="password" required autocomplete="new-password"></label>
          <span v-if="form.errors.password" class="mvp-field-error">{{ form.errors.password }}</span>
          <label>确认密码<input v-model="form.password_confirmation" type="password" required autocomplete="new-password"></label>
          <label>角色
            <select v-model="form.role" required>
              <option value="" disabled>请选择角色</option>
              <option v-for="preset in rolePresets" :key="preset.value" :value="preset.value">{{ preset.label }}</option>
            </select>
          </label>
          <span v-if="form.errors.role" class="mvp-field-error">{{ form.errors.role }}</span>
        </div>
      </form>
    </section>
  </AuthenticatedLayout>
</template>
