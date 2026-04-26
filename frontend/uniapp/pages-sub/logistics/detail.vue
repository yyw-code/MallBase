<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getLogisticsDetail } from '@/api/order/logistics'

// ---------- state ----------
const orderId = ref('')
const loading = ref(true)
const logistics = ref(null)

// ---------- mock data ----------
const MOCK_DATA = {
  status: '运输中',
  company: '顺丰速运',
  tracking_no: 'SF1234567890123',
  receiver: {
    name: '张三',
    phone: '13812345678',
    address: '浙江省杭州市西湖区文三路 138 号',
  },
  tracks: [
    { content: '快件已到达杭州西湖区网点，派件员正在派送中', time: '2025-04-27 14:30:00' },
    { content: '快件已到达杭州转运中心', time: '2025-04-27 08:15:00' },
    { content: '快件已从深圳转运中心发出', time: '2025-04-26 22:00:00' },
    { content: '快件已到达深圳转运中心', time: '2025-04-26 18:45:00' },
    { content: '商家已发货，快件揽收成功', time: '2025-04-26 15:20:00' },
  ],
}

onLoad((query) => {
  orderId.value = query?.order_id || ''
  fetchLogistics()
})

async function fetchLogistics() {
  loading.value = true
  try {
    const res = await getLogisticsDetail(orderId.value)
    if (res && res.tracks && res.tracks.length > 0) {
      logistics.value = res
    } else {
      logistics.value = MOCK_DATA
    }
  } catch {
    logistics.value = MOCK_DATA
  } finally {
    loading.value = false
  }
}

// ---------- computed ----------
const statusText = computed(() => logistics.value?.status || '')
const company = computed(() => logistics.value?.company || '')
const trackingNo = computed(() => logistics.value?.tracking_no || '')
const receiver = computed(() => logistics.value?.receiver || {})
const tracks = computed(() => logistics.value?.tracks || [])

const maskedPhone = computed(() => {
  const phone = receiver.value?.phone || ''
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
  <view class="page">
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
    <template v-else-if="logistics">
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

      <!-- Map placeholder -->
      <view class="map-placeholder">
        <view class="map-placeholder__dot" />
        <text class="map-placeholder__text">物流地图</text>
      </view>

      <!-- Timeline section -->
      <view class="section">
        <text class="section__title">物流轨迹</text>
        <view class="timeline">
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
      </view>

      <!-- Bottom spacer -->
      <view class="bottom-spacer" />
    </template>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: $mb-color-bg;
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
  color: $mb-color-text-tertiary;
  margin-bottom: $mb-spacing-xs;
}

.status-header__status {
  display: block;
  font-size: $mb-font-xxl;
  font-weight: 700;
  color: $mb-color-primary;
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
  color: $mb-color-text-secondary;
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
  color: $mb-color-text;
  font-weight: 500;
}

.copy-btn {
  flex-shrink: 0;
  padding: 4rpx 16rpx;
  border: 1rpx solid $mb-color-primary;
  border-radius: $mb-radius-sm;

  &:active {
    opacity: 0.7;
  }
}

.copy-btn__text {
  font-size: $mb-font-xs;
  color: $mb-color-primary;
}

// ---- Card base ----
.card {
  background: $mb-color-bg-secondary;
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg;
  margin: 0 $mb-spacing-md $mb-spacing-md;
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
  color: $mb-color-text-title;
}

.address-card__phone {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
}

.address-card__detail {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  line-height: 1.5;
}

// ---- Map placeholder ----
.map-placeholder {
  height: 300rpx;
  margin: 0 $mb-spacing-md $mb-spacing-md;
  background: #eef4ff;
  border-radius: $mb-radius-lg;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $mb-spacing-sm;
}

.map-placeholder__dot {
  width: 20rpx;
  height: 20rpx;
  border-radius: 50%;
  background: $mb-color-primary;
  box-shadow: 0 0 0 8rpx rgba($mb-color-primary, 0.2);
}

.map-placeholder__text {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
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
  color: $mb-color-text-title;
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
  background: $mb-color-border;
  flex-shrink: 0;

  &--active {
    width: 16rpx;
    height: 16rpx;
    background: $mb-color-primary;
    box-shadow: 0 0 0 6rpx rgba($mb-color-primary, 0.15);
  }
}

.timeline__line {
  width: 2rpx;
  flex: 1;
  background: $mb-color-divider;
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
  color: $mb-color-text-tertiary;
  line-height: 1.5;

  &--active {
    color: $mb-color-text;
    font-weight: 600;
  }
}

.timeline__time {
  display: block;
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  margin-top: 8rpx;
}

// ---- Bottom spacer ----
.bottom-spacer {
  height: calc(40rpx + env(safe-area-inset-bottom));
}
</style>
