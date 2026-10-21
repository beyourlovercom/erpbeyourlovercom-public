<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Action = { key: string; label: string; available: boolean }
type RestoreState = { available: boolean; status: string }
type BackupHistory = { id: number; runUuid: string; type: string; state: string; source: string; cadence: string | null; createdAt: string | null }
type AutomaticOption = { value: 'daily' | 'weekly' | 'monthly'; label: string; detail: string }
type AutomaticBackup = { enabled: boolean; cadence: AutomaticOption['value']; canManage: boolean; retentionCount: number; options: AutomaticOption[] }

const props = defineProps<{
  actions: Action[]
  history: BackupHistory[]
  storageUsage: { used: number; capacity: number } | null
  policy: { format: string; includedRoots: string[]; workspace: string; status: string }
  automaticBackup: AutomaticBackup
  restore: { local: RestoreState; github: RestoreState }
}>()

const cadence = ref<AutomaticOption['value']>(props.automaticBackup.cadence)
const saving = ref(false)
watch(() => props.automaticBackup.cadence, value => { cadence.value = value })

const selectedOption = computed(() => props.automaticBackup.options.find(option => option.value === cadence.value) ?? props.automaticBackup.options[0])

function saveAutomatic(enabled: boolean) {
  if (!props.automaticBackup.canManage || saving.value) return
  saving.value = true
  router.put('/settings/backup/automatic', { enabled, cadence: cadence.value }, {
    preserveScroll: true,
    onFinish: () => { saving.value = false },
  })
}

function saveCadence() {
  saveAutomatic(props.automaticBackup.enabled)
}

function formatTime(value: string | null) {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}
</script>

