<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Media = { id:number; kind:string; name:string|null; url:string }
type SopStep = { position:number; title:string; instruction:string; image_url?:string|null }
type SopExample = { title:string; image_url?:string|null }
type PackagingSop = { id:number; title:string; method:string; scenario?:string|null; version?:string|null; materials:string[]; steps:SopStep[]; tips:string[]; examples:SopExample[] }
const props = defineProps<{record:any; packagingSop:PackagingSop|null; canReview:boolean; canEdit:boolean; canAdoptStandard:boolean; isCurrentStandard:boolean; currentStandardRecordId:number|null}>()
const labels:any = { draft:'草稿', submitted:'待审核', approved:'已批准', rejected:'已驳回' }
const reviewNote = ref('')

function review(decision:string) {
    router.post(`/inventory/packaging/${props.record.id}/review`, {
        decision,
        review_note: reviewNote.value || null,
    })
}

function adoptStandard() {
    router.post(`/inventory/packaging/${props.record.id}/adopt-standard`)
}

function mediaFor(kind:string): Media[] {
    return (props.record.media ?? []).filter((media:Media) => media.kind === kind)
}
</script>

<template>
    <Head title="包装测量详情" />
    <AuthenticatedLayout title="包装与测量" :breadcrumbs="[{label:'库存',href:'/inventory'},{label:'包装与测量',href:'/inventory/packaging'},'详情']">
        <section class="mvp-page evidence-page">
            <div class="mvp-page-heading product-heading">
                <div>
                    <p class="mvp-kicker">Evidence Record #{{record.id}}</p>
                    <h2>{{record.product.name}}</h2>
                    <p>{{record.product.sku || '无 SKU'}} · {{labels[record.status]}}</p>
                </div>
                <div class="mvp-actions">
                    <span class="status-pill" :class="`status-${record.status}`">{{labels[record.status]}}</span>
                    <Link class="mvp-button" href="/inventory/packaging">返回列表</Link>
                    <Link v-if="canEdit" class="mvp-button" :href="`/inventory/packaging/${record.id}/edit`">编辑草稿</Link>
                    <span v-if="isCurrentStandard" class="current-standard-pill">当前包装标准</span>
                    <button v-else-if="canAdoptStandard" class="mvp-button mvp-button-primary" @click="adoptStandard">设为当前包装标准</button>
                    <button v-if="canReview && record.status === 'submitted'" class="mvp-button" @click="review('rejected')">驳回</button>
                    <button v-if="canReview && record.status === 'submitted'" class="mvp-button mvp-button-primary" @click="review('approved')">批准</button>
                </div>
            </div>

            <div class="evidence-grid">
                <article class="mvp-card evidence-card">
                    <div class="evidence-card-title"><span class="step">1</span><h3>尺寸证据</h3></div>
                    <div class="metric-primary">{{record.dimensions}}</div>
                    <div class="dimension-grid">
                        <div><small>长度</small><strong>{{record.packaging_length_cm}} cm</strong></div>
                        <div><small>宽度</small><strong>{{record.packaging_width_cm}} cm</strong></div>
                        <div><small>高度</small><strong>{{record.packaging_height_cm}} cm</strong></div>
                    </div>
                    <div class="evidence-media" :class="{'is-empty': !mediaFor('measurement').length}">
                        <a v-for="media in mediaFor('measurement')" :key="media.id" :href="media.url" target="_blank" rel="noopener">
                            <img :src="media.url" :alt="media.name || '测量记录图'" />
                            <span>测量记录图</span>
                        </a>
                        <p v-if="!mediaFor('measurement').length" class="empty-evidence">未上传测量记录图</p>
                    </div>
                </article>

                <article class="mvp-card evidence-card">
                    <div class="evidence-card-title"><span class="step">2</span><h3>重量证据</h3></div>
                    <div class="metric-primary">{{record.packaged_weight_kg}} kg</div>
                    <div class="weight-context"><small>包装重量</small><strong>{{record.packaged_weight_kg}} kg</strong></div>
                    <div class="evidence-media" :class="{'is-empty': !mediaFor('weight').length}">
                        <a v-for="media in mediaFor('weight')" :key="media.id" :href="media.url" target="_blank" rel="noopener">
                            <img :src="media.url" :alt="media.name || '称重记录图'" />
                            <span>称重记录图</span>
                        </a>
                        <p v-if="!mediaFor('weight').length" class="empty-evidence">未上传称重记录图</p>
                    </div>
                </article>


            </div>



            <div class="mvp-card review-summary">
                <div class="summary-icon">✓</div>
                <div><small>审核信息</small><strong>审核</strong></div>
                <p>{{record.reviewer || '—'}} <span>{{record.reviewed_at || ''}}</span></p>
            </div>

            <div v-if="canReview && record.status === 'submitted'" class="mvp-card review-box">
                <h3>审核备注</h3>
                <textarea v-model="reviewNote" rows="3" placeholder="可填写审核意见；驳回时建议说明原因"></textarea>
            </div>

            <div v-if="record.note || record.review_note" class="mvp-card note-card">
                <p v-if="record.note"><b>备注：</b>{{record.note}}</p>
                <p v-if="record.review_note"><b>审核备注：</b>{{record.review_note}}</p>
            </div>

            <section v-if="packagingSop" class="mvp-card sop-section">
                <div class="sop-heading">
                    <div>
                        <p class="mvp-kicker">PACKAGING REFERENCE</p>
                        <h3>包装方式参考 · {{packagingSop.method}}</h3>
                    </div>
                    <span v-if="packagingSop.version" class="sop-version">{{packagingSop.version}}</span>
                </div>

                <div class="sop-summary">
                    <div class="sop-method"><span class="sop-check">✓</span><span><small>推荐包装方式</small><strong>{{packagingSop.method}}</strong></span></div>
                    <div v-if="packagingSop.scenario" class="sop-scenario"><small>适用场景</small><strong>{{packagingSop.scenario}}</strong></div>
                    <div class="sop-materials"><small>所需材料</small><div class="material-chips"><span v-for="material in packagingSop.materials" :key="material">{{material}}</span></div></div>
                </div>

                <div class="sop-steps">
                    <article v-for="step in packagingSop.steps" :key="step.position" class="sop-step-card">
                        <div class="sop-step-copy"><span class="step">{{step.position}}</span><div><h4>{{step.title}}</h4><p>{{step.instruction}}</p></div></div>
                        <img v-if="step.image_url" :src="step.image_url" :alt="`${step.title}示意图`" loading="lazy" />
                    </article>
                </div>

                <div class="sop-footer">
                    <div class="sop-tips"><h4><span class="sop-check">✓</span>包装要点</h4><ul><li v-for="tip in packagingSop.tips" :key="tip">{{tip}}</li></ul></div>
                    <div v-if="packagingSop.examples.length" class="sop-examples"><figure v-for="example in packagingSop.examples" :key="example.title"><img v-if="example.image_url" :src="example.image_url" :alt="example.title" loading="lazy"/><figcaption>{{example.title}}</figcaption></figure></div>
                </div>
            </section>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
