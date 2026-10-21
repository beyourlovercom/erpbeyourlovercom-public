<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import FormField from '../../Components/UI/FormField.vue'
import TextInput from '../../Components/UI/TextInput.vue'
import UiAlert from '../../Components/UI/UiAlert.vue'
import UiButton from '../../Components/UI/UiButton.vue'

const form = useForm({ email: '', password: '' })
const page = usePage<{ errors?: Record<string, string> }>()
const showPassword = ref(false)
const submit = () => form.post('/login')
</script>

<template>
    <Head>
        <title>BYL ERP · 登录</title>
        <meta name="robots" content="noindex, nofollow">
    </Head>
    <main data-byl-theme="dark" class="login-home min-h-screen overflow-hidden bg-[var(--byl-canvas)] text-[var(--byl-text)]">
        <div class="login-grid" aria-hidden="true">
            <span class="orbit orbit-a"></span>
            <span class="orbit orbit-b"></span>
            <i v-for="n in 7" :key="n" class="node" :class="`node-${n}`"></i>
        </div>
        <header class="relative z-10 flex items-center justify-between px-6 py-6 lg:px-12">
            <div class="brand-lockup"><span class="brand-mark">BYL</span><span>ERP</span></div>
            <div class="system-status"><span></span>SYSTEM ONLINE</div>
        </header>
        <div class="relative z-10 mx-auto grid min-h-[calc(100vh-100px)] max-w-7xl items-center gap-12 px-6 pb-14 lg:grid-cols-2 lg:px-12">
            <section class="login-visual hidden lg:block">
                <p class="text-xs font-semibold tracking-[0.28em] text-[var(--byl-accent)]">BUSINESS OPERATING SYSTEM</p>
                <div class="visual-line"></div>
                <p class="max-w-sm text-sm leading-7 text-[var(--byl-text-muted)]">让订单、客户、物流与团队协作保持清晰连接。</p>
            </section>
            <section class="login-panel w-full max-w-md justify-self-center lg:justify-self-end">
                <p class="text-xs font-semibold tracking-[0.24em] text-[var(--byl-accent)]">BUSINESS OPERATING SYSTEM</p>
                <h1 class="mt-5 text-4xl font-semibold tracking-tight sm:text-5xl">统一管理<br><span class="text-[var(--byl-accent)]">高效协同</span></h1>
                <p class="mt-5 text-sm leading-7 text-[var(--byl-text-muted)]">连接订单、客户、物流与团队协作，<br>让每一项业务清晰可见、持续推进。</p>
                <form class="mt-8 space-y-5" @submit.prevent="submit">
                    <FormField id="email" v-slot="{ controlId, describedBy, invalid }" label="名称或邮箱" required>
                        <TextInput v-model="form.email" :id="controlId" name="email" type="text" autocomplete="username" placeholder="用户名或邮箱" required :aria-describedby="describedBy" :invalid="invalid" />
                    </FormField>
                    <FormField id="password" v-slot="{ controlId, describedBy, invalid }" label="密码" required>
                        <TextInput v-model="form.password" :id="controlId" name="password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required :aria-describedby="describedBy" :invalid="invalid">
                            <template #trailing>
                                <UiButton tone="quiet" size="sm" :aria-label="showPassword ? '隐藏密码' : '显示密码'" @click="showPassword = !showPassword">{{ showPassword ? '隐藏' : '显示' }}</UiButton>
                            </template>
                        </TextInput>
                    </FormField>
                    <UiAlert v-if="form.errors.email || form.errors.password || page.props.errors?.email" tone="error">名称/邮箱或密码不正确，请重试。</UiAlert>
                    <UiButton class="w-full" type="submit" tone="primary" :loading="form.processing">
                        登录系统
                        <template #loading>验证中…</template>
                    </UiButton>
                </form>
                <p class="mt-6 border-t border-[var(--byl-border-subtle)] pt-5 text-xs text-[var(--byl-text-muted)]"><span class="text-[var(--byl-text-secondary)]">申请开通账户</span>：BYL ERP 账户由管理员统一创建，请联系系统管理员开通。</p>
            </section>
        </div>
    </main>
</template>

<style scoped>
.login-grid {
    position: absolute;
    inset: 0;
    background-image: linear-gradient(var(--byl-color-auth-grid) 1px, transparent 1px), linear-gradient(90deg, var(--byl-color-auth-grid) 1px, transparent 1px);
    background-size: 64px 64px;
    mask-image: linear-gradient(90deg, #000, transparent 78%);
}

.orbit {
    position: absolute;
    border: var(--byl-border-width) solid var(--byl-color-auth-orbit);
    border-radius: var(--byl-radius-round);
    transform: rotate(-24deg);
}

.orbit-a { width: 46vw; height: 22vw; left: 4vw; top: 34vh; }
.orbit-b { width: 32vw; height: 14vw; left: 15vw; top: 47vh; }

.node {
    position: absolute;
    width: 5px;
    height: 5px;
    border-radius: var(--byl-radius-round);
    background: var(--byl-color-auth-node);
    box-shadow: 0 0 18px var(--byl-color-auth-glow);
}

.node-1 { left: 18%; top: 30%; }
.node-2 { left: 43%; top: 22%; }
.node-3 { left: 35%; top: 68%; }
.node-4 { left: 11%; top: 73%; }
.node-5 { left: 48%; top: 52%; }
.node-6 { left: 27%; top: 46%; }
.node-7 { left: 55%; top: 34%; }

.brand-lockup {
    display: flex;
    align-items: center;
    gap: var(--byl-space-8);
    font-size: 13px;
    letter-spacing: 0.18em;
    color: var(--byl-color-auth-brand-muted);
}

.brand-mark {
    font-size: var(--byl-font-size-20);
    font-weight: var(--byl-font-weight-extrabold);
    letter-spacing: 0.08em;
    color: var(--byl-color-white);
}

.system-status {
    display: flex;
    align-items: center;
    gap: var(--byl-space-8);
    font-size: var(--byl-font-size-10);
    letter-spacing: 0.18em;
    color: var(--byl-color-auth-status-muted);
}

.system-status span {
    width: var(--byl-space-6);
    height: var(--byl-space-6);
    border-radius: var(--byl-radius-round);
    background: var(--byl-color-auth-online);
    box-shadow: 0 0 var(--byl-space-10) var(--byl-color-auth-online);
}

.visual-line {
    width: 150px;
    height: var(--byl-border-width);
    margin: var(--byl-space-28) 0 var(--byl-space-20);
    background: var(--byl-color-auth-line);
}

@media (max-width: 1024px) {
    .login-grid { mask-image: linear-gradient(90deg, #000, transparent 100%); }
    .login-panel {
        background: var(--byl-color-auth-panel);
        padding: var(--byl-space-24);
        border: var(--byl-border-width) solid var(--byl-color-auth-panel-border);
        border-radius: var(--byl-radius-2xl);
    }
}

@media (prefers-reduced-motion: reduce) {
    .orbit { transform: none; }
}
</style>
