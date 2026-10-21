<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'

defineProps<{ errorId: string }>()
const retry = () => router.reload()
const back = () => window.history.length > 1 ? window.history.back() : router.visit('/dashboard')
</script>

<template>
  <Head title="系统暂时不可用 · BYL ERP" />
  <main class="erp-error-page">
    <section class="erp-error-card" role="alert">
      <p class="erp-error-kicker">BYL ERP</p>
      <h1>系统暂时不可用</h1>
      <p>请求未能完成。请稍后重试，或返回上一页继续操作。</p>
      <p class="erp-error-id">错误 ID：<code>{{ errorId }}</code></p>
      <div class="erp-error-actions">
        <button type="button" class="mvp-button mvp-button-primary" @click="retry">重试</button>
        <button type="button" class="mvp-button" @click="back">返回</button>
      </div>
    </section>
  </main>
</template>

<style scoped>
.erp-error-page { min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f4f7f8; color: #17303a; }
.erp-error-card { width: min(520px, 100%); padding: 32px; border: 1px solid #d7e0e3; border-radius: 14px; background: #fff; box-shadow: 0 12px 30px rgb(23 48 58 / 10%); }
.erp-error-kicker { margin: 0 0 8px; color: #5f737c; font-size: 12px; letter-spacing: .12em; }
.erp-error-card h1 { margin: 0 0 12px; font-size: 28px; }
.erp-error-id { margin-top: 20px; color: #5f737c; font-size: 13px; }
.erp-error-id code { color: #17303a; }
.erp-error-actions { display: flex; gap: 10px; margin-top: 24px; }
</style>
