<template>
  <view class="addr-edit">
    <mb-navbar :title="isEdit ? '编辑地址' : '新增地址'" />

    <view v-if="pageLoading" class="addr-edit__loading">
      <mb-skeleton type="card" />
    </view>

    <view v-else class="addr-edit__body">
      <!-- Section header -->
      <view class="addr-edit__section-header">
        <text class="addr-edit__section-title">收货详情</text>
        <text class="addr-edit__section-desc">请填写准确的联系方式以确保准时送达</text>
      </view>

      <!-- Form fields -->
      <view class="form-group">
        <!-- 收货人 -->
        <view class="form-field">
          <text class="form-field__label">收货人</text>
          <view class="form-field__row">
            <input
              v-model="form.receiver_name"
              class="form-field__input"
              type="text"
              :maxlength="50"
              placeholder="输入姓名"
              placeholder-class="form-placeholder"
            />
            <view class="form-field__icon form-field__icon--pen" />
          </view>
          <view class="form-field__line" />
        </view>

        <!-- 手机号 -->
        <view class="form-field">
          <text class="form-field__label">手机号</text>
          <view class="form-field__row">
            <input
              v-model="form.receiver_mobile"
              class="form-field__input"
              type="number"
              :maxlength="11"
              placeholder="11位手机号码"
              placeholder-class="form-placeholder"
            />
            <view class="form-field__icon form-field__icon--phone" />
          </view>
          <view class="form-field__line" />
        </view>

        <!-- 所在地区 -->
        <view class="form-field" @tap="openRegionPicker">
          <text class="form-field__label">所在地区</text>
          <view class="form-field__row form-field__row--tap">
            <text :class="regionDisplayText ? 'form-field__value' : 'form-field__placeholder-text'">
              {{ regionDisplayText || '点击选择省市区' }}
            </text>
            <view class="form-field__icon form-field__icon--arrow" />
          </view>
          <view class="form-field__line" />
        </view>

        <!-- 详细地址 -->
        <view class="form-field">
          <text class="form-field__label">详细地址</text>
          <view class="form-field__row">
            <input
              v-model="form.address_detail"
              class="form-field__input"
              type="text"
              :maxlength="255"
              placeholder="街道、门牌号等具体信息"
              placeholder-class="form-placeholder"
            />
          </view>
          <view class="form-field__line" />
        </view>
      </view>

      <!-- 默认地址开关 -->
      <view class="default-toggle" @tap="form.is_default = form.is_default ? 0 : 1">
        <view class="default-toggle__left">
          <text class="default-toggle__title">设为默认地址</text>
          <text class="default-toggle__desc">下单时将自动使用此地址</text>
        </view>
        <view class="form-switch" :class="{ 'form-switch--on': form.is_default }">
          <view class="form-switch__thumb" />
        </view>
      </view>

      <!-- 底部占位 -->
      <view class="addr-edit__spacer" />
    </view>

    <!-- 底部操作 -->
    <view v-if="!pageLoading" class="addr-edit__footer">
      <view
        v-if="isEdit"
        class="addr-edit__delete-btn"
        @tap="confirmDelete"
      >
        <text class="addr-edit__delete-text">删除地址</text>
      </view>
      <view
        class="addr-edit__save-btn"
        :class="{ 'addr-edit__save-btn--full': !isEdit }"
        @tap="handleSave"
      >
        <text class="addr-edit__save-text">{{ saving ? '保存中...' : '保存并使用' }}</text>
      </view>
    </view>

    <!-- Region picker popup -->
    <view v-if="showPicker" class="region-mask" @tap.self="closePicker">
      <view class="region-panel" :class="{ 'region-panel--show': pickerVisible }">
        <view class="region-panel__header">
          <text class="region-panel__title">选择地区</text>
          <view class="region-panel__close" @tap="closePicker">
            <text class="region-panel__close-icon">✕</text>
          </view>
        </view>

        <!-- Breadcrumb tabs -->
        <view class="region-tabs">
          <view
            v-for="(tab, idx) in breadcrumbs"
            :key="idx"
            class="region-tab"
            :class="{ 'region-tab--active': currentLevel === idx }"
            @tap="jumpToLevel(idx)"
          >
            <text class="region-tab__text">{{ tab.name || levelPlaceholder(idx) }}</text>
          </view>
          <view v-if="breadcrumbs.length < 4 && breadcrumbs[breadcrumbs.length - 1]?.id" class="region-tab region-tab--active">
            <text class="region-tab__text">{{ levelPlaceholder(breadcrumbs.length) }}</text>
          </view>
        </view>

        <!-- Region list -->
        <scroll-view scroll-y class="region-list" :scroll-top="scrollTop">
          <view v-if="regionLoading" class="region-list__loading">
            <text class="region-list__loading-text">加载中...</text>
          </view>
          <view v-else-if="regions.length === 0" class="region-list__empty">
            <text class="region-list__empty-text">无数据</text>
          </view>
          <view
            v-for="r in regions"
            v-else
            :key="r.id"
            class="region-item"
            :class="{ 'region-item--selected': isRegionSelected(r.id) }"
            @tap="selectRegion(r)"
          >
            <text class="region-item__text">{{ r.name }}</text>
            <text v-if="isRegionSelected(r.id)" class="region-item__check">✓</text>
          </view>
        </scroll-view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { ref, computed, reactive, nextTick } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import {
  getAddressInfo,
  createAddress,
  updateAddress,
  deleteAddress,
} from '@/api/user/address'
import { getRegionChildren } from '@/api/region'