.evidence-page{padding-bottom:36px}.current-standard-pill{display:inline-flex;align-items:center;min-height:34px;padding:0 12px;border:1px solid #a7d9c4;border-radius:10px;background:#ecfdf3;color:#137a50;font-size:12px;font-weight:800}.product-heading{align-items:center}.status-pill{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 13px;border-radius:10px;font-size:13px;font-weight:700;border:1px solid #cbd5e1;background:#f8fafc;color:#475569}.status-approved{background:#ecfdf3;border-color:#bbf7d0;color:#15803d}.status-submitted{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}.status-rejected{background:#fff1f2;border-color:#fecdd3;color:#be123c}.status-draft{background:#f8fafc;color:#64748b}
.evidence-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:stretch}.evidence-card{display:flex;flex-direction:column;min-width:0;padding:20px}.evidence-card-title{display:flex;align-items:center;gap:9px;margin-bottom:16px}.evidence-card-title h3{margin:0;font-size:17px}.step{display:inline-flex;width:22px;height:22px;align-items:center;justify-content:center;border-radius:999px;background:#16865c;color:#fff;font-size:12px;font-weight:800}.metric-primary{font-size:23px;font-weight:800;color:#142433;letter-spacing:-.02em;margin-bottom:14px}.dimension-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding-bottom:16px;border-bottom:1px solid #e2e8f0}.dimension-grid div,.supporting-facts div,.weight-context{display:flex;flex-direction:column;gap:4px}.dimension-grid small,.supporting-facts small,.weight-context small,.review-summary small{color:#64748b;font-size:12px}.dimension-grid strong,.supporting-facts strong,.weight-context strong{color:#475569;font-size:14px;font-weight:600}
.weight-context{padding-bottom:16px;border-bottom:1px solid #e2e8f0}.supporting-facts{display:grid;gap:18px;margin-bottom:16px}.evidence-media{display:grid;grid-template-columns:1fr;gap:12px;margin-top:auto;padding-top:16px}.evidence-media a{display:flex;flex-direction:column;gap:8px;color:#64748b;text-decoration:none;font-size:12px;text-align:center}.evidence-media img{display:block;width:100%;height:220px;object-fit:cover;border-radius:12px;border:1px solid #e2e8f0;background:#f8fafc}.evidence-media a:focus-visible img,.evidence-media a:hover img{outline:2px solid #16865c;outline-offset:2px}.empty-evidence{display:flex;min-height:220px;align-items:center;justify-content:center;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;color:#94a3b8;font-size:13px}.packaging-card .evidence-media img{height:220px}.review-summary{display:flex;align-items:center;gap:14px;margin-top:16px}.summary-icon{display:flex;width:40px;height:40px;align-items:center;justify-content:center;border-radius:10px;background:#dcfce7;color:#16865c;font-size:20px;font-weight:900}.review-summary>div:nth-child(2){display:flex;min-width:120px;flex-direction:column;gap:3px}.review-summary>div:nth-child(2) strong{font-size:14px}.review-summary p{margin:0;color:#475569}.review-summary p span{margin-left:4px;color:#64748b}.review-box{margin-top:16px}.review-box textarea{width:100%;margin-top:10px;min-height:84px}.note-card{margin-top:16px}.note-card p{margin:4px 0;color:#475569}
.sop-section{margin-top:16px;padding:20px}.sop-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}.sop-heading h3{margin:2px 0 0;font-size:20px;color:#142433}.sop-version{padding:5px 9px;border-radius:999px;background:#f1f5f9;color:#64748b;font-size:11px;font-weight:700}.sop-summary{display:grid;grid-template-columns:1.05fr 1.1fr 1.25fr;align-items:center;border:1px solid #dbe7e1;border-radius:12px;background:#fbfefc;margin-bottom:16px}.sop-summary>div{min-height:72px;padding:14px 16px}.sop-summary>div+div{border-left:1px solid #e2e8f0}.sop-method{display:flex;align-items:center;gap:10px}.sop-method span:last-child,.sop-scenario,.sop-materials{display:flex;flex-direction:column;gap:5px}.sop-summary small{color:#64748b;font-size:11px}.sop-summary strong{color:#234438;font-size:14px}.sop-check{display:inline-flex;width:24px;height:24px;align-items:center;justify-content:center;border-radius:999px;background:#16865c;color:white;font-size:13px;font-weight:900;flex:0 0 auto}.material-chips{display:flex;gap:7px;flex-wrap:wrap}.material-chips span{display:inline-flex;align-items:center;padding:5px 9px;border:1px solid #d4e6dc;border-radius:999px;background:white;color:#446257;font-size:12px;font-weight:600}.sop-steps{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}.sop-step-card{min-width:0;padding:12px;background:#fff}.sop-step-card+.sop-step-card{border-left:1px solid #e2e8f0}.sop-step-copy{display:flex;align-items:flex-start;gap:8px;min-height:76px}.sop-step-copy h4{margin:1px 0 4px;font-size:13px;color:#1f3443}.sop-step-copy p{margin:0;color:#64748b;font-size:11px;line-height:1.45}.sop-step-card img{display:block;width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc}.sop-footer{display:grid;grid-template-columns:.9fr 1.6fr;gap:16px;margin-top:16px}.sop-tips{padding:16px;border:1px solid #dbe7e1;border-radius:12px;background:#fbfefc}.sop-tips h4{display:flex;align-items:center;gap:8px;margin:0 0 10px;font-size:14px}.sop-tips ul{margin:0;padding-left:18px;color:#475569;font-size:12px;line-height:1.7}.sop-examples{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.sop-examples figure{margin:0}.sop-examples img{display:block;width:100%;aspect-ratio:16/8;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0}.sop-examples figcaption{margin-top:6px;text-align:center;color:#64748b;font-size:11px}
@media(max-width:1180px){.evidence-grid{grid-template-columns:1fr 1fr}.sop-steps{grid-template-columns:repeat(3,1fr)}.sop-step-card:nth-child(4){border-left:0;border-top:1px solid #e2e8f0}.sop-step-card:nth-child(n+4){border-top:1px solid #e2e8f0}}
@media(max-width:760px){.product-heading{align-items:flex-start}.evidence-grid{grid-template-columns:1fr}.evidence-media img,.packaging-card .evidence-media img{height:auto;max-height:360px}.dimension-grid{gap:8px}.sop-section{padding:14px}.sop-heading{margin-bottom:12px}.sop-heading h3{font-size:18px}.sop-summary{grid-template-columns:1fr}.sop-summary>div{min-height:auto;padding:11px 12px}.sop-summary>div+div{border-left:0;border-top:1px solid #e2e8f0}.sop-steps{grid-template-columns:1fr;border:0;gap:10px;background:transparent}.sop-step-card,.sop-step-card+.sop-step-card,.sop-step-card:nth-child(n+4),.sop-step-card:nth-child(4){border:1px solid #e2e8f0;border-radius:12px}.sop-step-copy{min-height:auto;margin-bottom:10px}.sop-step-copy h4{font-size:14px}.sop-step-copy p{font-size:12px}.sop-step-card img{aspect-ratio:16/9}.sop-footer{grid-template-columns:1fr}.sop-examples{grid-template-columns:1fr}.sop-examples img{aspect-ratio:16/9}.review-summary{align-items:flex-start;flex-wrap:wrap}.review-summary p{width:100%;padding-left:54px}}
</style>
