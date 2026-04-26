<script setup>
import { computed } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { useAppStore } from '@/store/app'
import { isLoggedIn } from '@/utils/auth'

const userStore = useUserStore()
const appStore = useAppStore()

const brandName = computed(() => appStore.siteConfig?.site_name || 'MALLBASE')

const systemInfo = uni.getSystemInfoSync()
const statusBarHeight = systemInfo.statusBarHeight || 0

// ---------- computed ----------
const logged = computed(() => isLoggedIn())

const nickname = computed(() => userStore.userInfo?.nickname || '')
const avatar = computed(() => userStore.userInfo?.avatar || '')
const mobile = computed(() => {
  const raw = userStore.userInfo?.mobile || ''
  if (!raw || raw.length < 7) return raw
  return raw.slice(0, 3) + ' **** ' + raw.slice(-4)
})
const levelLabel = computed(() => {
  const level = userStore.userInfo?.level
  if (!level) return ''
  return `等级标签 TOP ${level}%`
})

// ---------- order shortcuts ----------
const orderShortcuts = [
  { key: 'pending_pay', label: '待付款', icon: '¥' },
  { key: 'pending_ship', label: '待发货', icon: '✉' },
  { key: 'pending_receive', label: '待收货', icon: '✈' },
  { key: 'refund', label: '退款售后', icon: '↩' },
]

// ---------- function cells ----------
const menuCells = [
  { key: 'address', label: '收货地址', path: '/pages-sub/address/list' },
  { key: 'favorite', label: '我的收藏', path: '' },
  { key: 'theme', label: '主题设置', path: '' },
  { key: 'about', label: '关于我们', path: '' },
]

// ---------- lifecycle ----------
onShow(() => {
  if (isLoggedIn()) {
    userStore.fetchUserInfo()
  }
})

// ---------- navigation ----------
function goLogin() {
  uni.navigateTo({ url: '/pages-sub/user/login' })
}

function goSearch() {
  uni.navigateTo({ url: '/pages-sub/search/index' })
}

function goOrders(shortcut) {
  if (!isLoggedIn()) {
    goLogin()
    return
  }
  if (shortcut?.key) {
    uni.setStorageSync('order_initial_tab', shortcut.key)
  }
  uni.switchTab({ url: '/pages/order/index' })
}

function goAllOrders() {
  if (!isLoggedIn()) {
    goLogin()
    return
  }
  uni.switchTab({ url: '/pages/order/index' })
}

function goCell(cell) {
  if (!cell.path) {
    uni.showToast({ title: '即将开放', icon: 'none' })
    return
  }
  if (!isLoggedIn()) {
    goLogin()
    return
  }
  uni.navigateTo({ url: cell.path })
}

function handleLogout() {
  uni.showModal({
    title: '提示',
    content: '确定退出登录吗？',
    success: (res) => {
      if (res.confirm) {
        userStore.logout()
        uni.showToast({ title: '已退出登录', icon: 'none' })
      }
    },
  })
}
</script>

