<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Media = { id:number; kind:string; name:string|null; url:string }
type ProductOption = { id:number; sku:string|null; name:string }
type SopStep = { position:number; title:string; instruction:string; image_url?:string|null }
type PackagingSop = {
    id:number; title:string; method:string; preset_key?:string|null; scenario?:string|null; version?:string|null;
    default_illustration_url?:string|null; rendered_illustration_url?:string|null; rendered_illustration_name?:string|null;
    materials:string[]; steps:SopStep[]; tips:string[]; examples:any[]
}
type Preset = { key:string; label:string; summary:string; materials:string[]; default_image_url:string; steps:SopStep[]; tips:string[] }
type Rec = {
    id:number; status:string; packaging_method_key?:string|null;
    product:{id:number;sku:string|null;name:string}; warehouse:string|null; warehouse_id:number|null;
    packaging_length_cm?:string; packaging_width_cm?:string; packaging_height_cm?:string; packaged_weight_kg?:string;
    measured_at?:string|null; note?:string; media:Media[]
}

const props = defineProps<{record:Rec|null; warehouses:{id:number;name:string}[]; packagingPresets:Preset[]; packagingSop:PackagingSop|null}>()
const initialMethod = props.record?.packaging_method_key || props.packagingSop?.preset_key || props.packagingPresets[0]?.key || null
const form = useForm({
    product_id: props.record?.product.id ?? null as number|null,
    warehouse_id: props.record?.warehouse_id ?? null as number|null,
    packaging_method_key: initialMethod as string|null,
    packaging_length_cm: props.record?.packaging_length_cm ?? '',
    packaging_width_cm: props.record?.packaging_width_cm ?? '',
    packaging_height_cm: props.record?.packaging_height_cm ?? '',
    packaged_weight_kg: props.record?.packaged_weight_kg ?? '',
    measured_at: props.record?.measured_at?.slice(0,16) ?? '',
    note: props.record?.note ?? '',
})

const search = ref(props.record ? `${props.record.product.sku || '无 SKU'} · ${props.record.product.name}` : '')
const results = ref<ProductOption[]>([])
const productSop = ref<PackagingSop|null>(props.packagingSop)
const initialHasRenderedGuide = !!props.packagingSop?.rendered_illustration_url && (initialMethod === 'custom' ? !props.packagingSop?.preset_key : (!props.packagingSop?.preset_key || props.packagingSop.preset_key === initialMethod))
const guideMode = ref<'default'|'rendered'>(initialHasRenderedGuide ? 'rendered' : 'default')
const showSteps = ref(false)
const guideLoading = ref(false)

const selectedPreset = computed(() => props.packagingPresets.find(item => item.key === form.packaging_method_key) ?? null)
const isCustom = computed(() => form.packaging_method_key === 'custom')
const sopMatchesPreset = computed(() => !!productSop.value?.preset_key && productSop.value.preset_key === form.packaging_method_key)
const customSopAvailable = computed(() => isCustom.value && !!productSop.value && !productSop.value.preset_key)
const hasRenderedGuide = computed(() => !!productSop.value?.rendered_illustration_url && (customSopAvailable.value || sopMatchesPreset.value))
const currentGuideImage = computed(() => {
    if (guideMode.value === 'rendered' && hasRenderedGuide.value) return productSop.value?.rendered_illustration_url || ''
    if (isCustom.value) return customSopAvailable.value ? (productSop.value?.default_illustration_url || productSop.value?.rendered_illustration_url || '') : ''
    if (sopMatchesPreset.value && productSop.value?.default_illustration_url) return productSop.value.default_illustration_url
    return selectedPreset.value?.default_image_url || ''
})
const currentSteps = computed<SopStep[]>(() => isCustom.value ? (customSopAvailable.value ? (productSop.value?.steps ?? []) : []) : (selectedPreset.value?.steps ?? []))
const currentTips = computed<string[]>(() => isCustom.value ? (customSopAvailable.value ? (productSop.value?.tips ?? []) : []) : (selectedPreset.value?.tips ?? []))
const selectedMethodLabel = computed(() => isCustom.value ? (customSopAvailable.value ? (productSop.value?.method || '定制包装方案') : '定制包装方案') : (selectedPreset.value?.label || '未选择'))
const dimensionSummary = computed(() => {
    const values = [form.packaging_length_cm, form.packaging_width_cm, form.packaging_height_cm]
    return values.every(Boolean) ? `${values.join(' × ')} cm` : '待填写'
})

