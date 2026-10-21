<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

defineProps<{ owners: { id: number; name: string }[] }>()
const form = useForm({ name: '', legal_name: '', website_url: '', country_code: '', registration_number: '', industry: '', owner_id: '' })
const submit = () => form.post('/customers/companies')
</script>
<template>
  <Head title="新建公司 · BYL ERP" />
  <AuthenticatedLayout title="新建公司" :breadcrumbs="['BYL ERP', '客户', '公司', '新建']">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">客户主数据</p><h2>新建公司</h2></div><div class="mvp-form-actions page-header-actions"><a class="mvp-button" href="/customers/companies">取消</a><a class="mvp-button" href="/customers/companies">返回列表</a><button type="submit" form="company-create-form" class="mvp-button mvp-button-primary" :disabled="form.processing">保存公司</button></div></div>
      <form id="company-create-form" class="mvp-form" @submit.prevent="submit">
        <div class="mvp-form-grid">
          <label>名称<input v-model="form.name" required></label>
          <span v-if="form.errors.name" class="mvp-field-error">{{ form.errors.name }}</span>
          <label>法定名称<input v-model="form.legal_name"></label>
          <label>网址<input v-model="form.website_url" placeholder="https://example.com"></label>
          <span v-if="form.errors.website_url" class="mvp-field-error">{{ form.errors.website_url }}</span>
          <label>国家代码<input v-model="form.country_code" maxlength="2" placeholder="US"></label>
          <span v-if="form.errors.country_code" class="mvp-field-error">{{ form.errors.country_code }}</span>
          <label>注册号<input v-model="form.registration_number"></label>
          <label>行业<input v-model="form.industry"></label>
          <label>负责人<select v-model="form.owner_id"><option value="">未指定</option><option v-for="owner in owners" :key="owner.id" :value="owner.id">{{ owner.name }}</option></select></label>
        </div>
      </form>
    </section>
  </AuthenticatedLayout>
</template>
