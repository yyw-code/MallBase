<script setup>
import { useDecorateStore } from '@/store/decorate'
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getLogisticsDetail } from '@/api/order/logistics'
const decorateStore = useDecorateStore()

// ---------- state ----------
const orderId = ref('')
const loading = ref(true)
const logistics = ref(null)
const errorText = ref('')

onLoad((query) => {
  orderId.value = query?.order_id || ''
  fetchLogistics()
})

async function fetchLogistics() {
  loading.value = true
  errorText.value = ''
  try {
    const res = await getLogisticsDetail(orderId.value)
    logistics.value = res || null
  } catch (error) {
    logistics.value = null
    errorText.value = error?.message || '物流信息加载失败'
  } finally {
    loading.value = false
  }
}

// ---------- computed ----------
const statusText = computed(() => logistics.value?.status || '暂无物流')
const company = computed(() => logistics.value?.company || '')
const trackingNo = computed(() => logistics.value?.tracking_no || '')
const receiver = computed(() => logistics.value?.receiver || {})
const tracks = computed(() => logistics.value?.tracks || [])
const queryError = computed(() => logistics.value?.query_error || '')
const isAvailable = computed(() => logistics.value && logistics.value.available !== false)
const hasTracks = computed(() => tracks.value.length > 0)
const emptyText = computed(() => errorText.value || logistics.value?.status || '暂无物流信息')
const trackEmptyText = computed(() => queryError.value || '暂无物流轨迹')

const maskedPhone = computed(() => {
  const phone = receiver.value?.phone_masked || receiver.value?.phone || ''
  if (phone.includes('*')) return phone
  if (phone.length < 7) return phone
  return phone.slice(0, 3) + '****' + phone.slice(-4)
})

// ---------- actions ----------
function copyTrackingNo() {
  if (!trackingNo.value) return
  uni.setClipboardData({
    data: trackingNo.value,
    success() {
      uni.showToast({ title: '已复制', icon: 'success' })
    },
  })
}

</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="物流详情" />

    <!-- Loading -->
    <view v-if="loading" class="loading-wrap">
      <view class="status-header status-header--skeleton" />
      <view class="card">
        <mb-skeleton type="lines" :count="3" />
      </view>
      <view class="card">
        <mb-skeleton type="lines" :count="5" />
      </view>
    </view>

    <!-- Main content -->
    <template v-else-if="isAvailable">
      <!-- Status header -->
      <view class="status-header">
        <text class="status-header__label">当前状态</text>
        <text class="status-header__status">{{ statusText }}</text>
        <view class="status-header__info">
          <view class="status-header__info-item">
            <text class="status-header__info-label">快递公司</text>
            <text class="status-header__info-value">{{ company }}</text>
          </view>
          <view class="status-header__info-item">
            <text class="status-header__info-label">运单号</text>
            <view class="status-header__info-row">
              <text class="status-header__info-value">{{ trackingNo }}</text>
              <view class="copy-btn" @tap="copyTrackingNo">
                <text class="copy-btn__text">复制</text>
              </view>
            </view>
          </view>
        </view>
      </view>

      <!-- Address card -->
      <view class="card address-card">
        <text class="address-card__icon">&#x1F4E6;</text>
        <view class="address-card__info">
          <view class="address-card__top">
            <text class="address-card__name">{{ receiver.name }}</text>
            <text class="address-card__phone">{{ maskedPhone }}</text>
          </view>
          <text class="address-card__detail">{{ receiver.address }}</text>
        </view>
      </view>

      <!-- Timeline section -->
      <view class="section">
        <text class="section__title">物流轨迹</text>
        <view v-if="hasTracks" class="timeline">
          <view
            v-for="(track, index) in tracks"
            :key="index"
            class="timeline__item"
            :class="{ 'timeline__item--active': index === 0 }"
          >
            <view class="timeline__rail">
              <view
                class="timeline__dot"
                :class="{ 'timeline__dot--active': index === 0 }"
              />
              <view
                v-if="index < tracks.length - 1"
                class="timeline__line"
              />
            </view>
            <view class="timeline__content">
              <text
                class="timeline__text"
                :class="{ 'timeline__text--active': index === 0 }"
              >{{ track.content }}</text>
              <text class="timeline__time">{{ track.time }}</text>
            </view>
          </view>
        </view>
        <mb-empty-state v-else :text="trackEmptyText" padding-top="80rpx" />
      </view>

      <!-- Bottom spacer -->
      <view class="bottom-spacer" />
    </template>

    <!-- Empty / error -->
    <mb-empty-state
      v-else
      :text="emptyText"
      action-text="重新加载"
      @action="fetchLogistics"
    />
      <mb-floating-action />