async function find() {
    const response = await fetch(`/inventory/product-search?q=${encodeURIComponent(search.value)}`)
    results.value = response.ok ? await response.json() : []
}

async function chooseProduct(item:ProductOption) {
    form.product_id = item.id
    search.value = `${item.sku || '无 SKU'} · ${item.name}`
    results.value = []
    guideLoading.value = true
    try {
        const response = await fetch(`/inventory/packaging/product/${item.id}/guide`)
        const payload = response.ok ? await response.json() : null
        productSop.value = payload?.packaging_sop ?? null
        form.packaging_method_key = productSop.value?.preset_key || props.packagingPresets[0]?.key || null
        guideMode.value = productSop.value?.rendered_illustration_url ? 'rendered' : 'default'
    } finally {
        guideLoading.value = false
    }
}

function choosePreset(key:string) {
    form.packaging_method_key = key
    showSteps.value = false
    guideMode.value = productSop.value?.preset_key === key && productSop.value?.rendered_illustration_url ? 'rendered' : 'default'
}

function chooseCustom() {
    form.packaging_method_key = 'custom'
    guideMode.value = productSop.value?.rendered_illustration_url ? 'rendered' : 'default'
    showSteps.value = true
}

function save() {
    const url = props.record ? `/inventory/packaging/${props.record.id}` : '/inventory/packaging'
    props.record ? form.patch(url, {preserveScroll:true}) : form.post(url)
}

function submit() {
    if (props.record) form.post(`/inventory/packaging/${props.record.id}/submit`, {preserveScroll:true})
}

function upload(kind:string, event:Event) {
    if (!props.record) return
    const input = event.target as HTMLInputElement
    const files = input.files
    if (!files?.length) return
    const data = new FormData()
    data.append('kind', kind)
    Array.from(files).forEach(file => data.append('files[]', file))
    router.post(`/inventory/packaging/${props.record.id}/media`, data, {forceFormData:true, preserveScroll:true, onFinish:()=>{input.value=''}})
}

function removeMedia(media:Media) {
    if (!confirm(`删除 ${media.name || '这张图片'}？`)) return
    router.delete(`/inventory/packaging/media/${media.id}`, {preserveScroll:true})
}

function mediaFor(kind:string):Media[] {
    return (props.record?.media ?? []).filter(item => item.kind === kind)
}
</script>

