<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ImageRef = { id:number|null; name:string|null; url:string }
type PackagingProfile = {
  id:number; preset_key:string|null; method:string; scenario:string|null; version:string|null; units_per_carton:number|null;
  inherits_global_template:boolean; default_image:ImageRef|null; rendered_image:ImageRef|null; examples:ImageRef[]
}
type Preset = { key:string; label:string }
const props = defineProps<{product:{id:number;sku:string|null;name:string};packagingProfile:PackagingProfile|null;presets:Preset[]}>()
const initialPresetKey:string|null = props.packagingProfile?.preset_key ?? props.presets[0]?.key ?? null
const form = useForm<{preset_key:string|null;method:string;scenario:string;version:string;units_per_carton:number|null}>({
  preset_key: initialPresetKey,
  method: props.packagingProfile?.method ?? '',
  scenario: props.packagingProfile?.scenario ?? '',
  version: props.packagingProfile?.version ?? '',
  units_per_carton: props.packagingProfile?.units_per_carton ?? null,
})
const selectedPreset = computed(() => props.presets.find(item => item.key === form.preset_key) ?? null)
const isCustom = computed(() => !form.preset_key)
function choosePreset(key:string){form.preset_key=key;form.method=props.presets.find(item=>item.key===key)?.label??''}
function chooseCustom(){form.preset_key=null;if(selectedPreset.value?.label===form.method)form.method=''}
function save(){form.put(`/products/${props.product.id}/packaging`,{preserveScroll:true})}
function upload(kind:'default'|'rendered'|'example',event:Event){
  const input=event.target as HTMLInputElement
  if(!props.packagingProfile){alert('请先保存包装方案，再上传图片');input.value='';return}
  const files=input.files;if(!files?.length)return
  const data=new FormData();data.append('kind',kind);Array.from(files).forEach(file=>data.append('files[]',file))
  router.post(`/products/${props.product.id}/packaging/media`,data,{forceFormData:true,preserveScroll:true,onFinish:()=>{input.value=''}})
}
function removeImage(image:ImageRef){if(image.id===null)return;if(!confirm(`删除 ${image.name||'这张图片'}？`))return;router.delete(`/products/packaging/media/${image.id}`,{preserveScroll:true})}
</script>

