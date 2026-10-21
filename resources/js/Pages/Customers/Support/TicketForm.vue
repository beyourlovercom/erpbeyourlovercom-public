<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Ticket = { id: number; subject: string; description: string | null; status: string; priority: string; assigned_user_id: number | null }
const props = defineProps<{ customer: { id: number; name: string; customer_number: string }; ticket: Ticket | null; statuses: string[]; priorities: string[]; users: { id: number; name: string }[]; editing?: boolean }>()
const form = ref({ subject: props.ticket?.subject ?? '', description: props.ticket?.description ?? '', priority: props.ticket?.priority ?? 'normal', assigned_user_id: props.ticket?.assigned_user_id ? String(props.ticket.assigned_user_id) : '', status: props.ticket?.status ?? 'open' })
const errors = ref<Record<string, string>>({})
const submitting = ref(false)
const submit = () => { submitting.value = true; errors.value = {}; const options = { onError: (e: Record<string, string>) => { errors.value = e }, onFinish: () => { submitting.value = false } }; props.editing ? router.patch(`/customers/library/${props.customer.id}/support-tickets/${props.ticket?.id}`, form.value, options) : router.post(`/customers/library/${props.customer.id}/support-tickets`, form.value, options) }
const label = (value: string) => ({ open: '待处理', waiting_customer: '等待客户', waiting_internal: '内部处理中', resolved: '已解决', closed: '已关闭', low: '低', normal: '普通', high: '高', urgent: '紧急' } as Record<string, string>)[value] ?? value
</script>
<template>
    <Head :title="`${editing ? '编辑' : '新建'}支持工单 · BYL ERP`" />
    <AuthenticatedLayout title="客户支持工单" :breadcrumbs="['BYL ERP', '客户', customer.name, editing ? '编辑工单' : '新建工单']">
        <div class="erp-form-page"><div class="erp-form-content">
            <div class="erp-form-header-row"><div><p class="erp-form-subtitle">{{ customer.customer_number }} · {{ customer.name }}</p><h1 class="erp-form-title">{{ editing ? '编辑支持工单' : '新建支持工单' }}</h1><p class="erp-form-subtitle">客户关联由系统固定为当前 Customer ID，来源为手工录入。</p></div><div class="erp-form-header-actions page-header-actions"><Link class="erp-btn erp-btn-secondary" :href="editing ? `/customers/library/${customer.id}/support-tickets/${ticket?.id}` : `/customers/library/${customer.id}`">取消</Link><button class="erp-btn erp-btn-primary" type="button" :disabled="submitting" @click="submit">{{ submitting ? '保存中…' : (editing ? '保存更新' : '创建工单') }}</button></div></div>
            <article class="erp-form-card"><h2 class="erp-form-card-title">工单内容</h2><div class="support-form-grid">
                <div class="erp-form-field support-form-wide"><label class="erp-form-label" for="ticket-subject">主题 <span class="erp-form-required">*</span></label><input id="ticket-subject" v-model="form.subject" class="erp-input" :class="{ 'has-error': errors.subject }"><p v-if="errors.subject" class="erp-field-error">{{ errors.subject }}</p></div>
                <div class="erp-form-field"><label class="erp-form-label" for="ticket-priority">优先级</label><select id="ticket-priority" v-model="form.priority" class="erp-select" :class="{ 'has-error': errors.priority }"><option v-for="value in priorities" :key="value" :value="value">{{ label(value) }}</option></select><p v-if="errors.priority" class="erp-field-error">{{ errors.priority }}</p></div>
                <div class="erp-form-field"><label class="erp-form-label" for="ticket-assignee">负责人</label><select id="ticket-assignee" v-model="form.assigned_user_id" class="erp-select" :class="{ 'has-error': errors.assigned_user_id }"><option value="">未指定</option><option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option></select><p v-if="errors.assigned_user_id" class="erp-field-error">{{ errors.assigned_user_id }}</p></div>
                <div v-if="editing" class="erp-form-field"><label class="erp-form-label" for="ticket-status">状态</label><select id="ticket-status" v-model="form.status" class="erp-select" :class="{ 'has-error': errors.status }"><option v-for="value in statuses" :key="value" :value="value">{{ label(value) }}</option></select><p v-if="errors.status" class="erp-field-error">{{ errors.status }}</p></div>
                <div class="erp-form-field support-form-wide"><label class="erp-form-label" for="ticket-description">描述</label><textarea id="ticket-description" v-model="form.description" class="erp-textarea" :class="{ 'has-error': errors.description }" rows="5" /><p v-if="errors.description" class="erp-field-error">{{ errors.description }}</p></div>
            </div></article>
        </div></div>
    </AuthenticatedLayout>
</template>
<style scoped>.support-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.support-form-wide{grid-column:1/-1}@media(max-width:700px){.support-form-grid{grid-template-columns:1fr}}</style>
