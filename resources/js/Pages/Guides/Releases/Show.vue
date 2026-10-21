<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type Toc={id:string;label:string};type ReleaseNote={id:number;version:string;title:string;summary:string|null;status:string;released_on:string|null}
defineProps<{releaseNote:ReleaseNote;html:string;toc:Toc[];canManage:boolean}>()
</script>
<template><Head :title="`${releaseNote.version} · BYL ERP`"/><AuthenticatedLayout :title="releaseNote.title" :breadcrumbs="[{label:'BYL ERP',href:'/dashboard'},{label:'指南',href:'/guides'},{label:'版本更新',href:'/settings/changelog'},releaseNote.version]"><section class="mvp-page"><div class="mvp-page-heading"><div><p class="mvp-kicker">{{releaseNote.version}} · {{releaseNote.released_on}}</p><h2>{{releaseNote.title}}</h2><p>{{releaseNote.summary}}</p></div><Link v-if="canManage" class="mvp-button" :href="`/settings/changelog/${releaseNote.id}/edit`">编辑</Link></div><div class="release-layout"><aside v-if="toc.length" class="mvp-card release-toc"><strong>本页目录</strong><a v-for="item in toc" :key="item.id" :href="`#${item.id}`">{{item.label}}</a></aside><article class="mvp-card release-body" v-html="html"></article></div></section></AuthenticatedLayout></template>
<style scoped>.release-layout{display:grid;grid-template-columns:220px minmax(0,1fr);gap:18px;align-items:start}.release-toc{position:sticky;top:84px;display:flex;flex-direction:column;gap:8px}.release-body:deep(h2){scroll-margin-top:100px}@media(max-width:850px){.release-layout{grid-template-columns:1fr}.release-toc{position:static}}</style>
