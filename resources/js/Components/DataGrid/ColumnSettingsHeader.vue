<script setup lang="ts">
import { ref } from 'vue'

type Column = { key: string; label: string; visible: boolean }

withDefaults(defineProps<{
  columns: Column[]
  requiredKeys?: string[]
  syncState?: 'idle' | 'saving' | 'saved' | 'error'
}>(), { requiredKeys: () => [], syncState: 'idle' })

const emit = defineEmits<{
  toggle: [key: string]
  startDrag: [key: string]
  drop: [key: string]
  reset: []
}>()

const settingsOpen = ref(false)
</script>

<template>
  <th class="column-settings-header">
    <button class="column-settings-trigger" type="button" aria-label="列设置" title="设置显示列和列顺序" @click="settingsOpen = !settingsOpen">…</button>
    <div v-if="settingsOpen" class="column-settings-panel" @click.stop>
      <div class="column-settings-heading"><strong>列设置</strong><button type="button" class="column-reset-btn" @click="emit('reset')">恢复默认</button></div>
      <p v-if="syncState !== 'idle'" class="column-sync-state" :data-state="syncState">{{ syncState === 'saving' ? '正在同步…' : syncState === 'error' ? '同步失败，已保留本地缓存' : '已同步到账号' }}</p>
      <label v-for="column in columns" :key="column.key" class="column-setting-row" draggable="true" @dragstart="emit('startDrag', column.key)" @dragover.prevent @drop="emit('drop', column.key)">
        <span class="column-drag-handle" aria-hidden="true">⋮⋮</span>
        <input type="checkbox" :checked="column.visible" :disabled="requiredKeys.includes(column.key)" :aria-label="requiredKeys.includes(column.key) ? `${column.label.replace('ERP','')}固定显示` : `${column.label}显示`" @change="emit('toggle', column.key)">
        <span>{{ column.label }}<small v-if="requiredKeys.includes(column.key)" class="column-required">固定显示</small></span>
      </label>
    </div>
  </th>
</template>

<style scoped>
.column-settings-header { position: relative; width: 46px; min-width: 46px; text-align: right; }
.column-settings-trigger { border: 0; background: transparent; color: inherit; cursor: pointer; font-size: 20px; line-height: 1; padding: 4px 8px; }
.column-settings-panel { position: absolute; z-index: 20; top: calc(100% + 6px); right: 0; width: 260px; padding: 12px; border: 1px solid var(--byl-color-border,#d9e1e5); border-radius: 9px; background: var(--byl-color-surface,#fff); color: var(--byl-color-ink,#142126); box-shadow: 0 8px 24px rgb(20 33 38 / 14%); text-align: left; }
.column-settings-heading { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.column-reset-btn { border: 0; background: transparent; color: inherit; cursor: pointer; font-size: 12px; }
.column-sync-state { margin: 8px 0; font-size: 12px; line-height: 1.45; opacity: .72; }
.column-setting-row { display: flex; align-items: center; gap: 7px; min-height: 36px; cursor: grab; font-size: 13px; }
.column-setting-row input:disabled { cursor: not-allowed; }
.column-drag-handle { opacity: .5; letter-spacing: -2px; }
.column-required { margin-left: 6px; font-size: 11px; opacity: .6; }
</style>
