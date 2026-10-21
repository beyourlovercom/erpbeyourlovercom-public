<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

const props = defineProps<{
    owners: { id: number; name: string }[]
    sources: Record<string, string>
}>()

const breadcrumbs = [
    { label: 'BYL ERP', href: '/dashboard' },
    { label: '客户', href: '/customers' },
    { label: '客户库', href: '/customers/library' },
    '新建客户',
]

const emptyAddress = () => ({
    name: '', company: '', phone: '', country_code: '',
    province_state: '', city: '', address_line_1: '', address_line_2: '', postal_code: '',
})

const form = ref({
    name: '',
    email: '',
    phone: '',
    country_code: '',
    language_code: '',
    source_type: 'manual',
    owner_id: '',
    notes: '',
    billing_address: emptyAddress(),
    shipping_address: emptyAddress(),
})
const errors = ref<Record<string, string>>({})
const submitting = ref(false)

const submit = () => {
    submitting.value = true
    errors.value = {}
    router.post('/customers/library', form.value, {
        onError: (e) => { errors.value = e },
        onFinish: () => { submitting.value = false },
    })
}
</script>
<template>
    <Head title="新建客户 · BYL ERP" />
    <AuthenticatedLayout title="新建客户" :breadcrumbs="breadcrumbs">
        <div class="erp-form-page">
            <div class="erp-form-content">
                <div class="erp-form-header-row">
                    <div>
                        <h1 class="erp-form-title">新建客户</h1>
                        <p class="erp-form-subtitle">创建正式 Customer ID。历史订单不会自动猜测或合并身份。</p>
                    </div>
                    <div class="erp-form-header-actions page-header-actions">
                        <a href="/customers/library" class="erp-btn erp-btn-secondary">取消</a>
                        <button type="submit" form="customer-create-form" class="erp-btn erp-btn-primary" :disabled="submitting">{{ submitting ? '提交中…' : '保存客户' }}</button>
                    </div>
                </div>

                <form id="customer-create-form" @submit.prevent="submit">
                    <div class="erp-form-card">
                        <h2 class="erp-form-card-title">基本信息</h2>
                        <div class="erp-form-grid">

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="create-name">
                                    姓名 <span class="erp-form-required">*</span>
                                </label>
                                <input id="create-name" v-model="form.name" type="text" class="erp-input" :class="{ 'has-error': errors.name }" required>
                                <p v-if="errors.name" class="erp-field-error">{{ errors.name }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="create-email">Email</label>
                                <input id="create-email" v-model="form.email" type="email" class="erp-input" :class="{ 'has-error': errors.email }">
                                <p v-if="errors.email" class="erp-field-error">{{ errors.email }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="create-phone">电话</label>
                                <input id="create-phone" v-model="form.phone" type="text" class="erp-input" :class="{ 'has-error': errors.phone }">
                                <p v-if="errors.phone" class="erp-field-error">{{ errors.phone }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="create-country">国家代码</label>
                                <input id="create-country" v-model="form.country_code" type="text" maxlength="2" class="erp-input" :class="{ 'has-error': errors.country_code }" placeholder="US">
                                <p v-if="errors.country_code" class="erp-field-error">{{ errors.country_code }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="create-language">语言</label>
                                <input id="create-language" v-model="form.language_code" type="text" class="erp-input" :class="{ 'has-error': errors.language_code }" placeholder="English">
                                <p v-if="errors.language_code" class="erp-field-error">{{ errors.language_code }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="create-source">
                                    来源 <span class="erp-form-required">*</span>
                                </label>
                                <select id="create-source" v-model="form.source_type" class="erp-select" :class="{ 'has-error': errors.source_type }">
                                    <option v-for="(label, key) in props.sources" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <p v-if="errors.source_type" class="erp-field-error">{{ errors.source_type }}</p>
                            </div>

                            <div class="erp-form-field">
                                <label class="erp-form-label" for="create-owner">负责人</label>
                                <select id="create-owner" v-model="form.owner_id" class="erp-select">
                                    <option value="">未指定</option>
                                    <option v-for="owner in props.owners" :key="owner.id" :value="owner.id">{{ owner.name }}</option>
                                </select>
                            </div>

                            <div class="erp-form-field" style="grid-column: 1 / -1;">
                                <label class="erp-form-label" for="create-notes">备注</label>
                                <textarea id="create-notes" v-model="form.notes" class="erp-textarea" :class="{ 'has-error': errors.notes }" rows="4" />
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