<template>
    <Head :title="record ? '编辑包装测量草稿' : '新建包装测量'" />
    <AuthenticatedLayout title="包装与测量" :breadcrumbs="[{label:'库存',href:'/inventory'},{label:'包装与测量',href:'/inventory/packaging'},record?'编辑草稿':'新建记录']">
        <section class="form-page">
            <div class="mvp-page-heading page-heading">
                <div>
                    <p class="mvp-kicker">Factory Workspace</p>
                    <h2>{{record ? '编辑草稿' : '新建包装测量记录'}}</h2>
                    <p class="page-purpose">每单只需完成尺寸测量与称重，并上传两类对应照片；包装方式仅供操作参考。</p>
                </div>
                <div class="mvp-actions action-zone">
                    <Link class="mvp-button" href="/inventory/packaging">返回列表</Link>
                    <button class="mvp-button" :disabled="form.processing" @click="save">{{form.processing ? '保存中…' : '保存草稿'}}</button>
                    <button v-if="record" class="mvp-button mvp-button-primary" :disabled="form.processing" @click="submit">提交审核</button>
                </div>
            </div>

            <section class="mvp-card section-card">
                <div class="section-title"><span>1</span><div><h3>产品与工厂</h3><p>先确认本次测量对应的产品与操作地点。</p></div></div>
                <div class="identity-grid">
                    <div class="field-block product-field">
                        <label>产品 SKU / 名称 <em>*</em></label>
                        <div class="search-control">
                            <input v-model="search" placeholder="输入 SKU 或产品名称搜索" @keyup.enter="find" />
                            <button type="button" @click="find" aria-label="搜索产品">⌕</button>
                        </div>
                        <div v-if="results.length" class="search-results">
                            <button v-for="item in results" :key="item.id" type="button" @click="chooseProduct(item)">{{item.sku || '无 SKU'}} · {{item.name}}</button>
                        </div>
                        <small v-if="form.errors.product_id" class="field-error">{{form.errors.product_id}}</small>
                    </div>
                    <div class="field-block">
                        <label>工厂 / 仓库 <em>*</em></label>
                        <select v-model="form.warehouse_id">
                            <option :value="null">请选择工厂 / 仓库</option>
                            <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{warehouse.name}}</option>
                        </select>
                    </div>
                </div>
            </section>

            <section class="evidence-entry-grid primary-evidence-grid">
                <article class="mvp-card evidence-entry-card">
                    <div class="section-title small"><span>2</span><div><h3>包装尺寸 + 测量图</h3><p>填写打包后的长宽高，并拍摄尺寸测量照片。</p></div></div>
                    <div class="dimension-inputs">
                        <label><span>长度 <em>*</em></span><div class="unit-input"><input v-model="form.packaging_length_cm" type="number" step="0.01" min="0"/><b>cm</b></div></label>
                        <label><span>宽度 <em>*</em></span><div class="unit-input"><input v-model="form.packaging_width_cm" type="number" step="0.01" min="0"/><b>cm</b></div></label>
                        <label><span>高度 <em>*</em></span><div class="unit-input"><input v-model="form.packaging_height_cm" type="number" step="0.01" min="0"/><b>cm</b></div></label>
                    </div>
                    <div class="metric-summary">包装尺寸：<strong>{{dimensionSummary}}</strong></div>
                    <div class="upload-title"><strong>尺寸测量图 <em>*</em></strong><small>建议卷尺 / 尺子与包装同时入镜</small></div>
                    <label class="dropzone" :class="{disabled:!record}"><input type="file" accept="image/jpeg,image/png,image/webp" multiple capture="environment" :disabled="!record" @change="upload('measurement',$event)"/><span class="upload-icon">⇧</span><strong>{{record?'拍照 / 上传尺寸图':'先保存草稿后拍照'}}</strong><small>JPG / PNG / WEBP · 单张 ≤ 10MB</small></label>
                    <div class="thumb-grid"><figure v-for="media in mediaFor('measurement')" :key="media.id"><img :src="media.url" :alt="media.name || '尺寸测量图'"/><button type="button" @click="removeMedia(media)">×</button></figure></div>
                    <small v-if="form.errors.packaging_length_cm || form.errors.packaging_width_cm || form.errors.packaging_height_cm" class="field-error">请完整填写包装长、宽、高。</small>
                </article>

                <article class="mvp-card evidence-entry-card">
                    <div class="section-title small"><span>3</span><div><h3>包装重量 + 称重图</h3><p>填写打包后重量，并拍摄电子秤读数。</p></div></div>
                    <div class="weight-fields">
                        <label><span>打包后重量 <em>*</em></span><div class="unit-input"><input v-model="form.packaged_weight_kg" type="number" step="0.001" min="0"/><b>kg</b></div></label>
                        <label><span>测量时间 <em>*</em></span><input v-model="form.measured_at" type="datetime-local"/></label>
                    </div>
                    <div class="upload-title"><strong>称重记录图 <em>*</em></strong><small>请确保电子秤数字清晰可见</small></div>
                    <label class="dropzone" :class="{disabled:!record}"><input type="file" accept="image/jpeg,image/png,image/webp" multiple capture="environment" :disabled="!record" @change="upload('weight',$event)"/><span class="upload-icon">⇧</span><strong>{{record?'拍照 / 上传称重图':'先保存草稿后拍照'}}</strong><small>JPG / PNG / WEBP · 单张 ≤ 10MB</small></label>
                    <div class="thumb-grid"><figure v-for="media in mediaFor('weight')" :key="media.id"><img :src="media.url" :alt="media.name || '称重记录图'"/><button type="button" @click="removeMedia(media)">×</button></figure></div>
                    <small v-if="form.errors.packaged_weight_kg || form.errors.measured_at" class="field-error">请填写重量与测量时间。</small>
                </article>
            </section>

            <section class="mvp-card section-card note-section">
                <div class="section-title small"><span>4</span><div><h3>备注</h3><p>仅在有异常或特殊情况时补充说明。</p></div></div>
                <textarea v-model="form.note" rows="4" maxlength="5000" placeholder="请输入备注信息（选填）"></textarea>
            </section>

            <section class="mvp-card section-card reference-section">
                <div class="section-title"><span>5</span><div><h3>包装方式参考</h3><p>这是工厂打包参考，不影响本单尺寸 / 重量证据提交。</p></div></div>
                <div class="reference-banner"><strong>参考信息</strong><span>优先完成上方两次拍照；需要确认包装方式时再查看这里。</span></div>
                <div class="preset-heading reference-heading">
                    <div><h4>预设包装方案</h4><p>选择后显示对应示意图；该选择不是提交必填项。</p></div>
                    <button class="custom-link" type="button" @click="chooseCustom">没有合适预设？查看定制包装方案 →</button>
                </div>
                <div class="preset-grid">
                    <button v-for="preset in packagingPresets" :key="preset.key" type="button" class="preset-card" :class="{active:form.packaging_method_key===preset.key}" @click="choosePreset(preset.key)">
                        <span v-if="form.packaging_method_key===preset.key" class="preset-check">✓</span>
                        <img :src="preset.default_image_url" :alt="`${preset.label}默认示意图`" />
                        <strong>{{preset.label}}</strong>
                        <small>{{preset.summary}}</small>
                    </button>
                </div>
                <div v-if="isCustom" class="custom-notice">
                    <strong>定制包装参考</strong>
                    <span v-if="customSopAvailable">当前产品已有定制分步骤方案：{{productSop?.method}}</span>
                    <span v-else>当前产品暂未配置定制步骤，可继续提交本单测量证据。</span>
                </div>

                <div class="reference-guide">
                    <div class="section-title guide-title"><div><h3>包装示意图 / 包装教程</h3><p>可使用标准默认图，也可查看该产品专属渲染大图。</p></div></div>
                    <div v-if="guideLoading" class="guide-empty">正在读取产品包装方案…</div>
                    <template v-else>
                        <div class="guide-toolbar">
                            <div class="guide-tabs">
                                <button type="button" :class="{active:guideMode==='default'}" @click="guideMode='default'">默认示意图</button>
                                <button type="button" :disabled="!hasRenderedGuide" :class="{active:guideMode==='rendered'}" @click="hasRenderedGuide && (guideMode='rendered')">产品专属渲染图</button>
                            </div>
                            <div class="guide-meta"><span>参考方案：<b>{{selectedMethodLabel}}</b></span><span v-if="guideMode==='rendered' && hasRenderedGuide">来源：产品专属渲染图</span><span v-else>来源：标准默认示意图</span></div>
                        </div>
                        <div v-if="currentGuideImage" class="guide-hero"><img :src="currentGuideImage" :alt="`${selectedMethodLabel}包装示意图`" /></div>
                        <div v-else class="guide-empty">当前方案暂无示意图。</div>
                        <div class="guide-footer">
                            <div class="guide-summary"><span v-if="productSop?.rendered_illustration_name && guideMode==='rendered'">{{productSop.rendered_illustration_name}}</span><span v-else>{{selectedPreset?.summary || productSop?.scenario || '按产品实际包装要求执行'}}</span></div>
                            <button type="button" class="step-toggle" @click="showSteps=!showSteps">{{showSteps?'收起分步骤教程':'查看分步骤教程'}} {{showSteps?'↑':'→'}}</button>
                        </div>
                        <div v-if="productSop?.examples?.length && (sopMatchesPreset || customSopAvailable)" class="factory-case-block">
                            <div class="factory-case-head"><strong>真实包装案例</strong><span>由管理员 / 运营维护，仅供打包参考</span></div>
                            <div class="factory-case-grid"><figure v-for="example in productSop.examples" :key="example.image_url || example.title"><img v-if="example.image_url" :src="example.image_url" :alt="example.title || '真实包装案例'"/><figcaption>{{example.title || '真实包装案例'}}</figcaption></figure></div>
                        </div>
                        <div v-if="showSteps" class="step-panel">
                            <div v-if="currentSteps.length" class="step-grid">
                                <article v-for="step in currentSteps" :key="step.position" class="step-card"><div class="step-copy"><span>{{step.position}}</span><div><h4>{{step.title}}</h4><p>{{step.instruction}}</p></div></div><img v-if="step.image_url" :src="step.image_url" :alt="step.title" /></article>
                            </div>
                            <p v-else class="guide-empty compact">当前没有配置分步骤教程。</p>
                            <div v-if="currentTips.length" class="tips-box"><strong>包装要点</strong><ul><li v-for="tip in currentTips" :key="tip">{{tip}}</li></ul></div>
                        </div>
                    </template>
                </div>
            </section>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
