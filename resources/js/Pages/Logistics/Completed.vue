<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../Components/Logistics/StatusBar.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'
type Row={order_url:string|null;external_order_number:string|null;erp_order_id:string|null;tracking_number:string|null}
defineProps<{shipments:{data:Row[]};statusBar:{key:string;label:string;url:string;count:number}[]}>()
type ColumnKey = 'order_number' | 'tracking'
const definitions: GridColumnDefinition<ColumnKey>[] = [{ key: 'order_number', label: '订单号' }, { key: 'tracking', label: '追踪号' }]
const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({ namespace: 'grid.logistics.completed.v1', definitions, requiredKeys: ['order_number'] })
</script>
<template><Head title="已完结 · BYL ERP"/><AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP','物流','已完结']"><section class="mvp-page"><StatusBar :statuses="statusBar"/><h2>已完结</h2><div class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" /></tr></thead><tbody><tr v-for="(row,index) in shipments.data" :key="index"><td v-for="column in visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.erp_order_id ?? '—' }}</Link><span v-else>{{ row.erp_order_id ?? '—' }}</span></template><template v-else>{{ row.tracking_number ?? '—' }}</template></td><td>—</td></tr><tr v-if="shipments.data.length === 0"><td :colspan="visibleColumns.length + 2" class="mvp-empty">暂无已完结物流单</td></tr></tbody></table></div></section></AuthenticatedLayout></template>
