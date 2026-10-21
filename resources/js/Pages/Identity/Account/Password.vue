<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

const form = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const submit = () => form.put('/account/password', {
  preserveScroll: true,
  onSuccess: () => form.reset(),
})
</script>

<template>
  <Head title="修改密码 · BYL ERP" />
  <AuthenticatedLayout title="修改密码" :breadcrumbs="['BYL ERP', '账户', '修改密码']">
    <section class="mvp-page account-self-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">账户安全</p><h2>修改密码</h2></div>
      </div>
      <form class="mvp-form" @submit.prevent="submit">
        <p class="mvp-muted">修改当前登录账户的密码。必须先验证原密码。</p>
        <div class="mvp-form-grid">
          <label>原密码<input v-model="form.current_password" type="password" required autocomplete="current-password"></label>
          <span v-if="form.errors.current_password" class="mvp-field-error">{{ form.errors.current_password }}</span>
          <label>新密码<input v-model="form.password" type="password" required autocomplete="new-password"></label>
          <span v-if="form.errors.password" class="mvp-field-error">{{ form.errors.password }}</span>
          <label>确认新密码<input v-model="form.password_confirmation" type="password" required autocomplete="new-password"></label>
        </div>
        <p class="mvp-muted">新密码至少 12 个字符，并包含大写字母、小写字母和数字。</p>
        <div class="mvp-form-actions">
          <button class="mvp-button mvp-button-primary" :disabled="form.processing">保存新密码</button>
        </div>
      </form>
    </section>
  </AuthenticatedLayout>
</template>