.form-page{max-width:1120px;margin:0 auto;padding-bottom:40px}.page-heading{align-items:flex-start}.page-heading h2{margin:2px 0 4px}.page-purpose{margin:0;color:#667b84;font-size:13px}.action-zone{flex-wrap:wrap;justify-content:flex-end}.section-card{margin-top:16px;padding:22px}.section-title{display:flex;align-items:flex-start;gap:10px;margin-bottom:18px}.section-title>span{display:inline-flex;width:24px;height:24px;align-items:center;justify-content:center;border-radius:999px;background:#147f70;color:#fff;font-size:12px;font-weight:800;flex:0 0 auto}.section-title h3{margin:0;font-size:18px;color:#1b3039}.section-title p{margin:4px 0 0;color:#72858d;font-size:12px}.section-title.small{margin-bottom:14px}.section-title.small h3{font-size:16px}.identity-grid{display:grid;grid-template-columns:1.35fr .65fr;gap:16px}.field-block{position:relative;display:flex;flex-direction:column;gap:7px}.field-block label,.evidence-entry-card label>span{font-size:13px;font-weight:700;color:#2c414a}.field-block em,.evidence-entry-card em{color:#b94c45;font-style:normal}.field-block input,.field-block select,.weight-fields input,.dimension-inputs input,.note-section textarea{width:100%;min-height:42px;border:1px solid #dbe5e8;border-radius:10px;background:#fff;padding:9px 11px;color:#20343d;outline:none}.field-block input:focus,.field-block select:focus,.weight-fields input:focus,.dimension-inputs input:focus,.note-section textarea:focus{border-color:#2d8c84;box-shadow:0 0 0 3px rgba(45,140,132,.1)}.search-control{display:flex}.search-control input{border-radius:10px 0 0 10px}.search-control button{width:44px;border:1px solid #dbe5e8;border-left:0;border-radius:0 10px 10px 0;background:#f8fbfb;color:#47616b;font-size:18px}.search-results{position:absolute;z-index:20;top:70px;left:0;right:0;border:1px solid #dbe5e8;border-radius:10px;background:#fff;box-shadow:0 10px 30px rgba(25,54,64,.12);overflow:hidden}.search-results button{display:block;width:100%;padding:10px 12px;border:0;border-bottom:1px solid #edf2f3;background:#fff;text-align:left;color:#273e47}.search-results button:hover{background:#f1f8f6}.field-error{color:#b42318}.preset-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:22px 0 10px;padding-top:18px;border-top:1px solid #edf2f3}.preset-heading h4{margin:0;font-size:14px}.preset-heading p{margin:3px 0 0;color:#7a8c94;font-size:12px}.custom-link,.step-toggle{border:0;background:transparent;color:#167b70;font-size:12px;font-weight:700;cursor:pointer}.preset-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.preset-card{position:relative;display:flex;min-width:0;flex-direction:column;align-items:flex-start;gap:6px;padding:12px;border:1px solid #dce6e9;border-radius:12px;background:#fff;text-align:left;cursor:pointer}.preset-card:hover{border-color:#94c5bd}.preset-card.active{border-color:#168578;box-shadow:0 0 0 2px rgba(22,133,120,.08);background:#fbfffd}.preset-card img{width:100%;height:82px;object-fit:cover;border-radius:8px;background:#f3f7f8}.preset-card strong{font-size:13px;color:#263c45}.preset-card small{font-size:10.5px;line-height:1.4;color:#768a92}.preset-check{position:absolute;right:8px;top:8px;display:flex;width:22px;height:22px;align-items:center;justify-content:center;border-radius:999px;background:#168578;color:#fff;font-size:12px;font-weight:800;z-index:2}.custom-notice{display:flex;gap:10px;align-items:center;margin-top:12px;padding:10px 12px;border-radius:10px;background:#eff8f6;color:#45645b;font-size:12px}.custom-notice strong{color:#167b70}.guide-card{padding:22px}.guide-title{margin-bottom:14px}.guide-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:10px}.guide-tabs{display:flex;gap:4px;padding:3px;border-radius:10px;background:#f1f5f6}.guide-tabs button{min-height:34px;padding:0 14px;border:0;border-radius:8px;background:transparent;color:#6d8088;font-size:12px;font-weight:700}.guide-tabs button.active{background:#fff;color:#167b70;box-shadow:0 1px 4px rgba(25,55,65,.09)}.guide-tabs button:disabled{opacity:.4}.guide-meta{display:flex;gap:12px;flex-wrap:wrap;color:#73868e;font-size:11px}.guide-meta b{color:#2a4d43}.guide-hero{overflow:hidden;border:1px solid #dfe8eb;border-radius:13px;background:#f7fafb}.guide-hero img{display:block;width:100%;max-height:440px;object-fit:cover}.guide-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 2px 0}.guide-summary{color:#637881;font-size:12px}.guide-empty{display:flex;min-height:180px;align-items:center;justify-content:center;border:1px dashed #cad8dc;border-radius:12px;background:#f8fbfc;color:#86979e}.guide-empty.compact{min-height:80px}.factory-case-block{margin-top:14px;padding-top:14px;border-top:1px solid #e6edef}.factory-case-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:9px}.factory-case-head strong{font-size:13px;color:#28444d}.factory-case-head span{font-size:10.5px;color:#84949a}.factory-case-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.factory-case-grid figure{margin:0}.factory-case-grid img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;border:1px solid #dde7e9;border-radius:9px}.factory-case-grid figcaption{margin-top:5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#70838a;font-size:10px}.step-panel{margin-top:14px;padding-top:14px;border-top:1px solid #e7edef}.step-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.step-card{padding:10px;border:1px solid #e0e9eb;border-radius:11px;background:#fff}.step-copy{display:flex;gap:8px}.step-copy>span{display:flex;width:21px;height:21px;align-items:center;justify-content:center;border-radius:999px;background:#168578;color:#fff;font-size:11px;font-weight:800;flex:0 0 auto}.step-copy h4{margin:1px 0 3px;font-size:12px}.step-copy p{margin:0;color:#74878f;font-size:10.5px;line-height:1.45}.step-card img{display:block;width:100%;margin-top:8px;aspect-ratio:16/9;object-fit:cover;border-radius:8px}.tips-box{margin-top:10px;padding:12px 14px;border-radius:10px;background:#eff8f3;color:#48645b;font-size:11px}.tips-box strong{color:#167b70}.tips-box ul{display:grid;grid-template-columns:1fr 1fr;gap:4px 24px;margin:7px 0 0;padding-left:18px}.evidence-entry-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}.evidence-entry-card{padding:18px;min-width:0}.dimension-inputs{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.dimension-inputs label,.weight-fields label{display:flex;flex-direction:column;gap:6px}.unit-input{display:flex;align-items:center;border:1px solid #dbe5e8;border-radius:10px;overflow:hidden;background:#fff}.unit-input input{border:0;border-radius:0;min-width:0;box-shadow:none!important}.unit-input b{padding:0 10px;color:#71858d;font-size:11px;font-weight:600}.metric-summary{margin:10px 0 13px;color:#698089;font-size:12px}.metric-summary strong{color:#29434d}.weight-fields{display:grid;grid-template-columns:.8fr 1.2fr;gap:10px;margin-bottom:13px}.weight-fields input{min-height:42px}.upload-title{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:7px}.upload-title strong{font-size:12px;color:#304751}.upload-title small{font-size:10.5px;color:#82939a}.dropzone{display:flex;min-height:112px;align-items:center;justify-content:center;flex-direction:column;gap:4px;border:1.5px dashed #b9cbd1;border-radius:11px;background:#fbfdfe;color:#536e78;cursor:pointer}.dropzone.large{min-height:135px}.dropzone.disabled{cursor:not-allowed;opacity:.6}.dropzone input{position:absolute;width:1px;height:1px;opacity:0}.upload-icon{font-size:24px;color:#237f73}.dropzone strong{font-size:12px}.dropzone small{font-size:10px;color:#84959c}.thumb-grid{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}.thumb-grid figure{position:relative;margin:0}.thumb-grid img{display:block;width:86px;height:70px;object-fit:cover;border:1px solid #e0e8ea;border-radius:8px}.reference-banner{display:flex;gap:10px;align-items:center;margin-bottom:14px;padding:10px 12px;border-radius:10px;background:#f3f7f7;color:#61777f;font-size:12px}.reference-banner strong{color:#177b70}.reference-heading{margin-top:0;padding-top:0;border-top:0}.reference-guide{margin-top:18px;padding-top:18px;border-top:1px solid #e7edef}.primary-evidence-grid .evidence-entry-card{min-height:390px}.thumb-grid button{position:absolute;right:-5px;top:-5px;width:20px;height:20px;border:0;border-radius:999px;background:#40545b;color:#fff;cursor:pointer}.note-section textarea{min-height:100px;resize:vertical}.note-section{margin-top:16px}.note-section .section-title{margin-bottom:10px}
@media(max-width:900px){.form-page{max-width:none}.identity-grid{grid-template-columns:1fr}.preset-grid{grid-template-columns:1fr 1fr}.evidence-entry-grid{grid-template-columns:1fr}.step-grid{grid-template-columns:1fr 1fr}.guide-toolbar{align-items:flex-start;flex-direction:column}.guide-meta{width:100%}}
@media(max-width:700px){.factory-case-head{align-items:flex-start;flex-direction:column;gap:2px}.factory-case-grid{grid-template-columns:repeat(2,1fr)}.form-page{padding-bottom:24px}.page-heading{gap:12px}.action-zone{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr));width:100%;gap:8px}.action-zone .mvp-button{width:100%;min-width:0;padding-left:8px;padding-right:8px}.section-card,.guide-card{padding:14px}.section-title h3{font-size:16px}.section-title p{font-size:11px}.preset-heading{align-items:flex-start;flex-direction:column}.preset-grid{grid-template-columns:1fr}.preset-card{display:grid;grid-template-columns:72px 1fr;grid-template-rows:auto auto;align-items:center}.preset-card img{grid-row:1/3;width:72px;height:58px}.preset-card strong{align-self:end}.preset-card small{align-self:start}.custom-notice{align-items:flex-start;flex-direction:column}.guide-tabs{width:100%}.guide-tabs button{flex:1}.guide-meta{flex-direction:column;gap:3px}.guide-hero img{max-height:none;aspect-ratio:16/9;object-fit:cover}.guide-footer{align-items:flex-start;flex-direction:column}.step-grid{grid-template-columns:1fr}.tips-box ul{grid-template-columns:1fr}.dimension-inputs{grid-template-columns:repeat(3,1fr);gap:6px}.dimension-inputs label>span{font-size:11px}.unit-input b{padding:0 6px}.weight-fields{grid-template-columns:1fr}.upload-title{align-items:flex-start;flex-direction:column;gap:2px}.dropzone{min-height:105px}.thumb-grid img{width:92px;height:72px}.evidence-entry-card{padding:14px}}
@media(max-width:390px){.dimension-inputs{grid-template-columns:1fr}.action-zone{grid-template-columns:1fr 1fr 1fr}}
</style>
