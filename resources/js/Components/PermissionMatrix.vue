<script setup lang="ts">
import { computed, ref, watch } from 'vue'

type PermissionEntry = { key: string; label: string }
type PermissionGroup = { label: string; permissions: PermissionEntry[] }

const props = defineProps<{
  modelValue: string[]
  catalog: Record<string, PermissionGroup>
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: string[]): void
}>()

const selected = ref<Set<string>>(new Set(props.modelValue))

watch(() => props.modelValue, (val) => {
  selected.value = new Set(val)
})

function isChecked(key: string): boolean {
  return selected.value.has(key)
}

function toggle(key: string): void {
  if (props.disabled) return
  const next = new Set(selected.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  selected.value = next
  emit('update:modelValue', [...next])
}

function selectAll(): void {
  if (props.disabled) return
  const all: string[] = []
  for (const group of Object.values(props.catalog)) {
    for (const p of group.permissions) all.push(p.key)
  }
  selected.value = new Set(all)
  emit('update:modelValue', all)
}

function clearAll(): void {
  if (props.disabled) return
  selected.value = new Set()
  emit('update:modelValue', [])
}

const groups = computed(() => Object.entries(props.catalog).map(([, group]) => group))
</script>

<template>
  <div class="permission-matrix">
    <div v-for="group in groups" :key="group.label" class="permission-group">
      <h4 class="permission-group-label">{{ group.label }}</h4>
      <div class="permission-group-items">
        <label
          v-for="perm in group.permissions"
          :key="perm.key"
          class="permission-item"
          :class="{ 'permission-item-disabled': disabled }"
        >
          <input
            type="checkbox"
            :checked="isChecked(perm.key)"
            :disabled="disabled"
            @change="toggle(perm.key)"
          />
          <span>{{ perm.label }}</span>
        </label>
      </div>
    </div>
    <div v-if="!disabled" class="permission-matrix-actions">
      <button type="button" class="mvp-button" @click="selectAll">全选</button>
      <button type="button" class="mvp-button" @click="clearAll">取消全选</button>
    </div>
  </div>
</template>