<template>
  <view class="page">
    <!-- ========== Top App Bar ========== -->
    <view
      class="top-bar"
      :style="{ paddingTop: statusBarHeight + 'px' }"
    >
      <view class="top-bar__inner">
        <text class="top-bar__brand">{{ brandName }}</text>
        <view class="top-bar__spacer" />
        <view class="top-bar__search-btn" @tap="goSearch">
          <!-- CSS search icon -->
          <view class="search-icon">
            <view class="search-icon__lens" />
            <view class="search-icon__handle" />
          </view>
        </view>
      </view>
    </view>

    <!-- ========== Profile Header (centered) ========== -->
    <view class="profile-header">
      <!-- Logged-in state -->
      <view v-if="logged" class="profile-header__body" @tap="() => {}">
        <image
          v-if="avatar"
          class="profile-header__avatar"
          :src="avatar"
          mode="aspectFill"
        />
        <view v-else class="profile-header__avatar profile-header__avatar--placeholder">
          <view class="avatar-icon">
            <view class="avatar-icon__head" />
            <view class="avatar-icon__body" />
          </view>
        </view>

        <text class="profile-header__nickname">{{ nickname || '用户' }}</text>
        <text v-if="mobile" class="profile-header__mobile">{{ mobile }}</text>

        <view v-if="levelLabel" class="profile-header__badge">
          <text class="profile-header__badge-text">{{ levelLabel }}</text>
        </view>
      </view>

      <!-- Unlogged state -->
      <view v-else class="profile-header__body" @tap="goLogin">
        <view class="profile-header__avatar profile-header__avatar--placeholder">
          <view class="avatar-icon">
            <view class="avatar-icon__head" />
            <view class="avatar-icon__body" />
          </view>
        </view>

        <text class="profile-header__nickname">点击登录</text>
        <text class="profile-header__mobile">登录后享受更多服务</text>
      </view>
    </view>

    <!-- ========== Order Shortcuts Card ========== -->
    <view class="order-card">
      <view class="order-card__title-row">
        <text class="order-card__title">我的订单</text>
        <view class="order-card__all" @tap="goAllOrders">
          <text class="order-card__all-text">查看全部</text>
          <view class="arrow-icon arrow-icon--sm" />
        </view>
      </view>
      <view class="order-card__grid">
        <view
          v-for="item in orderShortcuts"
          :key="item.key"
          class="order-card__item"
          @tap="goOrders(item)"
        >
          <view :class="['order-dot', 'order-dot--' + item.key]">
            <text class="order-dot__icon">{{ item.icon }}</text>
          </view>
          <text class="order-card__label">{{ item.label }}</text>
        </view>
      </view>
    </view>

    <!-- ========== Menu Cell List ========== -->
    <view class="cell-group">
      <view
        v-for="(cell, ci) in menuCells"
        :key="cell.key"
        class="cell"
        :class="{ 'cell--last': ci === menuCells.length - 1 }"
        @tap="goCell(cell)"
      >
        <view :class="['cell__icon-wrap', 'cell__icon-wrap--' + cell.key]">
          <!-- address: location pin -->
          <view v-if="cell.key === 'address'" class="ci ci-address">
            <view class="ci-address__pin" />
            <view class="ci-address__dot" />
          </view>
          <!-- favorite: heart -->
          <view v-else-if="cell.key === 'favorite'" class="ci ci-heart">
            <view class="ci-heart__shape" />
          </view>
          <!-- theme: palette/circle -->
          <view v-else-if="cell.key === 'theme'" class="ci ci-theme">
            <view class="ci-theme__outer" />
            <view class="ci-theme__half" />
          </view>
          <!-- about: info circle -->
          <view v-else-if="cell.key === 'about'" class="ci ci-about">
            <view class="ci-about__circle" />
            <view class="ci-about__i" />
          </view>
        </view>
        <text class="cell__label">{{ cell.label }}</text>
        <view class="cell__spacer" />
        <view class="arrow-icon" />
      </view>
    </view>

    <!-- ========== Logout Button ========== -->
    <view v-if="logged" class="logout-wrap">
      <view class="logout-btn" @tap="handleLogout">
        <text class="logout-btn__text">退出登录</text>
      </view>
    </view>

    <!-- Bottom safe area -->
    <view class="bottom-spacer" />
  </view>
</template>

<style lang="scss" scoped>
/* ===========================
   Page
   =========================== */
.page {
  min-height: 100vh;
  background: $mb-color-bg-secondary;
}

/* ===========================
   Top App Bar
   =========================== */
.top-bar {
  background: $mb-color-bg-secondary;
}

.top-bar__inner {
  display: flex;
  align-items: center;
  height: 88rpx;
  padding: 0 $mb-spacing-page;
}

.top-bar__brand {
  font-size: 30rpx;
  font-weight: 700;
  color: $mb-color-text;
  letter-spacing: 2rpx;
}

.top-bar__spacer {
  flex: 1;
}

