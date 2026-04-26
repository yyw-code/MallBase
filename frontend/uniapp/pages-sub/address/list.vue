<template>
  <view class="addr-list">
    <mb-navbar :title="selectMode ? '选择地址' : '收货地址'" />

    <!-- Section header -->
    <view class="addr-list__header">
      <text class="addr-list__title">收货地址</text>
      <text class="addr-list__subtitle">管理您的默认配送信息</text>
    </view>

    <view v-if="loading" class="addr-list__loading">
      <mb-skeleton type="card" />
      <mb-skeleton type="card" />
    </view>

    <mb-empty-state
      v-else-if="list.length === 0"
      icon="📮"
      text="还没有收货地址"
      action-text="新增地址"
      @action="goAdd"
    />

    <scroll-view v-else scroll-y class="addr-list__scroll">
      <view
        v-for="item in list"
        :key="item.id"
        class="addr-card"
        @tap="onTapCard(item)"
      >
        <view class="addr-card__body">
          <view class="addr-card__info">
            <view class="addr-card__header">
              <text class="addr-card__name">{{ item.receiver_name }}</text>
              <text class="addr-card__phone">{{ item.receiver_mobile }}</text>
              <view v-if="item.is_default" class="addr-card__badge">
                <text class="addr-card__badge-text">默认</text>
              </view>
            </view>

            <text class="addr-card__detail">
              {{ regionText(item) }}
            </text>
            <text v-if="item.region_status === 0" class="addr-card__warn">
              {{ item.region_invalid_reason || '地区已失效，请编辑更新' }}
            </text>
          </view>

          <view class="addr-card__edit" @tap.stop="goEdit(item.id)">
            <view class="addr-card__edit-icon" />
          </view>
        </view>
      </view>

      <view class="addr-list__bottom-spacer" />
    </scroll-view>

    <view class="addr-list__footer">
      <view class="addr-list__add-btn" @tap="goAdd">
        <text class="addr-list__add-btn-text">新增收货地址</text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { getAddressList } from '@/api/user/address'

const selectMode = ref(false)
const loading = ref(true)
const list = ref([])

onLoad((query) => {
  selectMode.value = query?.select === '1'
})

onShow(() => {
  fetchList()
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getAddressList()
    list.value = Array.isArray(data?.list) ? data.list : (Array.isArray(data) ? data : [])
  } catch {
    list.value = []
  } finally {
    loading.value = false
  }
}

function regionText(item) {
  if (item.region_path_text) return `${item.region_path_text} ${item.address_detail}`
  return [item.province_name, item.city_name, item.district_name, item.street_name, item.address_detail]
    .filter(Boolean)
    .join(' ')
}

function onTapCard(item) {
  if (selectMode.value) {
    if (item.region_status === 0) {
      uni.showToast({ title: '该地址地区已失效，请编辑后使用', icon: 'none' })
      return
    }
    uni.setStorageSync('selected_address', item)
    uni.navigateBack()
    return
  }
  goEdit(item.id)
}

function goAdd() {
  uni.navigateTo({ url: '/pages-sub/address/edit' })
}

function goEdit(id) {
  uni.navigateTo({ url: `/pages-sub/address/edit?id=${id}` })
}

</script>

<style lang="scss" scoped>
.addr-list {
  min-height: 100vh;
  background: $mb-color-bg-secondary;
}

.addr-list__loading {
  padding: $mb-spacing-lg;
  display: flex;
  flex-direction: column;
  gap: $mb-spacing-md;
}

// ---- Section header ----
.addr-list__header {
  padding: $mb-spacing-xl $mb-spacing-page $mb-spacing-md;
}

.addr-list__title {
  display: block;
  font-size: $mb-font-xxl;
  font-weight: 800;
  color: $mb-color-text-title;
  letter-spacing: 0.02em;
}

.addr-list__subtitle {
  display: block;
  margin-top: $mb-spacing-xs;
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  letter-spacing: 0.01em;
}

// ---- Scroll area ----
.addr-list__scroll {
  height: 100vh;
  padding: $mb-spacing-sm $mb-spacing-page;
}

// ---- Address card ----
.addr-card {
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg;
  margin-bottom: $mb-spacing-md;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
}

.addr-card__body {
  display: flex;
  align-items: flex-start;
}

.addr-card__info {
  flex: 1;
  min-width: 0;
}

.addr-card__header {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
  margin-bottom: $mb-spacing-sm;
  flex-wrap: wrap;
}

.addr-card__name {
  font-size: $mb-font-lg;
  font-weight: 700;
  color: $mb-color-text-title;
}

.addr-card__phone {
  font-size: $mb-font-md;
  color: $mb-color-text;
  letter-spacing: 0.03em;
}

.addr-card__badge {
  background: $mb-color-text-title;
  padding: 4rpx 16rpx;
  border-radius: $mb-radius-sm;
  margin-left: $mb-spacing-xs;
}

.addr-card__badge-text {
  font-size: $mb-font-xs;
  color: $mb-color-text-inverse;
  font-weight: 600;
}

.addr-card__detail {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  line-height: 1.6;
}

.addr-card__warn {
  display: block;
  margin-top: $mb-spacing-xs;
  font-size: $mb-font-sm;
  color: $mb-color-error;
}

// ---- Edit button (pencil icon) ----
.addr-card__edit {
  flex-shrink: 0;
  width: 72rpx;
  height: 72rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-left: $mb-spacing-sm;
}

.addr-card__edit-icon {
  position: relative;
  width: 32rpx;
  height: 32rpx;

  // Pencil body (diagonal bar)
  &::before {
    content: '';
    position: absolute;
    width: 8rpx;
    height: 24rpx;
    background: $mb-color-text-secondary;
    border-radius: 2rpx;
    top: 0;
    left: 50%;
    transform-origin: center;
    transform: translateX(-50%) rotate(-45deg);
    top: 2rpx;
    left: 14rpx;
  }

  // Pencil tip
  &::after {
    content: '';
    position: absolute;
    width: 0;
    height: 0;
    border-left: 4rpx solid transparent;
    border-right: 4rpx solid transparent;
    border-top: 8rpx solid $mb-color-text-secondary;
    bottom: 2rpx;
    left: 6rpx;
    transform: rotate(-45deg);
  }
}

// ---- Footer ----
.addr-list__bottom-spacer {
  height: 180rpx;
}

.addr-list__footer {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 100;
  background: $mb-color-bg;
  padding: $mb-spacing-md $mb-spacing-page;
  padding-bottom: calc(#{$mb-spacing-md} + env(safe-area-inset-bottom));
}

.addr-list__add-btn {
  height: 96rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-text-title;
  display: flex;
  align-items: center;
  justify-content: center;

  &:active {
    opacity: 0.85;
    transform: scale(0.98);
  }
}

.addr-list__add-btn-text {
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text-inverse;
  letter-spacing: 0.08em;
}
</style>
