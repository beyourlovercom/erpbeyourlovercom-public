<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
const labels: Record<string, string> = { newsletters: '群发邮件', campaigns: '营销活动', automations: '自动化营销', promotions: '优惠促销' }
const props = defineProps<{ workspace: string }>()
</script>
<template>
  <Head :title="`${labels[props.workspace]} · BYL ERP`" />
  <AuthenticatedLayout :title="labels[props.workspace]" :breadcrumbs="['BYL ERP', '营销', labels[props.workspace]]">
    <div class="erp-form-page"><div class="erp-form-content"><section class="erp-form-card planned-workspace"><p class="mvp-kicker">MARKETING OS · PLANNED</p><h1 class="erp-form-title">{{ labels[props.workspace] }}</h1><p>此工作区已纳入 Marketing OS 路线图，但当前 Work Package 尚未实现其引擎。</p><p class="planned-note">暂无活动、发送、优惠券、收入或自动化指标；系统未生成模拟记录。</p></section></div></div>
  </AuthenticatedLayout>
</template>
<style scoped>.planned-workspace{max-width:720px}.planned-workspace p{color:#52646b}.planned-note{font-style:italic;color:#7a8c94!important}</style>