const levelLabels = ['请选择省份', '请选择城市', '请选择区县', '请选择街道']

const isEdit = ref(false)
const addressId = ref('')
const pageLoading = ref(false)
const saving = ref(false)

const form = reactive({
  receiver_name: '',
  receiver_mobile: '',
  province_id: 0,
  city_id: 0,
  district_id: 0,
  street_id: 0,
  address_detail: '',
  tag: '',
  is_default: 0,
})

const regionNames = reactive({
  province: '',
  city: '',
  district: '',
  street: '',
})

const regionDisplayText = computed(() => {
  const parts = [regionNames.province, regionNames.city, regionNames.district, regionNames.street]
  return parts.filter(Boolean).join(' ')
})

// --- Region picker state ---
const showPicker = ref(false)
const pickerVisible = ref(false)
const regionLoading = ref(false)
const regions = ref([])
const scrollTop = ref(0)
const currentLevel = ref(0)

const breadcrumbs = ref([])

function levelPlaceholder(idx) {
  return levelLabels[idx] || '请选择'
}

function isRegionSelected(id) {
  const bc = breadcrumbs.value[currentLevel.value]
  return bc && bc.id === id
}

async function openRegionPicker() {
  showPicker.value = true

  if (breadcrumbs.value.length === 0) {
    breadcrumbs.value = form.province_id
      ? buildBreadcrumbsFromForm()
      : []
    currentLevel.value = breadcrumbs.value.length
  }

  await loadRegions(parentIdForLevel(currentLevel.value))
  nextTick(() => { pickerVisible.value = true })
}

function buildBreadcrumbsFromForm() {
  const result = []
  if (form.province_id && regionNames.province) {
    result.push({ id: form.province_id, name: regionNames.province })
  }
  if (form.city_id && regionNames.city) {
    result.push({ id: form.city_id, name: regionNames.city })
  }
  if (form.district_id && regionNames.district) {
    result.push({ id: form.district_id, name: regionNames.district })
  }
  if (form.street_id && regionNames.street) {
    result.push({ id: form.street_id, name: regionNames.street })
  }
  return result
}

function parentIdForLevel(level) {
  if (level === 0) return 0
  return breadcrumbs.value[level - 1]?.id || 0
}

async function loadRegions(parentId) {
  regionLoading.value = true
  scrollTop.value = 0
  try {
    const data = await getRegionChildren(parentId)
    regions.value = Array.isArray(data?.list) ? data.list : (Array.isArray(data) ? data : [])
  } catch {
    regions.value = []
  } finally {
    regionLoading.value = false
  }
}

async function selectRegion(r) {
  const level = currentLevel.value

  const updated = breadcrumbs.value.slice(0, level)
  updated.push({ id: r.id, name: r.name })
  breadcrumbs.value = updated

  applyBreadcrumbsToForm()

  if (level >= 3) {
    closePicker()
    return
  }

  currentLevel.value = level + 1
  await loadRegions(r.id)

  if (regions.value.length === 0) {
    closePicker()
  }
}

