<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Company = { id: number; name: string; legal_name: string | null; website_url: string | null; country_code: string | null; registration_number: string | null; industry: string | null; owner: { id: number; name: string } | null }
const props = defineProps<{ company: Company; owners: { id: number; name: string }[] }>()
const form = useForm({
  name: props.company.name,
  legal_name: props.company.legal_name ?? '',
  website_url: props.company.website_url ?? '',
  country_code: props.company.country_code ?? '',
  registration_number: props.company.registration_number ?? '',
  industry: props.company.industry ?? '',
  owner_id: props.company.owner?.id ?? '',
})
const submit = () => form.patch(`/customers/companies/${props.company.id}`)
</script>
<template>
  <Head title="编辑公司 · BYL ERP" />
  <AuthenticatedLayout title="编辑公司" :breadcrumbs="['BYL ERP', '客户', '公司', company.name, '编辑']">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">客户主数据</p><h2>编辑 {{ company.name }}</h2></div><div class="mvp-form-actions page-header-actions"><a class="mvp-button" :href="`/customers/companies/${company.id}`">取消</a><a class="mvp-button" href="/customers/companies">返回列表</a><button type="submit" form="company-edit-form" class="mvp-button mvp-button-primary" :disabled="form.processing">保存修改</button></div></div>
      <form id="company-edit-form" class="mvp-form" @submit.prevent="submit">
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
