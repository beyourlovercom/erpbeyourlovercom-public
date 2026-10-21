<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Customer = {
    id: number
    name: string
    email: string | null
    phone: string | null
    country_code: string | null
    language_code: string | null
    source_type: string
    owner: { id: number; name: string } | null
    notes: string | null
}
type Address = {
    name: string | null
    company: string | null
    phone: string | null
    country_code: string | null
    province_state: string | null
    city: string | null
    address_line_1: string | null
    address_line_2: string | null
    postal_code: string | null
} | null

const props = defineProps<{
    customer: Customer
    billingAddress: Address
    shippingAddress: Address
    owners: { id: number; name: string }[]
    sources: Record<string, string>
}>()

const breadcrumbs = [
    { label: 'BYL ERP', href: '/dashboard' },
    { label: '客户', href: '/customers' },
    { label: '客户库', href: '/customers/library' },
    { label: props.customer.name, href: `/customers/library/${props.customer.id}` },
    '编辑',
]

const addrFields = (addr: Address) => ({
    name: addr?.name ?? '',
    company: addr?.company ?? '',
    phone: addr?.phone ?? '',
    country_code: addr?.country_code ?? '',
    province_state: addr?.province_state ?? '',
    city: addr?.city ?? '',
    address_line_1: addr?.address_line_1 ?? '',
    address_line_2: addr?.address_line_2 ?? '',
    postal_code: addr?.postal_code ?? '',
})

const form = ref({
    name: props.customer.name,
    email: props.customer.email ?? '',
    phone: props.customer.phone ?? '',
    country_code: props.customer.country_code ?? '',
    language_code: props.customer.language_code ?? '',
    source_type: props.customer.source_type,
    owner_id: props.customer.owner?.id ?? '',
    notes: props.customer.notes ?? '',
    billing_address: addrFields(props.billingAddress),
    shipping_address: addrFields(props.shippingAddress),
})
const errors = ref<Record<string, string>>({})
const submitting = ref(false)

