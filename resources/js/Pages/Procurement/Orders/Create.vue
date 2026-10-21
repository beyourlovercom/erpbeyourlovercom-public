<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Item = { id: number; product_id: number | null; sku: string | null; product_summary: string; quantity: number }
const props = defineProps<{ order: { id: number; erp_order_id: string | null; external_order_number: string; order_status: string; items: Item[] } }>()
const form = useForm({ items: Object.fromEntries(props.order.items.map(item => [item.id, { purchase_url: '', purchase_size: '', purchase_quantity: item.quantity }])) })
const submit = () => form.post(`/purchase/orders/from-order/${props.order.id}`)
</script>

<template>
  <Head title="创建采购订单 · BYL ERP" />
  <AuthenticatedLayout title="创建采购订单" :breadcrumbs="[{ label: '采购', href: '/purchase' }, { label: '订单', href: '/customers/orders' }, '创建采购订单']">
    <div class="erp-form-page"><div class="erp-form-content">
      <div class="erp-form-header-row"><div><p class="ord-kicker">缺货订单</p><h1 class="erp-form-title">为 {{ order.erp_order_id ?? '—' }} 创建采购订单</h1><p class="erp-form-subtitle">订单与产品身份来自源订单，只填写本次采购信息。</p></div><div class="erp-form-header-actions page-header-actions"><a :href="`/customers/orders/${order.id}`" class="erp-btn erp-btn-secondary">取消</a><button class="erp-btn erp-btn-primary" :disabled="form.processing" @click="submit">创建采购订单</button></div></div>
      <div class="erp-form-card"><h2 class="erp-form-card-title">采购明细</h2><div v-for="item in order.items" :key="item.id" class="procurement-line">
        <div class="procurement-identity"><strong>{{ item.sku || '—' }}</strong><span>{{ item.product_summary || '未提供产品摘要' }}</span><small>源订单数量：{{ item.quantity }}</small></div>
        <div class="erp-form-field"><label class="erp-form-label">采购链接</label><input v-model="form.items[item.id].purchase_url" class="erp-input" type="url" placeholder="https://..."><p v-if="form.errors[`items.${item.id}.purchase_url` as keyof typeof form.errors]" class="erp-field-error">链接格式不正确</p></div>
        <div class="erp-form-field"><label class="erp-form-label">尺码 / 规格</label><input v-model="form.items[item.id].purchase_size" class="erp-input"></div>
        <div class="erp-form-field"><label class="erp-form-label">采购数量</label><input v-model="form.items[item.id].purchase_quantity" class="erp-input" type="number" min="1" step="1"></div>
      </div></div>
    </div></div>
  </AuthenticatedLayout>
</template>

<style scoped>
.erp-form-header-actions { display:flex; gap:8px; align-items:center; justify-content:flex-end; flex-wrap:wrap; }
.procurement-line { display:grid; grid-template-columns:1.2fr 1.4fr 1fr .7fr; gap:16px; align-items:start; padding:16px 0; border-bottom:1px solid #e4eaed; }
.procurement-line:last-child { border-bottom:0; }.procurement-identity { display:flex; flex-direction:column; gap:5px; padding-top:8px; }.procurement-identity span,.procurement-identity small { color:#7a8c94; font-size:12px; }
@media (max-width: 767px) { .procurement-line { grid-template-columns:1fr; gap:10px; } }
</style>
