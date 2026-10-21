<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import EmptyState from '../../Components/UI/EmptyState.vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

defineProps<{ module: { key: string; label: string } }>()
</script>

<template>
    <Head :title="`${module.label} · BYL ERP`" />
    <AuthenticatedLayout :title="module.label" :breadcrumbs="['BYL ERP', module.label]">
        <EmptyState
            class="shell-placeholder"
            aria-labelledby="placeholder-title"
            eyebrow="APPLICATION SHELL"
            :title="`${module.label}模块尚未开始实现`"
            description="此页面用于验证导航、权限和统一布局。业务功能将在后续授权工作包中交付。"
        />
    </AuthenticatedLayout>
</template>
