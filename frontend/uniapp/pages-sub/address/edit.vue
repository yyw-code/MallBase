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

      <!-- 智能解析 -->
      <view class="smart-parse">
        <view class="smart-parse__header">
          <view class="smart-parse__icon">
            <view class="smart-parse__spark smart-parse__spark--main" />
            <view class="smart-parse__spark smart-parse__spark--sub" />
          </view>
          <view class="smart-parse__copy">
            <text class="smart-parse__title">智能解析</text>
            <text class="smart-parse__desc">粘贴姓名、手机号和地址，自动填入表单</text>
          </view>
        </view>
        <textarea
          v-model="smartText"
          class="smart-parse__textarea"
          :focus="smartFocus"
          :maxlength="300"
          auto-height
          placeholder="例如：张三 13800138000 浙江省杭州市西湖区文三路 138 号"
          placeholder-class="smart-parse__placeholder"
        />
        <mb-button
          class="smart-parse__button"
          type="secondary"
          size="medium"
          block
          :loading="parsing"
          label="解析并填入"
          @click="handleSmartParse"
        />
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
            <view class="form-field__icon form-field__icon--user" />
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
            <view class="form-field__icon form-field__icon--home" />
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
      <mb-button
        v-if="isEdit"
        class="addr-edit__delete-btn"
        type="danger"
        size="large"
        label="删除地址"
        @click="confirmDelete"
      />
      <mb-button
        class="addr-edit__save-btn"
        :class="{ 'addr-edit__save-btn--full': !isEdit }"
        type="primary"
        size="large"
        :loading="saving"
        :label="saving ? '保存中...' : '保存并使用'"
        @click="handleSave"
      />
    </view>

    <!-- Region picker popup -->
    <view v-if="showPicker" class="region-mask" @tap.self="closePicker">
      <view class="region-panel" :class="{ 'region-panel--show': pickerVisible }">
        <view class="region-panel__header">
          <text class="region-panel__title">选择地区</text>
          <view class="region-panel__close" @tap="closePicker">
            <view class="region-panel__close-icon" />
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
            <view v-if="isRegionSelected(r.id)" class="region-item__check" />
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
const parsing = ref(false)
const smartText = ref('')
const smartFocus = ref(false)
const regionCache = new Map()

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

function findMobileInfo(value) {
  const matched = String(value || '').match(/1[3-9]\d[\s-]?\d{4}[\s-]?\d{4}/)
  if (!matched) return { mobile: '', raw: '' }
  return {
    mobile: matched[0].replace(/\D/g, ''),
    raw: matched[0],
  }
}

