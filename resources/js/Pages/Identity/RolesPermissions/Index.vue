<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import PermissionMatrix from '../../../Components/PermissionMatrix.vue'

type PermissionEntry = { key: string; label: string }
type PermissionGroup = { label: string; permissions: PermissionEntry[] }
type PresetData = { preset: string; label: string; permissions: string[] }

const props = defineProps<{
  presets: PresetData[]
  permissionCatalog: Record<string, PermissionGroup>
}>()

const activePreset = ref<string>(props.presets[0]?.preset ?? '')

const currentPreset = computed(() =>
  props.presets.find((p) => p.preset === activePreset.value) ?? null
)

const permForms = Object.fromEntries(
  props.presets.map((p) => [
    p.preset,
    useForm({ permissions: [...p.permissions] }),
  ])
)

function selectPreset(preset: string) {
  activePreset.value = preset
}

function submitPreset() {
  const form = permForms[activePreset.value]
  if (!form) return
  form.put(`/settings/roles-permissions/${activePreset.value}`)
}
</script>

<template>
  <Head title="角色与权限 · BYL ERP" />
  <AuthenticatedLayout title="角色与权限" :breadcrumbs="['BYL ERP', '设置', '角色与权限']">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">系统设置</p><h2>角色与权限</h2></div>
        <div class="mvp-form-actions page-header-actions">
          <button
            v-if="currentPreset"
            type="button"
            class="mvp-button mvp-button-primary"
            :disabled="permForms[currentPreset.preset].processing"
            @click="submitPreset"
          >
            保存权限
          </button>
        </div>
      </div>
      <p class="mvp-form-hint">在此编辑各角色预设的默认权限模板。调整后，跟随角色默认的用户将立即生效；自定义权限的用户不受影响。</p>

      <div class="roles-tab-bar">
        <button
          v-for="p in presets"
          :key="p.preset"
          type="button"
          class="mvp-button"
          :class="{ 'mvp-button-primary': p.preset === activePreset }"
          @click="selectPreset(p.preset)"
        >
          {{ p.label }}
        </button>
      </div>

      <div v-if="currentPreset" class="mvp-form">
        <h3>{{ currentPreset.label }} 权限设置</h3>
        <PermissionMatrix
          v-model="permForms[currentPreset.preset].permissions"
          :catalog="permissionCatalog"
          :disabled="false"
        />
        <span v-if="permForms[currentPreset.preset].errors.permissions" class="mvp-field-error">
          {{ permForms[currentPreset.preset].errors.permissions }}
        </span>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