.top-bar__search-btn {
  width: 64rpx;
  height: 64rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* CSS search magnifier icon */
.search-icon {
  position: relative;
  width: 32rpx;
  height: 32rpx;
}

.search-icon__lens {
  width: 22rpx;
  height: 22rpx;
  border: 3rpx solid $mb-color-text;
  border-radius: $mb-radius-full;
  position: absolute;
  top: 0;
  left: 0;
}

.search-icon__handle {
  width: 10rpx;
  height: 3rpx;
  background: $mb-color-text;
  position: absolute;
  bottom: 3rpx;
  right: 0;
  transform: rotate(45deg);
  border-radius: 2rpx;
}

/* ===========================
   Profile Header (centered)
   =========================== */
.profile-header {
  padding: $mb-spacing-lg $mb-spacing-page 56rpx;
  background: $mb-color-bg-secondary;
}

.profile-header__body {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.profile-header__avatar {
  width: 160rpx;
  height: 160rpx;
  border-radius: $mb-radius-full;
  flex-shrink: 0;
  margin-bottom: $mb-spacing-md;
  background: #e8eaed;
}

.profile-header__avatar--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #d5d8dc;
}

/* Placeholder person silhouette */
.avatar-icon {
  position: relative;
  width: 56rpx;
  height: 64rpx;
}

.avatar-icon__head {
  width: 32rpx;
  height: 32rpx;
  border-radius: $mb-radius-full;
  background: rgba(255, 255, 255, 0.7);
  position: absolute;
  top: 0;
  left: 50%;
  transform: translateX(-50%);
}

.avatar-icon__body {
  width: 50rpx;
  height: 28rpx;
  border-radius: 25rpx 25rpx 0 0;
  background: rgba(255, 255, 255, 0.7);
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
}

.profile-header__nickname {
  font-size: $mb-font-xl;
  font-weight: 700;
  color: $mb-color-text;
  line-height: 1.3;
}

.profile-header__mobile {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  line-height: 1.4;
  margin-top: 6rpx;
}

.profile-header__badge {
  margin-top: 16rpx;
  padding: 6rpx 24rpx;
  background: $mb-color-text;
  border-radius: $mb-radius-full;
}

.profile-header__badge-text {
  font-size: $mb-font-xs;
  color: $mb-color-text-inverse;
  font-weight: 500;
  letter-spacing: 1rpx;
}

/* ===========================
   Order Shortcuts Card
   =========================== */
.order-card {
  margin: 0 $mb-spacing-page;
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg;
  box-shadow: 0 4rpx 24rpx rgba(0, 0, 0, 0.04);
}

.order-card__title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: $mb-spacing-lg;
}

.order-card__title {
  font-size: $mb-font-lg;
  font-weight: 600;
  color: $mb-color-text-title;
}

.order-card__all {
  display: flex;
  align-items: center;
  gap: 4rpx;
}

.order-card__all-text {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

.order-card__grid {
  display: flex;
}

.order-card__item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14rpx;
}

.order-card__label {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
}

/* Order icon dots */
.order-dot {
  width: 80rpx;
  height: 80rpx;
  border-radius: $mb-radius-full;
  display: flex;
  align-items: center;
  justify-content: center;
}

.order-dot--pending_pay {
  background: rgba(13, 80, 213, 0.06);
}

.order-dot--pending_ship {
  background: rgba(240, 173, 78, 0.08);
}

.order-dot--pending_receive {
  background: rgba(240, 173, 78, 0.08);
}

.order-dot--refund {
  background: rgba(186, 26, 26, 0.06);
}

.order-dot__icon {
  font-size: 36rpx;
  color: $mb-color-text;
  font-weight: 500;
}

/* ===========================
   Arrow icon (chevron-right)
   =========================== */
.arrow-icon {
  width: 16rpx;
  height: 16rpx;
  border-right: 3rpx solid $mb-color-text-tertiary;
  border-bottom: 3rpx solid $mb-color-text-tertiary;
  transform: rotate(-45deg);
  flex-shrink: 0;
}

.arrow-icon--sm {
  width: 12rpx;
  height: 12rpx;
  border-width: 2rpx;
}

/* ===========================
   Menu Cell Group
   =========================== */
.cell-group {
  margin: $mb-spacing-lg $mb-spacing-page 0;
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  overflow: hidden;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.02);
}

.cell {
  display: flex;
  align-items: center;
  padding: 28rpx $mb-spacing-lg;
  position: relative;

  &:not(.cell--last)::after {
    content: '';
    position: absolute;
    left: 96rpx;
    right: $mb-spacing-lg;
    bottom: 0;
    height: 1rpx;
    background: $mb-color-divider;
  }

  &:active {
    background: $mb-color-bg-surface;
  }
}

