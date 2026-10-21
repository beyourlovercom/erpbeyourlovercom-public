<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import PermissionMatrix from '../../../Components/PermissionMatrix.vue'

type PermissionEntry = { key: string; label: string }
type PermissionGroup = { label: string; permissions: PermissionEntry[] }

type UserDetail = {
  id: number
  name: string
  email: string
  is_active: boolean
  permission_mode: string
  custom_permissions: string[]
  role: { key: string; label: string; preset: string | null } | null
}

const props = defineProps<{
  user: UserDetail
  rolePresets: { value: string; label: string }[]
  isSelf: boolean
  permissionCatalog: Record<string, PermissionGroup>
  rolePermissions: string[]
}>()

const profileForm = useForm({ name: props.user.name, email: props.user.email })
const submitProfile = () => profileForm.patch(`/settings/users/${props.user.id}`)

const passwordForm = useForm({ password: '', password_confirmation: '' })
const submitPassword = () => passwordForm.post(`/settings/users/${props.user.id}/password`, {
  onSuccess: () => passwordForm.reset(),
})

const roleForm = useForm({ role: props.user.role?.preset ?? '' })
const submitRole = () => roleForm.post(`/settings/users/${props.user.id}/role`)

const permMode = ref<'role' | 'custom'>(props.user.permission_mode === 'custom' ? 'custom' : 'role')
const customPerms = ref<string[]>(
  props.user.permission_mode === 'custom'
    ? [...props.user.custom_permissions]
    : [...props.user.custom_permissions]
)

watch(permMode, (newMode, oldMode) => {
  if (newMode === 'custom' && oldMode === 'role') {
    customPerms.value = [...props.rolePermissions]
  }
})

const permForm = useForm({
  mode: permMode.value,
  permissions: customPerms.value,
})

watch(permMode, (val) => { permForm.mode = val })
watch(customPerms, (val) => { permForm.permissions = val })

const isCustomMode = computed(() => permMode.value === 'custom')

function setModeRole() {
  permMode.value = 'role'
}

function setModeCustom() {
  permMode.value = 'custom'
  if (customPerms.value.length === 0) {
    customPerms.value = [...props.rolePermissions]
  }
}

function resetToRole() {
  permForm.mode = 'role'
  permForm.permissions = []
  permForm.post(`/settings/users/${props.user.id}/permissions`, {
    onSuccess: () => {
      permMode.value = 'role'
      customPerms.value = []
    },
  })
}

function submitPermissions() {
  permForm.mode = permMode.value
  permForm.permissions = permMode.value === 'custom' ? customPerms.value : []
  permForm.post(`/settings/users/${props.user.id}/permissions`)
}

const effectivePermissions = computed(() =>
  permMode.value === 'role' ? props.rolePermissions : customPerms.value
)
</script>

<template>
  <Head title="编辑用户 · BYL ERP" />
  <AuthenticatedLayout title="编辑用户" :breadcrumbs="['BYL ERP', '设置', '用户', user.name, '编辑']">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">系统设置</p><h2>编辑 {{ user.name }}</h2></div><div class="mvp-form-actions page-header-actions"><a class="mvp-button" href="/settings/users">返回用户列表</a></div></div>

      <form class="mvp-form" @submit.prevent="submitProfile">
        <h3>基本信息</h3>
        <div class="mvp-form-grid">
          <label>姓名<input v-model="profileForm.name" required></label>
          <span v-if="profileForm.errors.name" class="mvp-field-error">{{ profileForm.errors.name }}</span>
          <label>邮箱<input v-model="profileForm.email" type="email" required></label>
          <span v-if="profileForm.errors.email" class="mvp-field-error">{{ profileForm.errors.email }}</span>
        </div>
        <div class="mvp-form-actions"><button class="mvp-button mvp-button-primary" :disabled="profileForm.processing">保存基本信息</button></div>
      </form>

      <form class="mvp-form" @submit.prevent="submitPassword">
        <h3>设置新密码</h3>
        <p>出于安全考虑，系统不会显示或找回原密码，只能设置一个新密码。</p>
        <div class="mvp-form-grid">
          <label>新密码<input v-model="passwordForm.password" type="password" autocomplete="new-password"></label>
          <span v-if="passwordForm.errors.password" class="mvp-field-error">{{ passwordForm.errors.password }}</span>
          <label>确认新密码<input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password"></label>
        </div>
        <div class="mvp-form-actions"><button class="mvp-button mvp-button-primary" :disabled="passwordForm.processing">更新密码</button></div>
      </form>

      <form class="mvp-form" @submit.prevent="submitRole">
        <h3>角色</h3>
        <div class="mvp-form-grid">
          <label>角色
            <select v-model="roleForm.role" required>
              <option value="" disabled>请选择角色</option>
              <option v-for="preset in rolePresets" :key="preset.value" :value="preset.value">{{ preset.label }}</option>
            </select>
          </label>
          <span v-if="roleForm.errors.role" class="mvp-field-error">{{ roleForm.errors.role }}</span>
        </div>
        <div class="mvp-form-actions"><button class="mvp-button mvp-button-primary" :disabled="roleForm.processing">更新角色</button></div>
      </form>

      <div class="mvp-form">
        <h3>权限设置</h3>
        <div class="permission-mode-selector">
          <label class="permission-mode-option">
            <input type="radio" name="perm_mode" value="role" :checked="!isCustomMode" @change="setModeRole" />
            跟随角色默认
          </label>
          <label class="permission-mode-option">
            <input type="radio" name="perm_mode" value="custom" :checked="isCustomMode" @change="setModeCustom" />
            自定义权限
          </label>
        </div>
        <p v-if="!isCustomMode" class="mvp-form-hint">当前使用角色默认权限，以下为只读展示。</p>
        <PermissionMatrix
          v-model="customPerms"
          :catalog="permissionCatalog"
          :disabled="!isCustomMode"
        />
        <span v-if="(permForm.errors as Record<string, string>).user" class="mvp-field-error">{{ (permForm.errors as Record<string, string>).user }}</span>
        <span v-if="permForm.errors.permissions" class="mvp-field-error">{{ permForm.errors.permissions }}</span>
        <div class="mvp-form-actions">
          <button type="button" class="mvp-button mvp-button-primary" :disabled="permForm.processing" @click="submitPermissions">保存权限</button>
          <button type="button" class="mvp-button" :disabled="permForm.processing" @click="resetToRole">恢复角色默认权限</button>
        </div>
      </div>

      <p v-if="isSelf" class="mvp-field-error">提示：管理员不能停用或删除自己的账号，请返回用户列表操作其他账号。</p>
    </section>
  </AuthenticatedLayout>
</template>
