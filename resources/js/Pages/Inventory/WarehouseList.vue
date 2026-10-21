<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
type W={slug:string;public_slug:string;code:string;name:string;name_en:string|null;country_code:string|null;address:string;role_label:string;is_fulfillment_warehouse:boolean;ownership_type:'owned'|'external';is_active:boolean;priority:number;product_count:number|null;quantity_on_hand:number|null;inventory_state:'confirmed'|'partial_only'|'manual'|'never_imported';inventory_state_label:string;last_inventory_at:string|null;fulfillment_available_on:string|null;fulfillment_state:'preparing'|'awaiting_inventory'|'ready'|'not_ready';fulfillment_state_label:string}
defineProps<{warehouses:W[];canManage:boolean}>()
const breadcrumbs=[{label:'BYL ERP',href:'/dashboard'},{label:'库存',href:'/inventory'},'仓库清单']
const formatDate=(value:string|null)=>value?new Intl.DateTimeFormat('zh-CN',{dateStyle:'medium'}).format(new Date(value)):'—'
</script>
<template><Head title="仓库清单 · BYL ERP"/><AuthenticatedLayout title="仓库清单" :breadcrumbs="breadcrumbs"><section class="mvp-page">
<div class="mvp-page-heading"><div><p class="mvp-kicker">Warehouse Master</p><h2>仓库清单</h2></div><Link v-if="canManage" class="mvp-button mvp-button-primary" href="/inventory/add">增加仓库</Link></div>
<div class="mvp-card table-wrap"><table><thead><tr><th>仓库</th><th>代码</th><th>用途</th><th>国家</th><th>地址</th><th>产品数量</th><th>总库存</th><th>库存数据</th><th>最近更新</th><th>归属</th><th>优先级</th><th>履约状态</th><th>预计可发货</th><th>状态</th></tr></thead><tbody>
<tr v-for="w in warehouses" :key="w.code"><td><Link :href="`/inventory/${w.public_slug||w.slug}`"><strong>{{w.name}}</strong></Link><small class="warehouse-en">{{w.name_en||w.code}}</small></td><td>{{w.code}}</td><td>{{w.role_label}}</td><td>{{w.country_code||'—'}}</td><td>{{w.address||'未填写'}}</td><td>{{w.product_count ?? '—'}}</td><td>{{w.quantity_on_hand ?? '—'}}</td><td>{{w.inventory_state_label}}</td><td>{{formatDate(w.last_inventory_at)}}</td><td>{{w.ownership_type==='external'?'外部仓':'自有仓'}}</td><td>{{w.priority}}</td><td>{{w.fulfillment_state_label}}</td><td>{{w.fulfillment_available_on||'—'}}</td><td>{{w.is_active?'启用':'停用'}}</td></tr>
</tbody></table></div></section></AuthenticatedLayout></template>
<style scoped>.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:nowrap}th{font-size:12px;color:#64748b}.warehouse-en{display:block;margin-top:3px;color:#64748b;font-size:11px}</style>
