<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

const breadcrumbs = [
  { label: 'BYL ERP', href: '/dashboard' },
  { label: '产品', href: '/products' },
  { label: '产品列表', href: '/products/list' },
  '新增产品',
]

const form = ref({
  sku: '',
  label_sku: '',
  label_description: '',
  name: '',
  product_type: 'simple',
  gtin: '',
  unit: '',
  is_active: true,
  weight_kg: '',
  sale_price: '',
  regular_price: '',
  shipping_weight_kg: '',
  length_cm: '',
  width_cm: '',
  height_cm: '',
  country_of_origin: '',
  hs_code: '',
  standard_production_cost: '',
  standard_packaging_cost: '',
  standard_purchase_cost: '',
  cost_currency: '',
  cost_note: '',
  notes: '',
})
const errors = ref<Record<string, string>>({})
const submitting = ref(false)

const submit = () => {
  submitting.value = true
  errors.value = {}
  router.post('/products', form.value, {
    onError: (e) => { errors.value = e },
    onFinish: () => { submitting.value = false },
  })
}
</script>

<template>
  <Head title="新增产品 · BYL ERP" />
  <AuthenticatedLayout title="新增产品" :breadcrumbs="breadcrumbs">
    <div class="erp-form-page">
      <div class="erp-form-content">

        <!-- Page header -->
        <div class="erp-form-header-row">
          <div>
            <h1 class="erp-form-title">新增产品</h1>
            <p class="erp-form-subtitle">管理产品资料、物流属性与会计参考成本</p>
          </div>
          <div class="erp-form-header-actions page-header-actions">
            <a href="/products/list" class="erp-btn erp-btn-secondary">取消</a>
            <a href="/products/list" class="erp-btn erp-btn-secondary">返回列表</a>
            <button type="submit" form="product-create-form" class="erp-btn erp-btn-primary" :disabled="submitting">{{ submitting ? '提交中…' : '创建产品' }}</button>
          </div>
        </div>

        <form id="product-create-form" @submit.prevent="submit">

          <!-- Card 1: 基本信息 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">基本信息</h2>
            <div class="erp-form-grid">

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-sku">
                  SKU <span class="erp-form-required">*</span>
                </label>
                <input
                  id="create-sku"
                  v-model="form.sku"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.sku }"
                  placeholder="输入 SKU"
                  required
                >
                <p v-if="errors.sku" class="erp-field-error">{{ errors.sku }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-label-sku">蓝标 SKU</label>
                <input
                  id="create-label-sku"
                  v-model="form.label_sku"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.label_sku }"
                  placeholder="留空则使用 SKU"
                >
                <p class="erp-field-help">仅在蓝标编号与产品 SKU 不一致时填写，例如 VC549A → C549A。</p>
                <p v-if="errors.label_sku" class="erp-field-error">{{ errors.label_sku }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-label-description">蓝标描述</label>
                <input
                  id="create-label-description"
                  v-model="form.label_description"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.label_description }"
                  placeholder="留空则使用产品名称"
                >
                <p v-if="errors.label_description" class="erp-field-error">{{ errors.label_description }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-name">
                  产品名称 <span class="erp-form-required">*</span>
                </label>
                <input
                  id="create-name"
                  v-model="form.name"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.name }"
                  placeholder="输入产品名称"
                  required
                >
                <p v-if="errors.name" class="erp-field-error">{{ errors.name }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-product-type">产品类型</label>
                <select
                  id="create-product-type"
                  v-model="form.product_type"
                  class="erp-select"
                  :class="{ 'has-error': errors.product_type }"
                >
                  <option value="simple">simple</option>
                  <option value="variable">variable</option>
                  <option value="bundle">bundle</option>
                </select>
                <p v-if="errors.product_type" class="erp-field-error">{{ errors.product_type }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-gtin">GTIN</label>
                <input
                  id="create-gtin"
                  v-model="form.gtin"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.gtin }"
                  placeholder="可选"
                >
                <p v-if="errors.gtin" class="erp-field-error">{{ errors.gtin }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-unit">单位</label>
                <input
                  id="create-unit"
                  v-model="form.unit"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.unit }"
                  placeholder="pcs / 件 / 套"
                >
                <p v-if="errors.unit" class="erp-field-error">{{ errors.unit }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label">状态</label>
                <div class="erp-segmented" role="group" aria-label="产品状态">
                  <button
                    type="button"
                    class="erp-segmented-option"
                    :class="{ selected: form.is_active }"
                    @click="form.is_active = true"
                  >● 启用</button>
                  <button
                    type="button"
                    class="erp-segmented-option"
                    :class="{ selected: !form.is_active }"
                    @click="form.is_active = false"
                  >○ 停用</button>
                </div>
              </div>

            </div>
          </div>

          <!-- Card 2: 销售信息 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">销售信息</h2>
            <div class="erp-form-grid">

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-sale-price">售价</label>
                <input
                  id="create-sale-price"
                  v-model="form.sale_price"
                  type="number"
                  step="0.01"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.sale_price }"
                  placeholder="可选"
                >
                <p v-if="errors.sale_price" class="erp-field-error">{{ errors.sale_price }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-regular-price">原价</label>
                <input
                  id="create-regular-price"
                  v-model="form.regular_price"
                  type="number"
                  step="0.01"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.regular_price }"
                  placeholder="可选"
                >
                <p v-if="errors.regular_price" class="erp-field-error">{{ errors.regular_price }}</p>
              </div>

            </div>
          </div>

          <!-- Card 3: 物流属性 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">物流属性</h2>
            <div class="erp-form-grid">

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-weight">净重 (kg)</label>
                <input
                  id="create-weight"
                  v-model="form.weight_kg"
                  type="number"
                  step="0.0001"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.weight_kg }"
                  placeholder="可选"
                >
                <p v-if="errors.weight_kg" class="erp-field-error">{{ errors.weight_kg }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-shipping-weight">发货重量 (kg)</label>
                <input
                  id="create-shipping-weight"
                  v-model="form.shipping_weight_kg"
                  type="number"
                  step="0.0001"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.shipping_weight_kg }"
                  placeholder="可选"
                >
                <p v-if="errors.shipping_weight_kg" class="erp-field-error">{{ errors.shipping_weight_kg }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-length">长 (cm)</label>
                <input
                  id="create-length"
                  v-model="form.length_cm"
                  type="number"
                  step="0.01"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.length_cm }"
                  placeholder="可选"
                >
                <p v-if="errors.length_cm" class="erp-field-error">{{ errors.length_cm }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-width">宽 (cm)</label>
                <input
                  id="create-width"
                  v-model="form.width_cm"
                  type="number"
                  step="0.01"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.width_cm }"
                  placeholder="可选"
                >
                <p v-if="errors.width_cm" class="erp-field-error">{{ errors.width_cm }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-height">高 (cm)</label>
                <input
                  id="create-height"
                  v-model="form.height_cm"
                  type="number"
                  step="0.01"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.height_cm }"
                  placeholder="可选"
                >
                <p v-if="errors.height_cm" class="erp-field-error">{{ errors.height_cm }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label">体积重量参考</label>
                <div class="erp-readonly-input" aria-label="体积重量参考（计算值）">—</div>
                <p class="erp-field-error" style="color:#7A8C94;">参考/计算值，不作为承运计费重量依据</p>
              </div>

            </div>
          </div>

          <!-- Card 4: 海关与产地 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">海关与产地</h2>
            <div class="erp-form-grid">

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-country">原产国</label>
                <input
                  id="create-country"
                  v-model="form.country_of_origin"
                  type="text"
                  maxlength="2"
                  class="erp-input"
                  :class="{ 'has-error': errors.country_of_origin }"
                  placeholder="CN"
                >
                <p v-if="errors.country_of_origin" class="erp-field-error">{{ errors.country_of_origin }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-hs-code">HS Code</label>
                <input
                  id="create-hs-code"
                  v-model="form.hs_code"
                  type="text"
                  class="erp-input"
                  :class="{ 'has-error': errors.hs_code }"
                  placeholder="可选"
                >
                <p v-if="errors.hs_code" class="erp-field-error">{{ errors.hs_code }}</p>
              </div>

            </div>
          </div>

          <!-- Card 5: 成本参考 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">成本参考</h2>
            <p class="erp-form-card-helper">当前参考成本；历史会计成本由 Accounting 成本版本管理。</p>
            <div class="erp-form-grid">

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-prod-cost">标准生产成本</label>
                <input
                  id="create-prod-cost"
                  v-model="form.standard_production_cost"
                  type="number"
                  step="0.0001"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.standard_production_cost }"
                  placeholder="可选"
                >
                <p v-if="errors.standard_production_cost" class="erp-field-error">{{ errors.standard_production_cost }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-pack-cost">标准包装成本</label>
                <input
                  id="create-pack-cost"
                  v-model="form.standard_packaging_cost"
                  type="number"
                  step="0.0001"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.standard_packaging_cost }"
                  placeholder="可选"
                >
                <p v-if="errors.standard_packaging_cost" class="erp-field-error">{{ errors.standard_packaging_cost }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-purch-cost">标准采购成本</label>
                <input
                  id="create-purch-cost"
                  v-model="form.standard_purchase_cost"
                  type="number"
                  step="0.0001"
                  min="0"
                  class="erp-input"
                  :class="{ 'has-error': errors.standard_purchase_cost }"
                  placeholder="可选"
                >
                <p v-if="errors.standard_purchase_cost" class="erp-field-error">{{ errors.standard_purchase_cost }}</p>
              </div>

              <div class="erp-form-field">
                <label class="erp-form-label" for="create-cost-currency">成本币种</label>
                <input
                  id="create-cost-currency"
                  v-model="form.cost_currency"
                  type="text"
                  maxlength="3"
                  class="erp-input"
                  :class="{ 'has-error': errors.cost_currency }"
                  placeholder="CNY"
                >
                <p v-if="errors.cost_currency" class="erp-field-error">{{ errors.cost_currency }}</p>
              </div>

              <div class="erp-form-field" style="grid-column: 1 / -1;">
                <label class="erp-form-label" for="create-cost-note">成本备注</label>
                <textarea
                  id="create-cost-note"
                  v-model="form.cost_note"
                  class="erp-textarea"
                  :class="{ 'has-error': errors.cost_note }"
                  placeholder="可选"
                />
                <p v-if="errors.cost_note" class="erp-field-error">{{ errors.cost_note }}</p>
              </div>

            </div>
          </div>

          <!-- Card 6: 内部备注 -->
          <div class="erp-form-card">
            <h2 class="erp-form-card-title">内部备注</h2>
            <div style="margin-top:16px;">
              <label class="erp-form-label" for="create-notes" style="margin-bottom:5px;display:block;">备注</label>
              <textarea
                id="create-notes"
                v-model="form.notes"
                class="erp-textarea"
                :class="{ 'has-error': errors.notes }"
                placeholder="内部运营备注，可选"
              />
              <p v-if="errors.notes" class="erp-field-error">{{ errors.notes }}</p>
            </div>
          </div>

        </form>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
