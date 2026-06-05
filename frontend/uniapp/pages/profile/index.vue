<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getPayMethods } from '@/api/config'
import { getWalletInfo } from '@/api/user/wallet'
import { useDecorateStore } from '@/store/decorate'
import { useUserStore } from '@/store/user'

const userStore = useUserStore()
const decorateStore = useDecorateStore()

const wallet = ref({
  balance: '0.00',
  total_recharge: '0.00',
  total_consume: '0.00',
})
const balancePaymentEnabled = ref(false)

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

const profileModules = computed(() => decorateStore.profileModules)

const themeOptions = computed(() => {
  const list = [
    { mode: 'system', label: '跟随系统' },
    { mode: 'light', label: '浅色' },
    { mode: 'dark', label: '深色' },
  ]
  if (decorateStore.themes?.custom) {
    list.push({ mode: 'custom', label: '自定义' })
  }
  return list
})

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

function moduleList(module) {
  const list = module.props.items || module.props.list || []
  if (!Array.isArray(list)) return []
  return list
    .filter((item) => {
      if (item.requireBalanceEnabled) return balancePaymentEnabled.value
      if ((item.action === 'theme' || item.key === 'theme') && !decorateStore.allowUserThemeSelect) return false
      return item.visible !== false && item.enabled !== false
    })
    .map((item) => ({
      ...item,
      label: item.label || item.title || item.text || '',
      path: item.path || item.url || item.link || '',
    }))
}

function goLogin() {
  uni.navigateTo({ url: '/pages-sub/user/login' })
}

function goEditProfile() {
  if (userStore.isLoggedIn) {
    uni.navigateTo({ url: '/pages-sub/user/edit-profile' })
    return
  }
  goLogin()
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
  if (cell.action === 'theme' || cell.key === 'theme') {
    showThemeSelector()
    return
  }
  if (!cell.path && !cell.url) {
    uni.showToast({ title: '即将开放', icon: 'none' })
    return
  }
  if (cell.auth !== false && !userStore.isLoggedIn) {
    goLogin()
    return
  }
  uni.navigateTo({ url: cell.path || cell.url })
}