<template>
  <Head :title="`${product.name} · 包装方案`" />
  <AuthenticatedLayout title="产品包装方案" :breadcrumbs="[{label:'产品',href:'/products'},{label:'产品360',href:`/products/${product.id}`},'包装方案']">
    <section class="packaging-admin-page">
      <div class="mvp-page-heading admin-heading">
        <div><p class="mvp-kicker">PRODUCT PACKAGING</p><h2>包装方案</h2><p>{{product.sku||'无 SKU'}} · {{product.name}}</p></div>
        <div class="mvp-actions"><Link class="mvp-button" :href="`/products/${product.id}`">返回 Product360</Link><button class="mvp-button mvp-button-primary" :disabled="form.processing" @click="save">{{form.processing?'保存中…':'保存包装方案'}}</button></div>
      </div>

      <article class="mvp-card admin-card">
        <div class="card-head"><div><span class="num">1</span><div><h3>包装方式</h3><p>4 套预设来自全局公共模板；产品只保存模板引用和自己的差异，工厂端只读。</p></div></div><span class="status-chip">工厂可见</span></div>
        <div class="template-note"><strong>全局模板复用</strong><span>无论 10 个还是 1000 个产品，这 4 套教程只维护一次；本产品不会复制模板步骤和图片。</span></div>
        <div class="preset-grid">
          <button v-for="preset in presets" :key="preset.key" type="button" class="preset-option" :class="{active:form.preset_key===preset.key}" @click="choosePreset(preset.key)"><span v-if="form.preset_key===preset.key">✓</span><strong>{{preset.label}}</strong></button>
          <button type="button" class="preset-option" :class="{active:isCustom}" @click="chooseCustom"><span v-if="isCustom">✓</span><strong>定制包装方案</strong></button>
        </div>
        <div class="meta-grid">
          <label><span>方案名称</span><input v-model="form.method" :disabled="!isCustom" :placeholder="selectedPreset?.label||'请输入定制方案名称'"/><small v-if="form.errors.method" class="error">{{form.errors.method}}</small></label>
          <label><span>版本</span><input v-model="form.version" placeholder="例如 v2 / 2026-09"/></label>
          <label><span>几个/箱</span><input v-model.number="form.units_per_carton" type="number" min="1" step="1" placeholder="例如 12"/><small>海运 / 备货使用的标准整箱装箱数</small><small v-if="form.errors.units_per_carton" class="error">{{form.errors.units_per_carton}}</small></label>
          <label class="wide"><span>适用说明</span><textarea v-model="form.scenario" rows="2" placeholder="例如：适用于压缩后长度 30–40cm 的枕头"></textarea></label>
        </div>
      </article>

      <article class="mvp-card admin-card">
        <div class="card-head"><div><span class="num">2</span><div><h3>产品专属主示意图</h3><p>建议上传最终给工厂看的大图：真实包装照、设计图或 AI 渲染图均可。</p></div></div><span class="status-chip primary">优先展示</span></div>
        <div class="media-editor hero-editor">
          <div class="preview large"><img v-if="packagingProfile?.rendered_image" :src="packagingProfile.rendered_image.url" :alt="packagingProfile.rendered_image.name||'产品专属主示意图'"/><div v-else class="empty">暂无产品专属主示意图</div></div>
          <div class="upload-panel"><strong>上传 / 替换主示意图</strong><p>工厂端优先显示此图；没有时退回默认示意图。</p><label class="upload-btn"><input type="file" accept="image/jpeg,image/png,image/webp" @change="upload('rendered',$event)"/>选择图片</label><button v-if="packagingProfile?.rendered_image?.id" type="button" class="text-danger" @click="removeImage(packagingProfile.rendered_image)">删除当前图片</button><small>JPG / PNG / WEBP · ≤ 10MB</small></div>
        </div>
      </article>

      <article class="mvp-card admin-card">
        <div class="card-head"><div><span class="num">3</span><div><h3>真实包装案例图</h3><p>可上传多张工厂实际包装照片，帮助不同尺寸产品确认最终效果。</p></div></div><span class="status-chip">可多张</span></div>
        <label class="case-dropzone"><input type="file" accept="image/jpeg,image/png,image/webp" multiple @change="upload('example',$event)"/><span>＋</span><strong>上传包装案例图</strong><small>支持一次选择多张 · JPG / PNG / WEBP · 单张 ≤ 10MB</small></label>
        <div v-if="packagingProfile?.examples.length" class="case-grid"><figure v-for="image in packagingProfile.examples" :key="image.id||image.url"><img :src="image.url" :alt="image.name||'包装案例图'"/><figcaption>{{image.name||'包装案例图'}}</figcaption><button v-if="image.id" type="button" @click="removeImage(image)">×</button></figure></div>
        <p v-else class="empty-note">还没有真实案例图。上传后，工厂端会在包装参考区域看到这些图片。</p>
      </article>

      <article class="mvp-card admin-card optional-card">
        <div class="card-head"><div><span class="num">4</span><div><h3>产品默认示意图覆盖</h3><p>可选。若不上传，系统继续使用所选预设方案的标准默认图。</p></div></div><span class="status-chip muted">可选</span></div>
        <div class="media-editor">
          <div class="preview"><img v-if="packagingProfile?.default_image" :src="packagingProfile.default_image.url" :alt="packagingProfile.default_image.name||'默认示意图'"/><div v-else class="empty">使用系统预设默认图</div></div>
          <div class="upload-panel"><strong>上传产品默认示意图</strong><p>适合产品尺寸特殊、标准预设图不够准确时覆盖。</p><label class="upload-btn secondary"><input type="file" accept="image/jpeg,image/png,image/webp" @change="upload('default',$event)"/>选择图片</label><button v-if="packagingProfile?.default_image?.id" type="button" class="text-danger" @click="removeImage(packagingProfile.default_image)">删除覆盖图</button></div>
        </div>
      </article>

      <div class="flow-note"><strong>最终交互：</strong><span>管理员/运营在这里维护图片 → 工厂在“包装与测量”底部只读查看 → 工厂每单仍只需要上传尺寸图 + 称重图。</span></div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.packaging-admin-page{max-width:1120px;margin:0 auto;padding-bottom:40px}.admin-heading{align-items:flex-start}.admin-heading h2{margin:2px 0 4px}.admin-heading p{margin:0;color:#6b7e86}.admin-card{margin-top:16px;padding:22px}.card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.card-head>div{display:flex;align-items:flex-start;gap:10px}.card-head h3{margin:0;font-size:18px}.card-head p{margin:4px 0 0;color:#71838b;font-size:12px}.num{display:flex;width:24px;height:24px;align-items:center;justify-content:center;border-radius:999px;background:#147f70;color:#fff;font-size:12px;font-weight:800}.status-chip{padding:5px 9px;border-radius:999px;background:#eef7f5;color:#247469;font-size:11px;font-weight:700}.status-chip.primary{background:#e7f7ef;color:#147f5c}.status-chip.muted{background:#f3f5f6;color:#7b8a90}.template-note{display:flex;gap:8px;margin-bottom:12px;padding:10px 12px;border-radius:9px;background:#f3faf7;color:#557069;font-size:11px}.template-note strong{color:#167b70;white-space:nowrap}.preset-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.preset-option{position:relative;min-height:74px;padding:12px;border:1px solid #dce6e8;border-radius:11px;background:#fff;color:#31464f;text-align:left;cursor:pointer}.preset-option.active{border-color:#168578;background:#fbfffd;box-shadow:0 0 0 2px rgba(22,133,120,.08)}.preset-option span{position:absolute;right:8px;top:8px;display:flex;width:20px;height:20px;align-items:center;justify-content:center;border-radius:999px;background:#168578;color:#fff}.preset-option strong{font-size:12px;line-height:1.35}.meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px;padding-top:16px;border-top:1px solid #edf1f2}.meta-grid label{display:flex;flex-direction:column;gap:6px}.meta-grid label>span{font-size:12px;font-weight:700}.meta-grid input,.meta-grid textarea{width:100%;border:1px solid #dce5e8;border-radius:9px;padding:9px 11px;min-height:40px}.meta-grid input:disabled{background:#f4f6f7;color:#84949a}.meta-grid .wide{grid-column:1/-1}.error{color:#b42318}.media-editor{display:grid;grid-template-columns:1.35fr .65fr;gap:18px}.preview{min-height:190px;border:1px solid #dfe7e9;border-radius:12px;overflow:hidden;background:#f7fafb}.preview.large{min-height:330px}.preview img{display:block;width:100%;height:100%;min-height:190px;object-fit:contain;background:#f5f8f9}.preview.large img{min-height:330px;max-height:480px}.preview .empty{display:grid;place-items:center;min-height:inherit;color:#89999f;font-size:13px}.upload-panel{display:flex;flex-direction:column;align-items:flex-start;justify-content:center;gap:8px;padding:6px}.upload-panel strong{font-size:15px}.upload-panel p{margin:0;color:#71838b;font-size:12px;line-height:1.5}.upload-panel small{color:#8a989e}.upload-btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 16px;border-radius:8px;background:#168578;color:#fff;font-size:12px;font-weight:700;cursor:pointer}.upload-btn.secondary{background:#fff;color:#276b64;border:1px solid #9bc8c1}.upload-btn input,.case-dropzone input{position:absolute;width:1px;height:1px;opacity:0}.text-danger{padding:0;border:0;background:transparent;color:#b44343;font-size:12px;cursor:pointer}.case-dropzone{display:flex;min-height:120px;flex-direction:column;align-items:center;justify-content:center;gap:5px;border:1.5px dashed #b9cbd1;border-radius:12px;background:#fbfdfe;color:#53717a;cursor:pointer}.case-dropzone>span{font-size:28px;color:#188072}.case-dropzone strong{font-size:13px}.case-dropzone small{font-size:10px;color:#8a999f}.case-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:14px}.case-grid figure{position:relative;margin:0}.case-grid img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;border:1px solid #dfe7e9;border-radius:10px}.case-grid figcaption{margin-top:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64777f;font-size:11px}.case-grid button{position:absolute;right:-5px;top:-5px;width:22px;height:22px;border:0;border-radius:999px;background:#40555d;color:#fff;cursor:pointer}.empty-note{margin:12px 0 0;color:#83939a;font-size:12px}.optional-card{opacity:.96}.flow-note{display:flex;gap:8px;margin-top:16px;padding:13px 15px;border:1px solid #d6e8e3;border-radius:10px;background:#f3faf7;color:#4a665e;font-size:12px}.flow-note strong{color:#177366}
@media(max-width:900px){.preset-grid{grid-template-columns:repeat(2,1fr)}.media-editor{grid-template-columns:1fr}.case-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:700px){.packaging-admin-page{padding-bottom:24px}.admin-heading{gap:12px}.admin-heading .mvp-actions{display:grid;width:100%;grid-template-columns:1fr 1fr}.admin-card{padding:14px}.card-head{gap:8px}.card-head h3{font-size:16px}.preset-grid{grid-template-columns:1fr}.preset-option{min-height:54px}.meta-grid{grid-template-columns:1fr}.meta-grid .wide{grid-column:auto}.preview.large{min-height:220px}.preview.large img{min-height:220px}.case-grid{grid-template-columns:repeat(2,1fr)}.flow-note{flex-direction:column}}
</style>