</view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

// ---- Loading ----
.loading-wrap {
  padding: 0 $mb-spacing-page;
}

// ---- Status header ----
.status-header {
  background: #eef4ff;
  padding: 40rpx $mb-spacing-page;
  margin-bottom: $mb-spacing-md;

  &--skeleton {
    height: 280rpx;
    margin-bottom: $mb-spacing-md;
  }
}

.status-header__label {
  display: block;
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  margin-bottom: $mb-spacing-xs;
}

.status-header__status {
  display: block;
  font-size: $mb-font-xxl;
  font-weight: 700;
  color: var(--color-primary, #0d50d5);
  margin-bottom: $mb-spacing-lg;
}

.status-header__info {
  display: flex;
  flex-direction: column;
  gap: $mb-spacing-sm;
}

.status-header__info-item {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
}

.status-header__info-label {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
  flex-shrink: 0;
}

.status-header__info-row {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
  flex: 1;
  min-width: 0;
}

.status-header__info-value {
  font-size: $mb-font-sm;
  color: var(--color-text, #191b23);
  font-weight: 500;
}

.copy-btn {
  flex-shrink: 0;
  padding: 4rpx 16rpx;
  border: 1rpx solid var(--color-primary, #0d50d5);
  border-radius: $mb-radius-sm;

  &:active {
    opacity: 0.7;
  }
}

.copy-btn__text {
  font-size: $mb-font-xs;
  color: var(--color-primary, #0d50d5);
}

// ---- Card base ----
.card {
  background: var(--color-bg, #ffffff);
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg;
  margin: 0 $mb-spacing-md $mb-spacing-md;
  border: 1rpx solid var(--color-border, #e0e4e8);
}

// ---- Address card ----
.address-card {
  display: flex;
  align-items: flex-start;
  gap: $mb-spacing-md;
}

.address-card__icon {
  font-size: 48rpx;
  flex-shrink: 0;
  line-height: 1.2;
}

.address-card__info {
  flex: 1;
  min-width: 0;
}

.address-card__top {
  display: flex;
  align-items: baseline;
  gap: $mb-spacing-sm;
  margin-bottom: 8rpx;
}

.address-card__name {
  font-size: $mb-font-lg;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
}

.address-card__phone {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
}

.address-card__detail {
  font-size: $mb-font-md;
  color: var(--color-text-secondary, #434654);
  line-height: 1.5;
}

// ---- Section ----
.section {
  padding: 0 $mb-spacing-page;
  margin-top: $mb-spacing-md;
}

.section__title {
  display: block;
  font-size: $mb-font-md;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
  margin-bottom: $mb-spacing-lg;
}

// ---- Timeline ----
.timeline {
  padding-left: 4rpx;
}

.timeline__item {
  display: flex;
  gap: 0;
  min-height: 120rpx;

  &:last-child {
    min-height: auto;

    .timeline__content {
      padding-bottom: 0;
    }
  }
}

.timeline__rail {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 24rpx;
  flex-shrink: 0;
  padding-top: 6rpx;
}

.timeline__dot {
  width: 12rpx;
  height: 12rpx;
  border-radius: 50%;
  background: var(--color-border, #e0e4e8);
  flex-shrink: 0;

  &--active {
    width: 16rpx;
    height: 16rpx;
    background: var(--color-primary, #0d50d5);
    border: 6rpx solid var(--color-primary-soft, rgba(13, 80, 213, 0.12));
    box-sizing: content-box;
  }
}

.timeline__line {
  width: 2rpx;
  flex: 1;
  background: var(--color-divider, #f0f2f5);
  margin-top: 8rpx;
}

.timeline__content {
  flex: 1;
  min-width: 0;
  padding-left: 24rpx;
  padding-bottom: $mb-spacing-lg;
}

.timeline__text {
  display: block;
  font-size: $mb-font-md;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.5;

  &--active {
    color: var(--color-text, #191b23);
    font-weight: 600;
  }
}

.timeline__time {
  display: block;
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  margin-top: 8rpx;
}

// ---- Bottom spacer ----
.bottom-spacer {
  height: calc(40rpx + env(safe-area-inset-bottom));
}
</style>