function showThemeSelector() {
  if (!decorateStore.allowUserThemeSelect) {
    uni.showToast({ title: '当前不允许切换主题', icon: 'none' })
    return
  }
  uni.showActionSheet({
    itemList: themeOptions.value.map((item) => item.label),
    success(res) {
      const selected = themeOptions.value[res.tapIndex]
      if (!selected) return
      decorateStore.setThemeMode(selected.mode)
      uni.showToast({ title: `已切换为${selected.label}`, icon: 'none' })
    },
  })
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
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="MallBase" :back="false" bg-color="var(--color-bg, #ffffff)" />

    <view class="profile-modules">
      <template v-for="module in profileModules" :key="module.id">
        <view v-if="module.type === 'userCard'" class="profile-header">
          <view v-if="logged" class="profile-header__body" @tap="goEditProfile">
            <image v-if="avatar" class="profile-header__avatar" :src="avatar" mode="aspectFill" />
            <view v-else class="profile-header__avatar profile-header__avatar--placeholder">
              <text class="profile-header__avatar-text">{{ (nickname || 'M').slice(0, 1) }}</text>
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
          <view v-else class="profile-header__body" @tap="goLogin">
            <view class="profile-header__avatar profile-header__avatar--placeholder">
              <text class="profile-header__avatar-text">M</text>
            </view>
            <view class="profile-header__main">
              <text class="profile-header__nickname">点击登录</text>
              <text class="profile-header__mobile">登录后享受更多服务</text>
              <text class="profile-header__bio">完善资料后可展示个性签名</text>
            </view>
          </view>
        </view>

        <view v-else-if="module.type === 'wallet' && balancePaymentEnabled" class="wallet-card" @tap="goWallet">
          <view class="wallet-card__main">
            <text class="wallet-card__label">{{ module.props.title || '我的余额' }}</text>
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

        <view v-else-if="module.type === 'orderShortcut'" class="order-card">
          <view class="order-card__title-row">
            <text class="order-card__title">{{ module.props.title || '我的订单' }}</text>
            <view class="order-card__all" @tap="goAllOrders">
              <text class="order-card__all-text">查看全部</text>
              <view class="arrow-icon arrow-icon--sm" />
            </view>
          </view>
          <view class="order-card__grid">
            <view
              v-for="item in moduleList(module)"
              :key="item.key || item.label"
              class="order-card__item"
              @tap="goOrders(item)"
            >
              <view class="order-dot">
                <text class="order-dot__icon">{{ item.icon || item.label.slice(0, 1) }}</text>
              </view>
              <text class="order-card__label">{{ item.label }}</text>
            </view>
          </view>
        </view>

        <view v-else-if="module.type === 'serviceMenu'" class="cell-group">
          <view
            v-for="(cell, ci) in moduleList(module)"
            :key="cell.key || cell.label"
            class="cell"
            :class="{ 'cell--last': ci === moduleList(module).length - 1 }"
            @tap="goCell(cell)"
          >
            <view class="cell__icon-wrap">
              <text class="cell__icon">{{ cell.icon || cell.label.slice(0, 1) }}</text>
            </view>
            <text class="cell__label">{{ cell.label }}</text>
            <view class="cell__spacer" />
            <text v-if="cell.key === 'theme'" class="cell__value">
              {{ themeOptions.find((item) => item.mode === decorateStore.themeMode)?.label || '跟随系统' }}
            </text>
            <view class="arrow-icon" />
          </view>
        </view>

        <view v-else-if="module.type === 'title'" class="plain-title">
          <text class="plain-title__text">{{ module.props.text || module.props.title }}</text>
        </view>

        <view v-else-if="module.type === 'richText'" class="plain-rich">
          <rich-text :nodes="module.props.content || module.props.html || ''" />
        </view>

        <view
          v-else-if="module.type === 'spacing'"
          :style="{ height: `${Number(module.props.height || 24)}rpx` }"
        />

        <view v-else-if="module.type === 'divider'" class="plain-divider" />

        <view v-else-if="module.type === 'logout' && logged" class="logout-wrap">
          <view class="logout-btn" @tap="handleLogout">
            <text class="logout-btn__text">{{ module.props.text || '退出登录' }}</text>
          </view>
        </view>
      </template>
    </view>

    <view class="bottom-spacer" />
    <mb-custom-tabbar current="/pages/profile/index" />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

.profile-modules {
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.profile-header {
  padding: 28rpx 28rpx 36rpx;
  background: linear-gradient(180deg, rgba(13, 80, 213, 0.1) 0%, var(--color-bg-secondary, #faf8ff) 100%);
}

.profile-header__body {
  display: flex;
  align-items: center;
  gap: 24rpx;
}

.profile-header__avatar {
  width: 92rpx;
  height: 92rpx;
  border-radius: 999rpx;
  flex-shrink: 0;
  background: var(--color-bg-surface, #f3f3fe);
}

.profile-header__avatar--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.profile-header__avatar-text {
  font-size: 34rpx;
  font-weight: 800;
  color: var(--color-primary, #0d50d5);
}

.profile-header__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.profile-header__nickname {
  font-size: 30rpx;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
  line-height: 1.3;
}

.profile-header__mobile {
  margin-top: 6rpx;
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}

.profile-header__bio {
  margin-top: 8rpx;
  font-size: 22rpx;
  color: var(--color-text-secondary, #434654);
  line-height: 1.5;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.profile-header__edit-btn {
  margin-top: 12rpx;
  height: 48rpx;
  padding: 0 20rpx;
  border-radius: 999rpx;
  border: 1rpx solid rgba(13, 80, 213, 0.45);
  background: rgba(13, 80, 213, 0.06);
  align-self: flex-start;
  display: flex;
  align-items: center;
}

.profile-header__edit-text {
  font-size: 22rpx;
  color: var(--color-primary, #0d50d5);
  font-weight: 600;
}

.wallet-card,
.order-card,
.cell-group,
.plain-rich {
  margin: 0 28rpx;
  background: var(--color-bg, #ffffff);
  border-radius: 20rpx;
  border: 1rpx solid var(--color-divider, #f0f2f5);
}

.wallet-card {
  padding: 28rpx;
}

.wallet-card__label {
  font-size: 24rpx;
  color: var(--color-text-secondary, #434654);
}

.wallet-card__amount {
  display: flex;
  align-items: baseline;
  margin-top: 10rpx;
}

.wallet-card__symbol {
  font-size: 32rpx;
  color: var(--color-text-title, #191b23);
  font-weight: 700;
}

.wallet-card__value {
  margin-left: 4rpx;
  font-size: 52rpx;
  line-height: 1;
  color: var(--color-text-title, #191b23);
  font-weight: 800;
}

.wallet-card__meta,
.wallet-card__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12rpx;
}

.wallet-card__meta {
  margin-top: 16rpx;
}

.wallet-card__meta-text,
.wallet-card__dot {
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
}

.wallet-card__actions {
  margin-top: 24rpx;
}

.wallet-card__action {
  flex: 1;
  height: 64rpx;
  border-radius: 12rpx;
  background: var(--color-bg-surface, #f3f3fe);
  display: flex;
  align-items: center;
  justify-content: center;
}

.wallet-card__action--primary {
  background: var(--color-primary, #0d50d5);
}

.wallet-card__action-text {
  font-size: 24rpx;
  color: var(--color-text-secondary, #434654);
  font-weight: 600;
}

.wallet-card__action-text--primary {
  color: #ffffff;
}

.order-card {
  padding: 28rpx;
}

.order-card__title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 28rpx;
}

.order-card__title {
  font-size: 30rpx;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.order-card__all {
  display: flex;
  align-items: center;
  gap: 6rpx;
}

.order-card__all-text {
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}

.order-card__grid {
  display: flex;
}

.order-card__item {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14rpx;
}

.order-dot {
  width: 80rpx;
  height: 80rpx;
  border-radius: 18rpx;
  background: rgba(13, 80, 213, 0.08);
  display: flex;
  align-items: center;
  justify-content: center;
}

.order-dot__icon {
  font-size: 32rpx;
  color: var(--color-primary, #0d50d5);
  font-weight: 700;
}

.order-card__label {
  font-size: 24rpx;
  color: var(--color-text-secondary, #434654);
}

.cell-group {
  overflow: hidden;
}

.cell {
  display: flex;
  align-items: center;
  padding: 28rpx;
  position: relative;

  &:not(.cell--last)::after {
    content: '';
    position: absolute;
    left: 96rpx;
    right: 28rpx;
    bottom: 0;
    height: 1rpx;
    background: var(--color-divider, #f0f2f5);
  }
}

.cell__icon-wrap {
  width: 52rpx;
  height: 52rpx;
  border-radius: 12rpx;
  background: rgba(13, 80, 213, 0.08);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 24rpx;
}

.cell__icon {
  font-size: 22rpx;
  color: var(--color-primary, #0d50d5);
  font-weight: 700;
}

.cell__label {
  font-size: 28rpx;
  color: var(--color-text, #191b23);
  font-weight: 500;
}

.cell__spacer {
  flex: 1;
}

.cell__value {
  margin-right: 12rpx;
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}

.arrow-icon {
  width: 16rpx;
  height: 16rpx;
  border-right: 3rpx solid var(--color-text-tertiary, #737686);
  border-bottom: 3rpx solid var(--color-text-tertiary, #737686);
  transform: rotate(-45deg);
  flex-shrink: 0;
}

.arrow-icon--sm {
  width: 12rpx;
  height: 12rpx;
  border-width: 2rpx;
}

.plain-title {
  margin: 0 28rpx;
}

.plain-title__text {
  font-size: 32rpx;
  font-weight: 800;
  color: var(--color-text-title, #191b23);
}

.plain-rich {
  padding: 24rpx;
  color: var(--color-text, #191b23);
}

.plain-divider {
  margin: 0 28rpx;
  height: 1rpx;
  background: var(--color-divider, #f0f2f5);
}

.logout-wrap {
  padding: 0 28rpx;
}

.logout-btn {
  height: 88rpx;
  border-radius: 16rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  display: flex;
  align-items: center;
  justify-content: center;
}

.logout-btn__text {
  font-size: 28rpx;
  color: var(--color-error, #ba1a1a);
  font-weight: 600;
}

.bottom-spacer {
  height: 144rpx;
}
</style>