.cell__icon-wrap {
  width: 52rpx;
  height: 52rpx;
  border-radius: $mb-radius-md;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-right: $mb-spacing-md;
}

.cell__icon-wrap--address {
  background: rgba(13, 80, 213, 0.07);
}

.cell__icon-wrap--favorite {
  background: rgba(186, 26, 26, 0.07);
}

.cell__icon-wrap--theme {
  background: rgba(240, 173, 78, 0.08);
}

.cell__icon-wrap--about {
  background: rgba(52, 199, 89, 0.07);
}

.cell__label {
  font-size: $mb-font-md;
  color: $mb-color-text;
  font-weight: 500;
}

.cell__spacer {
  flex: 1;
}

/* ===========================
   Cell icons (pure CSS)
   =========================== */
.ci {
  position: relative;
}

/* --- Address pin --- */
.ci-address {
  width: 22rpx;
  height: 28rpx;
}

.ci-address__pin {
  width: 18rpx;
  height: 18rpx;
  border: 3rpx solid $mb-color-primary;
  border-radius: 50% 50% 50% 0;
  transform: rotate(-45deg);
  position: absolute;
  top: 0;
  left: 50%;
  margin-left: -9rpx;
}

.ci-address__dot {
  width: 5rpx;
  height: 5rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-primary;
  position: absolute;
  top: 7rpx;
  left: 50%;
  transform: translateX(-50%);
}

/* --- Heart --- */
.ci-heart {
  width: 24rpx;
  height: 22rpx;
}

.ci-heart__shape {
  position: absolute;
  top: 6rpx;
  left: 50%;
  transform: translateX(-50%) rotate(-45deg);
  width: 13rpx;
  height: 13rpx;
  background: $mb-color-error;
  border-radius: 50% 0 0 0;

  &::before {
    content: '';
    position: absolute;
    width: 13rpx;
    height: 13rpx;
    background: $mb-color-error;
    border-radius: 50%;
    top: -6rpx;
    left: 0;
  }

  &::after {
    content: '';
    position: absolute;
    width: 13rpx;
    height: 13rpx;
    background: $mb-color-error;
    border-radius: 50%;
    top: 0;
    right: -6rpx;
  }
}

/* --- Theme (half-circle palette) --- */
.ci-theme {
  width: 24rpx;
  height: 24rpx;
}

.ci-theme__outer {
  width: 20rpx;
  height: 20rpx;
  border-radius: $mb-radius-full;
  border: 3rpx solid #e6930a;
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
}

.ci-theme__half {
  width: 10rpx;
  height: 20rpx;
  background: #e6930a;
  border-radius: 0 10rpx 10rpx 0;
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translateY(-50%);
}

/* --- About (info circle) --- */
.ci-about {
  width: 24rpx;
  height: 24rpx;
}

.ci-about__circle {
  width: 20rpx;
  height: 20rpx;
  border-radius: $mb-radius-full;
  border: 3rpx solid $mb-color-success;
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
}

.ci-about__i {
  width: 3rpx;
  height: 9rpx;
  background: $mb-color-success;
  border-radius: 2rpx;
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -20%);

  &::before {
    content: '';
    width: 4rpx;
    height: 4rpx;
    border-radius: $mb-radius-full;
    background: $mb-color-success;
    position: absolute;
    bottom: calc(100% + 2rpx);
    left: 50%;
    transform: translateX(-50%);
  }
}

/* ===========================
   Logout Button
   =========================== */
.logout-wrap {
  padding: 48rpx $mb-spacing-page 0;
}

.logout-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 88rpx;
  border-radius: $mb-radius-lg;
  border: 2rpx solid $mb-color-border;
  background: $mb-color-bg;

  &:active {
    background: $mb-color-bg-surface;
  }
}

.logout-btn__text {
  font-size: $mb-font-md;
  font-weight: 500;
  color: $mb-color-text;
}

/* ===========================
   Bottom safe area
   =========================== */
.bottom-spacer {
  height: 200rpx;
}
</style>
