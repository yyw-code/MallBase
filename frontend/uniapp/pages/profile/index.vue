<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getPayMethods } from '@/api/config'
import { getWalletInfo } from '@/api/user/wallet'
import { useUserStore } from '@/store/user'

const userStore = useUserStore()
const wallet = ref({
  balance: '0.00',
  total_recharge: '0.00',
  total_consume: '0.00',
})
const balancePaymentEnabled = ref(false)

// ---------- computed ----------
const logged = computed(() => userStore.isLoggedIn)

const nickname = computed(() => userStore.userInfo?.nickname || '')
const avatar = computed(() => userStore.userInfo?.avatar_full_url || userStore.userInfo?.avatar || '')
const bio = computed(() => userStore.userInfo?.bio || '还没有填写个性签名')
const mobile = computed(() => {
  const raw = userStore.userInfo?.mobile || ''
  if (!raw || raw.length < 7) return raw
  return raw.slice(0, 3) + ' **** ' + raw.slice(-4)
})
const walletBalance = computed(() => formatAmount(wallet.value.balance))
const walletRecharge = computed(() => formatAmount(wallet.value.total_recharge))
const walletConsume = computed(() => formatAmount(wallet.value.total_consume))

// ---------- order shortcuts ----------
const orderShortcuts = [
  { key: 'pending_pay', label: '待付款', icon: '¥' },
  { key: 'paid', label: '待发货', icon: '✉' },
  { key: 'shipped', label: '待收货', icon: '✈' },
  { key: 'refund', label: '退款售后', icon: '↩', path: '/pages-sub/refund/list' },
]

// ---------- function cells ----------
const menuCells = computed(() => [
  { key: 'address', label: '地址管理', path: '/pages-sub/address/list' },
  ...(balancePaymentEnabled.value ? [{ key: 'wallet', label: '我的余额', path: '/pages-sub/wallet/index' }] : []),
  { key: 'favorite', label: '我的收藏', path: '' },
  { key: 'theme', label: '主题设置', path: '' },
  { key: 'about', label: '关于我们', path: '' },
])

function goEditProfile() {
  if (userStore.isLoggedIn) {
    uni.navigateTo({ url: '/pages-sub/user/edit-profile' })
    return
  }
  goLogin()
}

// ---------- lifecycle ----------
onShow(() => {
  userStore.restoreToken()
  fetchPayMethodState()
  if (userStore.isLoggedIn) {
    userStore.fetchUserInfo()
    if (balancePaymentEnabled.value) {
      fetchWallet()
    }
  }
})

async function fetchPayMethodState() {
  try {
    const methods = await getPayMethods()
    balancePaymentEnabled.value = Array.isArray(methods)
      && methods.some((item) => Number(item?.code) === 3)
    if (userStore.isLoggedIn && balancePaymentEnabled.value) {
      fetchWallet()
    }
  } catch {
    balancePaymentEnabled.value = false
  }
}

async function fetchWallet() {
  try {
    const data = await getWalletInfo()
    wallet.value = {
      ...wallet.value,
      ...(data || {}),
    }
  } catch {
    wallet.value = {
      balance: '0.00',
      total_recharge: '0.00',
      total_consume: '0.00',
    }
  }
}

function formatAmount(value) {
  return Number(value || 0).toFixed(2)
}

// ---------- navigation ----------
function goLogin() {
  uni.navigateTo({ url: '/pages-sub/user/login' })
}

function goOrders(shortcut) {
  if (!userStore.isLoggedIn) {
    goLogin()
    return
  }
  if (shortcut?.path) {
    uni.navigateTo({ url: shortcut.path })
    return
  }
  if (shortcut?.key) {
    uni.setStorageSync('order_initial_tab', shortcut.key)
  }
  uni.switchTab({ url: '/pages/order/index' })
}

function goAllOrders() {
  if (!userStore.isLoggedIn) {
    goLogin()
    return
  }
  uni.switchTab({ url: '/pages/order/index' })
}

function goWallet() {
  if (!userStore.isLoggedIn) {
    goLogin()
    return
  }
  uni.navigateTo({ url: '/pages-sub/wallet/index' })
}

function goWalletRecords() {
  if (!userStore.isLoggedIn) {
    goLogin()
    return
  }
  uni.navigateTo({ url: '/pages-sub/wallet/records' })
}

