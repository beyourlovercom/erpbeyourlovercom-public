import axios from 'axios'
import { computed, onMounted, ref, type Ref } from 'vue'
export type GridColumnDefinition<K extends string> = { key: K; label: string; defaultVisible?: boolean }
export type GridPreferenceColumn<K extends string> = { key: K; visible: boolean }
export type GridPreferenceValue<K extends string> = { columns: GridPreferenceColumn<K>[] }
type GridRuntimeColumn<K extends string> = GridColumnDefinition<K> & { visible: boolean }
type Options<K extends string> = { namespace: string; definitions: GridColumnDefinition<K>[]; requiredKeys?: K[]; serverValue?: GridPreferenceValue<K> | null; legacyCacheKeys?: string[] }
const storageAvailable = () => typeof window !== 'undefined' && typeof window.localStorage !== 'undefined'
const normalizeStored = <K extends string>(raw: unknown): GridPreferenceValue<K> | null => {
    if (Array.isArray(raw)) return { columns: raw as GridPreferenceColumn<K>[] }
    if (!raw || typeof raw !== 'object') return null
    const columns = (raw as { columns?: unknown }).columns
    return Array.isArray(columns) ? { columns: columns as GridPreferenceColumn<K>[] } : null
}
export function useAccountGridPreferences<K extends string>(options: Options<K>) {
    const cacheKey = `ui-pref-cache:${options.namespace}`
    const definitions = new Map(options.definitions.map(definition => [definition.key, definition]))
    const requiredKeys = new Set(options.requiredKeys ?? [])
    const sanitize = (value: GridPreferenceValue<K> | null): GridRuntimeColumn<K>[] => {
        const restored: GridRuntimeColumn<K>[] = []
        for (const item of value?.columns ?? []) {
            const definition = definitions.get(item.key)
            if (!definition || restored.some(column => column.key === item.key)) continue
            restored.push({ ...definition, visible: requiredKeys.has(item.key) || item.visible !== false })
        }
        for (const definition of options.definitions) {
            if (!restored.some(column => column.key === definition.key)) restored.push({ ...definition, visible: requiredKeys.has(definition.key) || definition.defaultVisible !== false })
        }
        return restored
    }
    let initialValue = options.serverValue ?? null
    let shouldMigrateLocal = false
    if (options.serverValue == null && storageAvailable()) {
        for (const key of [cacheKey, ...(options.legacyCacheKeys ?? [])]) {
            const raw = window.localStorage.getItem(key)
            if (!raw) continue
            try {
                initialValue = normalizeStored<K>(JSON.parse(raw)); shouldMigrateLocal = initialValue !== null
                if (initialValue) break
            } catch { /* ignore malformed cache */ }
        }
    }
    const columns = ref(sanitize(initialValue)) as Ref<GridRuntimeColumn<K>[]>
    const visibleColumns = computed(() => columns.value.filter(column => column.visible))
    const syncState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle')
    const draggedKey = ref<K | null>(null)
    const value = (): GridPreferenceValue<K> => ({ columns: columns.value.map(({ key, visible }) => ({ key, visible })) })
    const cache = () => { if (storageAvailable()) window.localStorage.setItem(cacheKey, JSON.stringify(value())) }
    const sync = async () => {
        cache(); syncState.value = 'saving'
        try { await axios.put(`/ui/preferences/${encodeURIComponent(options.namespace)}`, { value: value() }); syncState.value = 'saved' }
        catch { syncState.value = 'error' }
    }
    if (options.serverValue != null) cache()
    onMounted(async () => {
        if (options.serverValue != null) return
        try {
            const response = await axios.get(`/ui/preferences/${encodeURIComponent(options.namespace)}`)
            const serverValue = normalizeStored<K>(response.data?.value)
            if (serverValue) {
                columns.value = sanitize(serverValue)
                cache()
                return
            }
        } catch {
            // A cached preference remains usable when the account endpoint is unavailable.
        }
        if (shouldMigrateLocal) void sync()
    })
    const toggle = (key: string) => { const typedKey = key as K; if (!definitions.has(typedKey) || requiredKeys.has(typedKey)) return; const column = columns.value.find(item => item.key === typedKey); if (!column) return; column.visible = !column.visible; void sync() }
    const startDrag = (key: string) => { const typedKey = key as K; if (!definitions.has(typedKey)) return; draggedKey.value = typedKey }
    const drop = (targetKey: string) => {
        const typedTargetKey = targetKey as K; if (!definitions.has(typedTargetKey)) return
        const sourceKey = draggedKey.value; draggedKey.value = null
        if (!sourceKey || sourceKey === typedTargetKey) return
        const next = [...columns.value]; const sourceIndex = next.findIndex(column => column.key === sourceKey); const targetIndex = next.findIndex(column => column.key === typedTargetKey)
        if (sourceIndex < 0 || targetIndex < 0) return
        const [moved] = next.splice(sourceIndex, 1); next.splice(targetIndex, 0, moved); columns.value = next; void sync()
    }
    const reset = () => { columns.value = sanitize(null); void sync() }
    return { columns, visibleColumns, syncState, toggle, startDrag, drop, reset }
}