async function jumpToLevel(idx) {
  if (idx === currentLevel.value) return
  currentLevel.value = idx
  await loadRegions(parentIdForLevel(idx))
}

function applyBreadcrumbsToForm() {
  const ids = [0, 0, 0, 0]
  const names = ['', '', '', '']
  const keys = ['province', 'city', 'district', 'street']

  breadcrumbs.value.forEach((bc, idx) => {
    ids[idx] = bc.id
    names[idx] = bc.name
  })
  for (let i = breadcrumbs.value.length; i < 4; i++) {
    ids[i] = 0
    names[i] = ''
  }

  form.province_id = ids[0]
  form.city_id = ids[1]
  form.district_id = ids[2]
  form.street_id = ids[3]
  keys.forEach((k, i) => { regionNames[k] = names[i] })
}

function closePicker() {
  pickerVisible.value = false
  setTimeout(() => { showPicker.value = false }, 300)
}

// --- Page lifecycle ---
onLoad(async (query) => {
  if (query?.id) {
    isEdit.value = true
    addressId.value = query.id
    pageLoading.value = true
    try {
      const data = await getAddressInfo(query.id)
      const info = data?.data ?? data ?? {}
      fillForm(info)
    } catch {
      uni.showToast({ title: '加载失败', icon: 'none' })
    } finally {
      pageLoading.value = false
    }
  }
})

function fillForm(info) {
  form.receiver_name = info.receiver_name || ''
  form.receiver_mobile = info.receiver_mobile || ''
  form.province_id = info.province_id || 0
  form.city_id = info.city_id || 0
  form.district_id = info.district_id || 0
  form.street_id = info.street_id || 0
  form.address_detail = info.address_detail || ''
  form.tag = info.tag || ''
  form.is_default = info.is_default || 0

  regionNames.province = info.province_name || ''
  regionNames.city = info.city_name || ''
  regionNames.district = info.district_name || ''
  regionNames.street = info.street_name || ''

  breadcrumbs.value = buildBreadcrumbsFromForm()
}

// --- Validation ---
function validate() {
  if (!form.receiver_name.trim()) {
    uni.showToast({ title: '请输入收件人姓名', icon: 'none' })
    return false
  }
  if (!/^1[3-9]\d{9}$/.test(form.receiver_mobile)) {
    uni.showToast({ title: '请输入正确的手机号', icon: 'none' })
    return false
  }
  if (!form.province_id || !form.city_id || !form.district_id || !form.street_id) {
    uni.showToast({ title: '请选择完整的地区', icon: 'none' })
    return false
  }
  if (!form.address_detail.trim()) {
    uni.showToast({ title: '请输入详细地址', icon: 'none' })
    return false
  }
  return true
}

async function handleSave() {
  if (saving.value) return
  if (!validate()) return

  saving.value = true
  const payload = {
    receiver_name: form.receiver_name.trim(),
    receiver_mobile: form.receiver_mobile,
    province_id: form.province_id,
    city_id: form.city_id,
    district_id: form.district_id,
    street_id: form.street_id,
    address_detail: form.address_detail.trim(),
    tag: form.tag,
    is_default: form.is_default,
  }

  try {
    if (isEdit.value) {
      await updateAddress(addressId.value, payload)
    } else {
      await createAddress(payload)
    }
    uni.showToast({ title: '保存成功', icon: 'success' })
    setTimeout(() => uni.navigateBack(), 800)
  } catch {
    uni.showToast({ title: '保存失败，请重试', icon: 'none' })
  } finally {
    saving.value = false
  }
}

function confirmDelete() {
  uni.showModal({
    title: '确认删除',
    content: '删除后无法恢复，确定删除该地址？',
    confirmColor: '#ba1a1a',
    success: async (res) => {
      if (!res.confirm) return
      try {
        await deleteAddress(addressId.value)
        uni.showToast({ title: '已删除', icon: 'success' })
        setTimeout(() => uni.navigateBack(), 800)
      } catch {
        uni.showToast({ title: '删除失败', icon: 'none' })
      }
    },
  })
}
</script>

