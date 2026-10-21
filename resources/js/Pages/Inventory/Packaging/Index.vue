<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type RecordRow={id:number;status:string;product:{sku:string|null;name:string};warehouse:string|null;operator:string|null;measured_at:string|null;dimensions:string;packaged_weight_kg:string|null;media_count:number}
const props=defineProps<{records:{data:RecordRow[];links:any[]};filters:Record<string,string>;canReview:boolean;canSubmit:boolean}>()
const labels:Record<string,string>={draft:'草稿',submitted:'待审核',approved:'已批准',rejected:'已驳回'}
const search=ref(props.filters?.search??''); const status=ref(props.filters?.status??'')
function filter(){router.get('/inventory/packaging',{search:search.value||undefined,status:status.value||undefined},{preserveState:true,replace:true})}
</script>
<template><Head title="包装与测量 · BYL ERP"/><AuthenticatedLayout title="包装与测量" :breadcrumbs="[{label:'BYL ERP',href:'/dashboard'},{label:'库存',href:'/inventory'},'包装与测量']"><section class="mvp-page">
<div class="mvp-page-heading"><div><p class="mvp-kicker">Inventory Evidence</p><h2>包装与测量</h2><p>记录工厂包装、尺寸和称重证据；不会改写产品主数据。</p></div><div class="mvp-actions"><Link v-if="canSubmit" class="mvp-button mvp-button-primary" href="/inventory/packaging/create">新建记录</Link></div></div>
<div class="mvp-card filters"><input v-model="search" placeholder="搜索 SKU / 产品名称" @keyup.enter="filter"/><select v-model="status" @change="filter"><option value="">全部状态</option><option v-for="(label,key) in labels" :key="key" :value="key">{{label}}</option></select><button class="mvp-button" @click="filter">筛选</button></div>
<div class="mvp-card table-wrap"><table><thead><tr><th>产品 / SKU</th><th>地点</th><th>操作员</th><th>尺寸</th><th>重量</th><th>测量时间</th><th>状态</th><th>证据</th><th></th></tr></thead><tbody><tr v-for="row in records.data" :key="row.id"><td><strong>{{row.product.name}}</strong><small>{{row.product.sku||'无 SKU'}}</small></td><td>{{row.warehouse||'未选择'}}</td><td>{{row.operator||'—'}}</td><td>{{row.dimensions}}</td><td>{{row.packaged_weight_kg||'—'}} kg</td><td>{{row.measured_at?new Date(row.measured_at).toLocaleString('zh-CN'):'—'}}</td><td><span class="status">{{labels[row.status]}}</span></td><td>{{row.media_count}} 张</td><td><Link :href="`/inventory/packaging/${row.id}`">查看</Link></td></tr><tr v-if="!records.data.length"><td colspan="9">暂无记录</td></tr></tbody></table></div>
</section></AuthenticatedLayout></template>
<style scoped>.filters{display:flex;gap:10px;flex-wrap:wrap}.filters input{min-width:260px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:nowrap}td small{display:block;color:#64748b}.status{padding:4px 8px;border-radius:999px;background:#ecfeff;color:#155e75;font-size:12px}</style>