function goCell(cell) {
  if (!cell.path) {
    uni.showToast({ title: '即将开放', icon: 'none' })
    return
  }
  if (!userStore.isLoggedIn) {
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
    <mb-navbar title="MallBase" :back="false" bg-color="#ffffff" />

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

        <view class="profile-header__main">
          <text class="profile-header__nickname">{{ nickname || 'MallBase 用户' }}</text>
          <text v-if="mobile" class="profile-header__mobile">{{ mobile }}</text>
          <text class="profile-header__bio">{{ bio }}</text>
          <view class="profile-header__edit-btn" @tap.stop="goEditProfile">
            <text class="profile-header__edit-text">资料编辑</text>
          </view>
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

        <view class="profile-header__main">
          <text class="profile-header__nickname">点击登录</text>
          <text class="profile-header__mobile">登录后享受更多服务</text>
          <text class="profile-header__bio">完善资料后可展示个性签名</text>
        </view>
      </view>
    </view>

    <!-- ========== Wallet Summary ========== -->
    <view v-if="balancePaymentEnabled" class="wallet-card" @tap="goWallet">
      <view class="wallet-card__main">
        <text class="wallet-card__label">我的余额</text>
        <view class="wallet-card__amount">
          <text class="wallet-card__symbol">¥</text>
          <text class="wallet-card__value">{{ logged ? walletBalance : '0.00' }}</text>
        </view>
        <view class="wallet-card__meta">
          <text class="wallet-card__meta-text">累计充值 ¥{{ logged ? walletRecharge : '0.00' }}</text>
          <text class="wallet-card__dot">•</text>
          <text class="wallet-card__meta-text">累计消费 ¥{{ logged ? walletConsume : '0.00' }}</text>
        </view>
      </view>
      <view class="wallet-card__actions">
        <view class="wallet-card__action" @tap.stop="goWalletRecords">
          <text class="wallet-card__action-text">余额明细</text>
        </view>
        <view class="wallet-card__action wallet-card__action--primary" @tap.stop="goWallet">
          <text class="wallet-card__action-text wallet-card__action-text--primary">去查看</text>
        </view>
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
          <!-- wallet: coin -->
          <view v-else-if="cell.key === 'wallet'" class="ci ci-wallet">
            <view class="ci-wallet__body" />
            <view class="ci-wallet__coin" />
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
   Profile Header (centered)
   =========================== */
.profile-header {
  padding: 28rpx $mb-spacing-page 36rpx;
  background: linear-gradient(180deg, #eef3ff 0%, #faf8ff 100%);
}

.profile-header__body {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: $mb-spacing-md;
}

.profile-header__avatar {
  width: 92rpx;
  height: 92rpx;
  border-radius: $mb-radius-full;
  flex-shrink: 0;
  background: #e8eaed;
}

.profile-header__avatar--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #d5d8dc;
}

.profile-header__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
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
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text;
  line-height: 1.3;
}

.profile-header__mobile {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  line-height: 1.4;
  margin-top: 6rpx;
}

.profile-header__bio {
  margin-top: 8rpx;
  font-size: $mb-font-xs;
  color: $mb-color-text-secondary;
  line-height: 1.5;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.profile-header__edit-btn {
  margin-top: 12rpx;
  height: 48rpx;
  padding: 0 20rpx;
  border-radius: $mb-radius-full;
  border: 1rpx solid rgba(13, 80, 213, 0.45);
  background: rgba(13, 80, 213, 0.06);
  align-self: flex-start;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* ===========================
   Wallet Card
   =========================== */
.wallet-card {
  margin: -12rpx $mb-spacing-page $mb-spacing-md;
  padding: 28rpx;
  background: $mb-color-bg;
  border: 1rpx solid $mb-color-divider;
  border-radius: $mb-radius-lg;
}

.wallet-card__main {
  min-width: 0;
}

.wallet-card__label {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
}

.wallet-card__amount {
  display: flex;
  align-items: baseline;
  margin-top: 10rpx;
}

.wallet-card__symbol {
  font-size: $mb-font-lg;
  color: $mb-color-text-title;
  font-weight: 700;
}

.wallet-card__value {
  margin-left: 4rpx;
  font-size: 52rpx;
  line-height: 1;
  color: $mb-color-text-title;
  font-weight: 700;
}

.wallet-card__meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10rpx;
  margin-top: 16rpx;
}

.wallet-card__meta-text,
.wallet-card__dot {
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
}

.wallet-card__actions {
  display: flex;
  gap: $mb-spacing-sm;
  margin-top: 24rpx;
}

.wallet-card__action {
  flex: 1;
  height: 64rpx;
  border-radius: $mb-radius-md;
  background: $mb-color-bg-surface;
  display: flex;
  align-items: center;
  justify-content: center;
}

.wallet-card__action--primary {
  background: $mb-color-primary;
}

.wallet-card__action-text {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
  font-weight: 600;
}

.wallet-card__action-text--primary {
  color: $mb-color-text-inverse;
}

.profile-header__edit-text {
  font-size: $mb-font-xs;
  color: $mb-color-primary;
  font-weight: 600;
  line-height: 1;
}

/* ===========================
   Order Shortcuts Card
   =========================== */
.order-card {
  margin: 0 $mb-spacing-page;
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg;
  border: 1rpx solid $mb-color-divider;
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
  border-radius: $mb-radius-lg;
  display: flex;
  align-items: center;
  justify-content: center;
}

.order-dot--pending_pay {
  background: rgba(13, 80, 213, 0.06);
}

.order-dot--paid {
  background: rgba(240, 173, 78, 0.08);
}

.order-dot--shipped {
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
  border: 1rpx solid $mb-color-divider;
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

.cell__icon-wrap--wallet {
  background: rgba(240, 173, 78, 0.1);
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

/* --- Wallet --- */
.ci-wallet {
  width: 28rpx;
  height: 24rpx;
}

.ci-wallet__body {
  position: absolute;
  left: 1rpx;
  top: 4rpx;
  width: 26rpx;
  height: 18rpx;
  border: 3rpx solid #d97706;
  border-radius: 6rpx;
}

.ci-wallet__coin {
  position: absolute;
  right: 4rpx;
  top: 10rpx;
  width: 6rpx;
  height: 6rpx;
  border-radius: $mb-radius-full;
  background: #d97706;
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
  border-radius: $mb-radius-sm;
  border: 1rpx solid $mb-color-border;
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
