<template>
  <view class="addr-list">
    <mb-navbar :title="selectMode ? '选择地址' : '收货地址'" />

    <!-- Section header -->
    <view class="addr-list__header">
      <text class="addr-list__title">收货地址</text>
      <text class="addr-list__subtitle">管理您的默认配送信息</text>
    </view>

    <scroll-view scroll-y class="addr-list__scroll">
      <view v-if="loading" class="addr-list__loading">
        <mb-skeleton type="card" />
        <mb-skeleton type="card" />
      </view>

      <mb-empty-state
        v-else-if="list.length === 0"
        icon=""
        text="还没有收货地址"
        action-text="新增地址"
        padding-top="180rpx"
        @action="goAdd"
      />

      <block v-else>
        <view
          v-for="item in list"
          :key="item.id"
          class="addr-card"
          @tap="onTapCard(item)"
        >
          <view class="addr-card__body">
            <view class="addr-card__location">
              <view class="addr-pin">
                <view class="addr-pin__head" />
                <view class="addr-pin__body" />
              </view>
            </view>

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

            <view class="addr-card__action" @tap.stop="stopCardTap">
              <mb-button
                class="addr-card__edit-btn"
                type="secondary"
                size="small"
                label="编辑"
                @click="goEdit(item.id)"
              />
            </view>
          </view>
        </view>
      </block>

      <view class="addr-list__bottom-spacer" />
    </scroll-view>

    <view class="addr-list__footer">
      <view class="addr-list__footer-actions">
        <mb-button
          class="addr-list__smart-btn"
          type="secondary"
          size="large"
          label="智能解析"
          @click="goSmartAdd"
        />
        <mb-button
          class="addr-list__add-btn"
          type="primary"
          size="large"
          label="新增地址"
          @click="goAdd"
        />
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

function goSmartAdd() {
  uni.navigateTo({ url: '/pages-sub/address/edit?smart=1' })
}

function goEdit(id) {
  uni.navigateTo({ url: `/pages-sub/address/edit?id=${id}` })
}

function stopCardTap() {}

</script>

<style lang="scss" scoped>
.addr-list {
  min-height: 100vh;
  background: $mb-color-bg-secondary;
}

.addr-list__loading {
  padding: $mb-spacing-sm 0;
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
  letter-spacing: 0;
}

.addr-list__subtitle {
  display: block;
  margin-top: $mb-spacing-xs;
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  letter-spacing: 0;
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
  border: 1rpx solid $mb-color-border;
}

.addr-card__body {
  display: flex;
  align-items: flex-start;
  gap: $mb-spacing-md;
}

.addr-card__location {
  flex-shrink: 0;
  width: 58rpx;
  height: 58rpx;
  border-radius: 16rpx;
  background: rgba($mb-color-primary, 0.08);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 4rpx;
}

.addr-pin {
  position: relative;
  width: 28rpx;
  height: 36rpx;
}

.addr-pin__head {
  position: absolute;
  top: 0;
  left: 0;
  width: 28rpx;
  height: 28rpx;
  border-radius: 50% 50% 50% 0;
  background: $mb-color-primary;
  transform: rotate(-45deg);

  &::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 10rpx;
    height: 10rpx;
    border-radius: 50%;
    background: $mb-color-bg;
    transform: translate(-50%, -50%);
  }
}

.addr-pin__body {
  position: absolute;
  bottom: 0;
  left: 50%;
  width: 4rpx;
  height: 10rpx;
  border-radius: 0 0 2rpx 2rpx;
  background: $mb-color-primary;
  transform: translateX(-50%);
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
  background: rgba($mb-color-primary, 0.08);
  padding: 4rpx 16rpx;
  border-radius: $mb-radius-sm;
  margin-left: $mb-spacing-xs;
}

.addr-card__badge-text {
  font-size: $mb-font-xs;
  color: $mb-color-primary;
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

.addr-card__action {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding-top: 2rpx;
}

.addr-card__edit-btn {
  min-width: 104rpx;
}

// ---- Footer ----
.addr-list__bottom-spacer {
  height: 188rpx;
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
  box-shadow: $mb-shadow-bar;
}

.addr-list__footer-actions {
  display: flex;
  gap: $mb-spacing-md;
}

.addr-list__smart-btn {
  flex: 1;
}

.addr-list__add-btn {
  flex: 1.2;
}
</style>