<template>
  <Head title="备份与迁移 · BYL ERP" />
  <AuthenticatedLayout title="备份与迁移" :breadcrumbs="['BYL ERP', '设置', '备份与迁移']">
    <section class="mvp-page backup-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">BACKUP &amp; MIGRATION</p>
          <h2>备份与迁移</h2>
          <p>自动备份、备份历史与恢复安全入口。</p>
        </div>
      </div>

      <section class="mvp-panel automatic-panel">
        <div class="section-heading">
          <div>
            <h3>自动备份</h3>
            <p>最多保留 {{ props.automaticBackup.retentionCount }} 份。新备份验证成功后，自动清理最旧的一份。</p>
          </div>
          <span class="mvp-status" :class="{ active: props.automaticBackup.enabled }">{{ props.automaticBackup.enabled ? '已开启' : '已关闭' }}</span>
        </div>
        <div class="automatic-controls">
          <label>
            <span>备份周期</span>
            <select v-model="cadence" :disabled="!props.automaticBackup.canManage || saving" @change="saveCadence">
              <option v-for="option in props.automaticBackup.options" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>
          <div class="schedule-summary">
            <span>执行时间</span>
            <strong>{{ selectedOption?.detail }}</strong>
          </div>
          <button
            type="button"
            class="toggle-button"
            :class="{ danger: props.automaticBackup.enabled }"
            :disabled="!props.automaticBackup.canManage || saving"
            @click="saveAutomatic(!props.automaticBackup.enabled)"
          >
            {{ saving ? '保存中…' : (props.automaticBackup.enabled ? '关闭自动备份' : '开启自动备份') }}
          </button>
        </div>
        <p v-if="!props.automaticBackup.canManage" class="mvp-empty">仅具有“管理备份策略”权限的管理员可以修改。</p>
      </section>

      <section class="mvp-panel">
        <div class="section-heading">
          <div><h3>备份操作</h3><p>自动备份已接入正式引擎；手动备份入口仍保持关闭。</p></div>
          <span class="mvp-status">手动入口暂不可用</span>
        </div>
        <div class="action-grid"><button v-for="action in props.actions" :key="action.key" type="button" disabled>{{ action.label }}</button></div>
      </section>

      <div class="two-column">
        <section class="mvp-panel history-panel">
          <h3>最近备份</h3>
          <p v-if="props.history.length === 0" class="mvp-empty">暂无真实备份记录。</p>
          <div v-else class="history-list">
            <div v-for="item in props.history" :key="item.id" class="history-row">
              <div><strong>{{ item.source }}</strong><span>{{ item.type }} · {{ item.state }}</span></div>
              <time>{{ formatTime(item.createdAt) }}</time>
            </div>
          </div>
        </section>
        <section class="mvp-panel">
          <h3>存储使用情况</h3>
          <p v-if="props.storageUsage === null" class="mvp-empty">当前不展示容量估算，实际备份资产由工作区与 GitHub Release 管理。</p>
          <p v-else>{{ props.storageUsage.used }} / {{ props.storageUsage.capacity }}</p>
        </section>
      </div>

      <section class="mvp-panel">
        <h3>备份策略</h3>
        <dl class="policy-list">
          <div><dt>格式</dt><dd>{{ props.policy.format }}</dd></div>
          <div><dt>包含目录</dt><dd>{{ props.policy.includedRoots.join('、') }}</dd></div>
          <div><dt>备份工作区</dt><dd>{{ props.policy.workspace }}</dd></div>
          <div><dt>状态</dt><dd>{{ props.policy.status }}</dd></div>
        </dl>
      </section>

      <div class="two-column">
        <section class="mvp-panel"><h3>本地恢复</h3><p>{{ props.restore.local.status }}</p><button type="button" disabled>选择备份文件</button></section>
        <section class="mvp-panel"><h3>GitHub 恢复</h3><p>{{ props.restore.github.status }}</p><button type="button" disabled>从 GitHub 选择备份</button></section>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.backup-page{display:grid;gap:var(--byl-space-20)}.mvp-panel{display:grid;gap:var(--byl-space-12);padding:var(--byl-space-20);border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg);background:var(--byl-surface)}.mvp-panel h3,.mvp-panel p{margin:0}.section-heading{display:flex;justify-content:space-between;gap:var(--byl-space-12);align-items:start}.section-heading p,.mvp-empty{color:var(--byl-text-muted)}.mvp-status{white-space:nowrap}.mvp-status.active{font-weight:700}.automatic-controls{display:grid;grid-template-columns:minmax(180px,1fr) minmax(180px,1fr) auto;gap:var(--byl-space-12);align-items:end}.automatic-controls label,.schedule-summary{display:grid;gap:var(--byl-space-4)}.automatic-controls label>span,.schedule-summary>span{font-size:12px;color:var(--byl-text-muted)}select,.toggle-button{min-height:42px;padding:0 var(--byl-space-12);border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-md);background:var(--byl-surface);color:var(--byl-text)}.toggle-button{font-weight:700;cursor:pointer}.toggle-button:disabled{cursor:not-allowed;opacity:.55}.toggle-button.danger{background:var(--byl-readonly-bg)}.action-grid,.two-column{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--byl-space-12)}.two-column{grid-template-columns:repeat(2,minmax(0,1fr))}.action-grid button,.mvp-panel>button{min-height:42px;padding:0 var(--byl-space-12);border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-md);background:var(--byl-readonly-bg);color:var(--byl-text-muted);cursor:not-allowed}.history-list{display:grid}.history-row{display:flex;justify-content:space-between;gap:var(--byl-space-12);padding:var(--byl-space-12) 0;border-top:1px solid var(--byl-border-subtle)}.history-row:first-child{border-top:0}.history-row div{display:grid;gap:2px}.history-row span,.history-row time{font-size:12px;color:var(--byl-text-muted)}.policy-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--byl-space-12);margin:0}.policy-list div{display:grid;gap:var(--byl-space-4)}dt{color:var(--byl-text-muted)}dd{margin:0;font-weight:600;overflow-wrap:anywhere}@media(max-width:800px){.automatic-controls,.action-grid,.two-column,.policy-list{grid-template-columns:1fr}}
</style>