<style lang="scss" scoped>
.addr-edit { min-height: 100vh; background: $mb-color-bg-secondary; }
.addr-edit__loading { padding: $mb-spacing-lg; }
.addr-edit__body { padding: 0 $mb-spacing-page; }

// Section header
.addr-edit__section-header { padding: $mb-spacing-xl 0 $mb-spacing-lg; }
.addr-edit__section-title {
  display: block; font-size: $mb-font-xxl; font-weight: 800;
  color: $mb-color-text-title; letter-spacing: 0.02em;
}
.addr-edit__section-desc {
  display: block; margin-top: $mb-spacing-xs; font-size: $mb-font-sm;
  color: $mb-color-text-tertiary; letter-spacing: 0.01em;
}

// Form group
.form-group {
  background: $mb-color-bg; border-radius: $mb-radius-lg;
  padding: $mb-spacing-sm $mb-spacing-lg $mb-spacing-md;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.form-field { padding-top: $mb-spacing-md; }
.form-field__label {
  display: block; font-size: $mb-font-sm; color: $mb-color-text-tertiary;
  margin-bottom: $mb-spacing-xs; letter-spacing: 0.03em;
}
.form-field__row { display: flex; align-items: center; min-height: 72rpx; }
.form-field__row--tap { cursor: pointer; }
.form-field__input { flex: 1; font-size: $mb-font-md; color: $mb-color-text; min-height: 48rpx; }
.form-placeholder { color: $mb-color-text-tertiary; }
.form-field__value {
  flex: 1; font-size: $mb-font-md; color: $mb-color-text;
  overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
}
.form-field__placeholder-text { flex: 1; font-size: $mb-font-md; color: $mb-color-text-tertiary; }
.form-field__line { height: 1rpx; background: $mb-color-divider; margin-top: $mb-spacing-sm; }
.form-field:last-child .form-field__line { display: none; }

// Field icons (pure CSS)
.form-field__icon {
  flex-shrink: 0; width: 40rpx; height: 40rpx;
  display: flex; align-items: center; justify-content: center;
  margin-left: $mb-spacing-sm; position: relative;
}
.form-field__icon--pen {
  &::before {
    content: ''; position: absolute; width: 6rpx; height: 20rpx;
    background: $mb-color-text-tertiary; border-radius: 2rpx;
    transform: rotate(-45deg); top: 4rpx; right: 12rpx;
  }
  &::after {
    content: ''; position: absolute; width: 0; height: 0;
    border-left: 4rpx solid transparent; border-right: 4rpx solid transparent;
    border-top: 7rpx solid $mb-color-text-tertiary;
    transform: rotate(-45deg); bottom: 6rpx; right: 10rpx;
  }
}
.form-field__icon--phone {
  &::before {
    content: ''; position: absolute; width: 16rpx; height: 26rpx;
    border: 3rpx solid $mb-color-text-tertiary; border-radius: 4rpx;
    top: 50%; left: 50%; transform: translate(-50%, -50%);
  }
  &::after {
    content: ''; position: absolute; width: 6rpx; height: 2rpx;
    background: $mb-color-text-tertiary; border-radius: 1rpx;
    bottom: 9rpx; left: 50%; transform: translateX(-50%);
  }
}
.form-field__icon--arrow::before {
  content: ''; position: absolute; width: 12rpx; height: 12rpx;
  border-right: 3rpx solid $mb-color-text-tertiary;
  border-bottom: 3rpx solid $mb-color-text-tertiary;
  transform: rotate(-45deg); top: 50%; left: 50%;
  margin-top: -6rpx; margin-left: -8rpx;
}

// Default address toggle
.default-toggle {
  display: flex; align-items: center; justify-content: space-between;
  background: $mb-color-bg; border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg; margin-top: $mb-spacing-lg;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.default-toggle__left { flex: 1; min-width: 0; }
.default-toggle__title { display: block; font-size: $mb-font-md; font-weight: 600; color: $mb-color-text-title; }
.default-toggle__desc { display: block; margin-top: 4rpx; font-size: $mb-font-sm; color: $mb-color-text-tertiary; }

// Switch
.form-switch {
  flex-shrink: 0; width: 88rpx; height: 48rpx; border-radius: 24rpx;
  background: $mb-color-border; position: relative;
  transition: background 0.2s; margin-left: $mb-spacing-md;
}
.form-switch--on { background: $mb-color-text-title; }
.form-switch__thumb {
  position: absolute; top: 4rpx; left: 4rpx; width: 40rpx; height: 40rpx;
  border-radius: 50%; background: $mb-color-bg;
  box-shadow: 0 2rpx 8rpx rgba(0, 0, 0, 0.15); transition: transform 0.2s;
  .form-switch--on & { transform: translateX(40rpx); }
}

// Footer
.addr-edit__spacer { height: 200rpx; }
.addr-edit__footer {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 100;
  background: $mb-color-bg; display: flex; gap: $mb-spacing-md;
  padding: $mb-spacing-md $mb-spacing-page;
  padding-bottom: calc(#{$mb-spacing-md} + env(safe-area-inset-bottom));
}
.addr-edit__delete-btn {
  height: 96rpx; flex: 1; border-radius: $mb-radius-full;
  background: $mb-color-bg-secondary; border: 2rpx solid $mb-color-border;
  display: flex; align-items: center; justify-content: center;
  &:active { opacity: 0.85; }
}
.addr-edit__delete-text { font-size: $mb-font-md; font-weight: 600; color: $mb-color-error; }
.addr-edit__save-btn {
  height: 96rpx; flex: 2; border-radius: $mb-radius-full;
  background: $mb-color-text-title; display: flex; align-items: center; justify-content: center;
  &:active { opacity: 0.85; transform: scale(0.98); }
}
.addr-edit__save-btn--full { flex: 1; }
.addr-edit__save-text { font-size: $mb-font-md; font-weight: 600; color: $mb-color-text-inverse; letter-spacing: 0.08em; }

// Region picker
.region-mask {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(0, 0, 0, 0.45); display: flex; align-items: flex-end;
}
.region-panel {
  width: 100%; max-height: 70vh; background: $mb-color-bg;
  border-radius: $mb-radius-xl $mb-radius-xl 0 0;
  display: flex; flex-direction: column;
  transform: translateY(100%); transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.region-panel--show { transform: translateY(0); }
.region-panel__header {
  display: flex; align-items: center; justify-content: space-between;
  padding: $mb-spacing-lg; flex-shrink: 0;
}
.region-panel__title { font-size: $mb-font-lg; font-weight: 700; color: $mb-color-text-title; }
.region-panel__close { width: 48rpx; height: 48rpx; display: flex; align-items: center; justify-content: center; }
.region-panel__close-icon { font-size: $mb-font-md; color: $mb-color-text-tertiary; }

// Region tabs
.region-tabs {
  display: flex; gap: $mb-spacing-lg; padding: 0 $mb-spacing-lg $mb-spacing-md;
  border-bottom: 1rpx solid $mb-color-divider; flex-shrink: 0;
}
.region-tab { padding-bottom: $mb-spacing-sm; position: relative; }
.region-tab--active::after {
  content: ''; position: absolute; bottom: 0; left: 0; right: 0;
  height: 4rpx; background: $mb-color-text-title; border-radius: 2rpx;
}
.region-tab__text {
  font-size: $mb-font-md; color: $mb-color-text-secondary; white-space: nowrap;
  .region-tab--active & { color: $mb-color-text-title; font-weight: 600; }
}

// Region list
.region-list { flex: 1; max-height: 50vh; padding: $mb-spacing-sm 0; }
.region-list__loading, .region-list__empty { padding: $mb-spacing-xl 0; text-align: center; }
.region-list__loading-text, .region-list__empty-text { font-size: $mb-font-md; color: $mb-color-text-tertiary; }
.region-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: $mb-spacing-md $mb-spacing-lg;
  &:active { background: $mb-color-bg-secondary; }
}
.region-item--selected .region-item__text { color: $mb-color-text-title; font-weight: 600; }
.region-item__text { font-size: $mb-font-md; color: $mb-color-text; }
.region-item__check { font-size: $mb-font-md; color: $mb-color-text-title; font-weight: 700; }
</style>