const submit = () => {
    submitting.value = true
    errors.value = {}
    router.patch(`/customers/library/${props.customer.id}`, form.value, {
        onError: (e) => { errors.value = e },
        onFinish: () => { submitting.value = false },
    })
}
</script>
<template>
    <Head :title="`编辑 ${customer.name} · BYL ERP`" />
    <AuthenticatedLayout title="编辑客户" :breadcrumbs="breadcrumbs">
        <div class="erp-form-page">
            <div class="erp-form-content">
                <div class="erp-form-header-row">
                    <div>
                        <h1 class="erp-form-title">编辑 {{ customer.name }}</h1>
                        <p class="erp-form-subtitle">修改客户基本信息、账单地址和收货地址</p>
                    </div>
                    <div class="erp-form-header-actions page-header-actions">
                        <a :href="`/customers/library/${customer.id}`" class="erp-btn erp-btn-secondary">取消</a>
                        <button type="submit" form="customer-edit-form" class="erp-btn erp-btn-primary" :disabled="submitting">{{ submitting ? '提交中…' : '保存修改' }}</button>
                    </div>
                </div>

                <form id="customer-edit-form" @submit.prevent="submit">
                    <div class="erp-form-card">
                        <h2 class="erp-form-card-title">基本信息</h2>
                        <div class="erp-form-grid">

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="edit-name">
                                    姓名 <span class="erp-form-required">*</span>
                                </label>
                                <input id="edit-name" v-model="form.name" type="text" class="erp-input" :class="{ 'has-error': errors.name }" required>
                                <p v-if="errors.name" class="erp-field-error">{{ errors.name }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="edit-email">Email</label>
                                <input id="edit-email" v-model="form.email" type="email" class="erp-input" :class="{ 'has-error': errors.email }">
                                <p v-if="errors.email" class="erp-field-error">{{ errors.email }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="edit-phone">电话</label>
                                <input id="edit-phone" v-model="form.phone" type="text" class="erp-input" :class="{ 'has-error': errors.phone }">
                                <p v-if="errors.phone" class="erp-field-error">{{ errors.phone }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="edit-country">国家代码</label>
                                <input id="edit-country" v-model="form.country_code" type="text" maxlength="2" class="erp-input" :class="{ 'has-error': errors.country_code }">
                                <p v-if="errors.country_code" class="erp-field-error">{{ errors.country_code }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="edit-language">语言</label>
                                <input id="edit-language" v-model="form.language_code" type="text" class="erp-input" :class="{ 'has-error': errors.language_code }">
                                <p v-if="errors.language_code" class="erp-field-error">{{ errors.language_code }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="edit-source">
                                    来源 <span class="erp-form-required">*</span>
                                </label>
                                <select id="edit-source" v-model="form.source_type" class="erp-select" :class="{ 'has-error': errors.source_type }">
                                    <option v-for="(label, key) in sources" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <p v-if="errors.source_type" class="erp-field-error">{{ errors.source_type }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="edit-owner">负责人</label>
                                <select id="edit-owner" v-model="form.owner_id" class="erp-select">
                                    <option value="">未指定</option>
                                    <option v-for="owner in owners" :key="owner.id" :value="owner.id">{{ owner.name }}</option>
                                </select>
                            </div>

                            <div class="erp-form-field" style="grid-column: 1 / -1;">
                                <label class="erp-form-label" for="edit-notes">备注</label>
                                <textarea id="edit-notes" v-model="form.notes" class="erp-textarea" :class="{ 'has-error': errors.notes }" rows="4" />
                                <p v-if="errors.notes" class="erp-field-error">{{ errors.notes }}</p>
                            </div>

                        </div>
                    </div>

                    <div class="erp-form-card">
                        <h2 class="erp-form-card-title">账单地址</h2>
                        <div class="erp-form-grid">
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="billing-name">收件人姓名</label>
                                <input id="billing-name" v-model="form.billing_address.name" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="billing-company">公司</label>
                                <input id="billing-company" v-model="form.billing_address.company" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="billing-phone">电话</label>
                                <input id="billing-phone" v-model="form.billing_address.phone" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="billing-country">国家代码</label>
                                <input id="billing-country" v-model="form.billing_address.country_code" type="text" maxlength="2" class="erp-input" placeholder="US">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="billing-state">省/州</label>
                                <input id="billing-state" v-model="form.billing_address.province_state" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="billing-city">城市</label>
                                <input id="billing-city" v-model="form.billing_address.city" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field" style="grid-column: 1 / -1;">
                                <label class="erp-form-label" for="billing-line1">地址第一行</label>
                                <input id="billing-line1" v-model="form.billing_address.address_line_1" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field" style="grid-column: 1 / -1;">
                                <label class="erp-form-label" for="billing-line2">地址第二行</label>
                                <input id="billing-line2" v-model="form.billing_address.address_line_2" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="billing-postal">邮编</label>
                                <input id="billing-postal" v-model="form.billing_address.postal_code" type="text" class="erp-input">
                            </div>
                        </div>
                    </div>

                    <div class="erp-form-card">
                        <h2 class="erp-form-card-title">收货地址</h2>
                        <div class="erp-form-grid">
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="shipping-name">收件人姓名</label>
                                <input id="shipping-name" v-model="form.shipping_address.name" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="shipping-company">公司</label>
                                <input id="shipping-company" v-model="form.shipping_address.company" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="shipping-phone">电话</label>
                                <input id="shipping-phone" v-model="form.shipping_address.phone" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="shipping-country">国家代码</label>
                                <input id="shipping-country" v-model="form.shipping_address.country_code" type="text" maxlength="2" class="erp-input" placeholder="US">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="shipping-state">省/州</label>
                                <input id="shipping-state" v-model="form.shipping_address.province_state" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="shipping-city">城市</label>
                                <input id="shipping-city" v-model="form.shipping_address.city" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field" style="grid-column: 1 / -1;">
                                <label class="erp-form-label" for="shipping-line1">地址第一行</label>
                                <input id="shipping-line1" v-model="form.shipping_address.address_line_1" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field" style="grid-column: 1 / -1;">
                                <label class="erp-form-label" for="shipping-line2">地址第二行</label>
                                <input id="shipping-line2" v-model="form.shipping_address.address_line_2" type="text" class="erp-input">
                            </div>
                            <div class="erp-form-field">
                                <label class="erp-form-label" for="shipping-postal">邮编</label>
                                <input id="shipping-postal" v-model="form.shipping_address.postal_code" type="text" class="erp-input">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