function cleanSmartText(value) {
  return String(value || '')
    .replace(/\r/g, '\n')
    .replace(/[，,；;|]/g, ' ')
    .replace(/[：:]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

function stripSmartLabels(value) {
  return cleanSmartText(value)
    .replace(/(收货地址|详细地址|联系电话|手机号码|收货人|收件人|联系人|手机号|电话|手机|姓名|地址)\s*/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

function splitSmartTokens(value) {
  return String(value || '')
    .replace(/[，,；;|/\\\n\r]/g, ' ')
    .split(/\s+/)
    .map((item) => item.trim())
    .filter(Boolean)
}

function normalizeNameCandidate(value) {
  const cleaned = String(value || '').replace(/[^\u4e00-\u9fa5A-Za-z·]/g, '')
  if (cleaned.length < 2 || cleaned.length > 8) return ''
  if (/[省市区县镇乡街道路号室楼栋单元村苑园座幢弄巷]/.test(cleaned)) return ''
  return cleaned
}

function extractReceiverName(value, mobileRaw) {
  const source = String(value || '')
  const beforeMobile = mobileRaw ? source.split(mobileRaw)[0] : source
  const beforeTokens = splitSmartTokens(beforeMobile)

  for (let i = beforeTokens.length - 1; i >= 0; i--) {
    const name = normalizeNameCandidate(beforeTokens[i])
    if (name) return name
  }

  const tokens = splitSmartTokens(source)
  for (const token of tokens) {
    const name = normalizeNameCandidate(token)
    if (name) return name
  }

  return ''
}

function removeFirst(value, chunk) {
  if (!chunk) return value
  return String(value || '').replace(chunk, ' ')
}

function shortRegionName(name) {
  const value = String(name || '').trim()
  const suffixes = ['维吾尔自治区', '壮族自治区', '回族自治区', '特别行政区', '自治区', '自治州', '地区', '街道', '省', '市', '区', '县', '镇', '乡', '村']
  const suffix = suffixes.find((item) => value.endsWith(item) && value.length > item.length + 1)
  return suffix ? value.slice(0, -suffix.length) : value
}

function regionNameTokens(name) {
  const full = String(name || '').trim()
  const short = shortRegionName(full)
  return [full, short]
    .filter((item, index, arr) => item && arr.indexOf(item) === index)
    .sort((a, b) => b.length - a.length)
}

function matchRegionOption(options, text) {
  let best = null

  options.forEach((option) => {
    regionNameTokens(option.name).forEach((token) => {
      const index = text.indexOf(token)
      if (index < 0) return

      const score = token.length
      if (!best || index < best.index || (index === best.index && score > best.score)) {
        best = {
          option,
          index,
          score,
          endIndex: index + token.length,
        }
      }
    })
  })

  return best
}

async function loadRegionOptions(parentId = 0) {
  const key = String(parentId || 0)
  if (regionCache.has(key)) return regionCache.get(key)

  const data = await getRegionChildren(parentId || 0)
  const list = Array.isArray(data?.list) ? data.list : (Array.isArray(data) ? data : [])
  regionCache.set(key, list)
  return list
}

async function resolveRegionFromText(text) {
  const breadcrumbsResult = []
  let parentId = 0
  let endIndex = 0

  for (let level = 0; level < 4; level++) {
    const options = await loadRegionOptions(parentId)
    const matched = matchRegionOption(options, text)
    if (!matched) break

    breadcrumbsResult.push({
      id: matched.option.id,
      name: matched.option.name,
    })
    parentId = matched.option.id
    endIndex = Math.max(endIndex, matched.endIndex)
  }

  return { breadcrumbs: breadcrumbsResult, endIndex }
}

function buildSmartDetail(addressText, regionEndIndex) {
  const source = String(addressText || '')
  const detailSource = regionEndIndex > 0 ? source.slice(regionEndIndex) : source
  return cleanSmartText(detailSource).replace(/^[\s，,；;：:-]+/, '')
}

async function parseSmartAddress(value) {
  const mobileInfo = findMobileInfo(value)
  const readable = stripSmartLabels(value)
  const withoutMobile = mobileInfo.raw ? readable.replace(mobileInfo.raw, ' ') : readable
  const receiverName = extractReceiverName(readable, mobileInfo.raw)
  const addressText = cleanSmartText(removeFirst(withoutMobile, receiverName))
  const regionResult = addressText
    ? await resolveRegionFromText(addressText)
    : { breadcrumbs: [], endIndex: 0 }

  return {
    mobile: mobileInfo.mobile,
    receiverName,
    breadcrumbs: regionResult.breadcrumbs,
    addressDetail: buildSmartDetail(addressText, regionResult.endIndex),
  }
}

async function handleSmartParse() {
  if (parsing.value) return

  if (!String(smartText.value || '').trim()) {
    uni.showToast({ title: '请先粘贴收货信息', icon: 'none' })
    return
  }

  parsing.value = true
  try {
    const result = await parseSmartAddress(smartText.value)
    let filledCount = 0

    if (result.receiverName) {
      form.receiver_name = result.receiverName
      filledCount++
    }
    if (result.mobile) {
      form.receiver_mobile = result.mobile
      filledCount++
    }
    if (result.breadcrumbs.length > 0) {
      breadcrumbs.value = result.breadcrumbs
      applyBreadcrumbsToForm()
      currentLevel.value = Math.min(result.breadcrumbs.length, 3)
      filledCount++
    }
    if (result.addressDetail) {
      form.address_detail = result.addressDetail
      filledCount++
    }

    if (!filledCount) {
      uni.showToast({ title: '未识别到有效信息', icon: 'none' })
      return
    }

    const title = result.breadcrumbs.length >= 4
      ? '已解析并填入'
      : '已解析，请补全地区'
    uni.showToast({ title, icon: 'none' })
  } catch {
    uni.showToast({ title: '解析失败，请手动填写', icon: 'none' })
  } finally {
    parsing.value = false
    smartFocus.value = false
  }
}

// --- Page lifecycle ---
onLoad(async (query) => {
  if (query?.smart === '1') {
    nextTick(() => { smartFocus.value = true })
  }

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
  color: $mb-color-text-title; letter-spacing: 0;
}
.addr-edit__section-desc {
  display: block; margin-top: $mb-spacing-xs; font-size: $mb-font-sm;
  color: $mb-color-text-tertiary; letter-spacing: 0;
}

// Smart parser
.smart-parse {
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg;
  border: 1rpx solid rgba($mb-color-primary, 0.14);
  margin-bottom: $mb-spacing-lg;
}

.smart-parse__header {
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
  margin-bottom: $mb-spacing-md;
}

.smart-parse__icon {
  flex-shrink: 0;
  width: 58rpx;
  height: 58rpx;
  border-radius: 18rpx;
  background: rgba($mb-color-primary, 0.08);
  position: relative;
}

.smart-parse__spark {
  position: absolute;

  &::before,
  &::after {
    content: '';
    position: absolute;
    left: 50%;
    top: 50%;
    border-radius: 999rpx;
    background: $mb-color-primary;
    transform: translate(-50%, -50%);
  }

  &::before {
    width: 4rpx;
    height: 28rpx;
  }

  &::after {
    width: 28rpx;
    height: 4rpx;
  }
}

.smart-parse__spark--main {
  left: 17rpx;
  top: 14rpx;
  width: 28rpx;
  height: 28rpx;
  transform: rotate(45deg);
}

.smart-parse__spark--sub {
  right: 11rpx;
  bottom: 10rpx;
  width: 16rpx;
  height: 16rpx;
  transform: rotate(45deg);

  &::before {
    height: 16rpx;
  }

  &::after {
    width: 16rpx;
  }
}

.smart-parse__copy {
  flex: 1;
  min-width: 0;
}

.smart-parse__title {
  display: block;
  font-size: $mb-font-lg;
  font-weight: 700;
  color: $mb-color-text-title;
  letter-spacing: 0;
}

.smart-parse__desc {
  display: block;
  margin-top: 4rpx;
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  line-height: 1.4;
}

.smart-parse__textarea {
  width: 100%;
  min-height: 128rpx;
  box-sizing: border-box;
  padding: $mb-spacing-md;
  border-radius: $mb-radius-md;
  background: $mb-color-bg-secondary;
  color: $mb-color-text;
  font-size: $mb-font-md;
  line-height: 1.5;
}

.smart-parse__placeholder {
  color: $mb-color-text-tertiary;
}

.smart-parse__button {
  margin-top: $mb-spacing-md;
}

// Form group
.form-group {
  background: $mb-color-bg; border-radius: $mb-radius-lg;
  padding: $mb-spacing-sm $mb-spacing-lg $mb-spacing-md;
  border: 1rpx solid $mb-color-border;
}
.form-field { padding-top: $mb-spacing-md; }
.form-field__label {
  display: block; font-size: $mb-font-sm; color: $mb-color-text-tertiary;
  margin-bottom: $mb-spacing-xs; letter-spacing: 0;
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
.form-field__icon--user {
  &::before {
    content: ''; position: absolute; width: 16rpx; height: 16rpx;
    border: 3rpx solid $mb-color-text-tertiary; border-radius: 50%;
    top: 5rpx; left: 50%; transform: translateX(-50%);
  }
  &::after {
    content: ''; position: absolute; width: 24rpx; height: 12rpx;
    border: 3rpx solid $mb-color-text-tertiary; border-top: 0;
    border-radius: 0 0 16rpx 16rpx;
    left: 50%; bottom: 5rpx; transform: translateX(-50%);
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
.form-field__icon--home {
  &::before {
    content: ''; position: absolute; width: 22rpx; height: 18rpx;
    border: 3rpx solid $mb-color-text-tertiary; border-top: 0;
    border-radius: 0 0 4rpx 4rpx;
    left: 50%; bottom: 6rpx; transform: translateX(-50%);
  }
  &::after {
    content: ''; position: absolute; width: 18rpx; height: 18rpx;
    border-left: 3rpx solid $mb-color-text-tertiary;
    border-top: 3rpx solid $mb-color-text-tertiary;
    top: 7rpx; left: 50%; transform: translateX(-50%) rotate(45deg);
  }
}

// Default address toggle
.default-toggle {
  display: flex; align-items: center; justify-content: space-between;
  background: $mb-color-bg; border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg; margin-top: $mb-spacing-lg;
  border: 1rpx solid $mb-color-border;
}
.default-toggle__left { flex: 1; min-width: 0; }
.default-toggle__title { display: block; font-size: $mb-font-md; font-weight: 600; color: $mb-color-text-title; }
.default-toggle__desc { display: block; margin-top: 4rpx; font-size: $mb-font-sm; color: $mb-color-text-tertiary; }

// Switch
.form-switch {
  flex-shrink: 0; width: 88rpx; height: 48rpx; border-radius: $mb-radius-full;
  background: $mb-color-border; position: relative;
  transition: background 0.2s; margin-left: $mb-spacing-md;
}
.form-switch--on { background: $mb-color-primary; }
.form-switch__thumb {
  position: absolute; top: 4rpx; left: 4rpx; width: 40rpx; height: 40rpx;
  border-radius: 50%; background: $mb-color-bg;
  border: 1rpx solid $mb-color-border;
  transition: transform 0.2s;
  .form-switch--on & { transform: translateX(40rpx); }
}

// Footer
.addr-edit__spacer { height: 200rpx; }
.addr-edit__footer {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 100;
  background: $mb-color-bg; display: flex; gap: $mb-spacing-md;
  padding: $mb-spacing-md $mb-spacing-page;
  padding-bottom: calc(#{$mb-spacing-md} + env(safe-area-inset-bottom));
  box-shadow: $mb-shadow-bar;
}
.addr-edit__delete-btn {
  flex: 1;
}
.addr-edit__save-btn {
  flex: 2;
}
.addr-edit__save-btn--full { flex: 1; }

// Region picker
.region-mask {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(0, 0, 0, 0.45); display: flex; align-items: flex-end;
}
.region-panel {
  width: 100%; max-height: 70vh; background: $mb-color-bg;
  border-radius: $mb-radius-lg $mb-radius-lg 0 0;
  display: flex; flex-direction: column;
  transform: translateY(100%); transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.region-panel--show { transform: translateY(0); }
.region-panel__header {
  display: flex; align-items: center; justify-content: space-between;
  padding: $mb-spacing-lg; flex-shrink: 0;
}
.region-panel__title { font-size: $mb-font-lg; font-weight: 700; color: $mb-color-text-title; }
.region-panel__close {
  width: 56rpx; height: 56rpx; border-radius: 50%;
  background: $mb-color-bg-secondary;
  display: flex; align-items: center; justify-content: center;
}
.region-panel__close-icon {
  position: relative;
  width: 24rpx;
  height: 24rpx;

  &::before,
  &::after {
    content: '';
    position: absolute;
    left: 50%;
    top: 50%;
    width: 24rpx;
    height: 3rpx;
    border-radius: 999rpx;
    background: $mb-color-text-tertiary;
  }

  &::before {
    transform: translate(-50%, -50%) rotate(45deg);
  }

  &::after {
    transform: translate(-50%, -50%) rotate(-45deg);
  }
}

// Region tabs
.region-tabs {
  display: flex; gap: $mb-spacing-lg; padding: 0 $mb-spacing-lg $mb-spacing-md;
  border-bottom: 1rpx solid $mb-color-divider; flex-shrink: 0;
}
.region-tab { padding-bottom: $mb-spacing-sm; position: relative; }
.region-tab--active::after {
  content: ''; position: absolute; bottom: 0; left: 0; right: 0;
  height: 4rpx; background: $mb-color-primary; border-radius: 2rpx;
}
.region-tab__text {
  font-size: $mb-font-md; color: $mb-color-text-secondary; white-space: nowrap;
  .region-tab--active & { color: $mb-color-primary; font-weight: 600; }
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
.region-item--selected .region-item__text { color: $mb-color-primary; font-weight: 600; }
.region-item__text { font-size: $mb-font-md; color: $mb-color-text; }
.region-item__check {
  width: 22rpx;
  height: 12rpx;
  border-left: 4rpx solid $mb-color-primary;
  border-bottom: 4rpx solid $mb-color-primary;
  transform: rotate(-45deg);
}
</style>
