<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type SelfProfile = {
  name: string
  email: string
  contact_name: string | null
  mobile: string | null
  phone: string | null
  qq: string | null
}

const props = defineProps<{ profile: SelfProfile }>()
const form = useForm({
  name: props.profile.name,
  email: props.profile.email,
  contact_name: props.profile.contact_name ?? '',
  mobile: props.profile.mobile ?? '',
  phone: props.profile.phone ?? '',
  qq: props.profile.qq ?? '',
})

const submit = () => form.patch('/account/profile', { preserveScroll: true })
</script>

<template>
  <Head title="个人信息 · BYL ERP" />
  <AuthenticatedLayout title="个人信息" :breadcrumbs="['BYL ERP', '账户', '个人信息']">
    <section class="mvp-page account-self-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">账户设置</p><h2>个人信息</h2></div>
      </div>
      <form class="mvp-form" @submit.prevent="submit">
        <p class="mvp-muted">这里只修改当前登录账户自己的资料；角色、权限和启用状态仍由管理员管理。</p>
        <div class="mvp-form-grid">
          <label>用户名<input v-model="form.name" required autocomplete="name"></label>
          <span v-if="form.errors.name" class="mvp-field-error">{{ form.errors.name }}</span>
          <label>邮箱<input v-model="form.email" type="email" required autocomplete="email"></label>
          <span v-if="form.errors.email" class="mvp-field-error">{{ form.errors.email }}</span>
          <label>联系人<input v-model="form.contact_name" autocomplete="name"></label>
          <span v-if="form.errors.contact_name" class="mvp-field-error">{{ form.errors.contact_name }}</span>
          <label>手机<input v-model="form.mobile" inputmode="tel" autocomplete="tel"></label>
          <span v-if="form.errors.mobile" class="mvp-field-error">{{ form.errors.mobile }}</span>
          <label>电话<input v-model="form.phone" inputmode="tel"></label>
          <span v-if="form.errors.phone" class="mvp-field-error">{{ form.errors.phone }}</span>
          <label>QQ<input v-model="form.qq" inputmode="numeric"></label>
          <span v-if="form.errors.qq" class="mvp-field-error">{{ form.errors.qq }}</span>
        </div>
        <div class="mvp-form-actions">
          <button class="mvp-button mvp-button-primary" :disabled="form.processing">保存个人信息</button>
        </div>
      </form>
    </section>
  </AuthenticatedLayout>
</template>