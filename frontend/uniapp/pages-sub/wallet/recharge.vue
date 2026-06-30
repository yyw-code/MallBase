<script setup>
import { useDecorateStore } from '@/store/decorate'
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getRechargeMethods } from '@/api/config'
import { getRechargePackages } from '@/api/recharge/package'
import { getWalletInfo } from '@/api/user/wallet'
import { getPlatform } from '@/utils/platform'
const decorateStore = useDecorateStore()

const loading = ref(false)
const submitLoading = ref(false)
const packages = ref([])
const selectedId = ref(0)
const wallet = ref({
  balance: '0.00',
})

const selectedPackage = computed(() =>
  packages.value.find((item) => Number(item.id) === Number(selectedId.value))
)

const balanceText = computed(() => formatAmount(wallet.value.balance))
const defaultCardBg = '/static/demo/recharge-dragon-card.png'

onShow(() => {
  fetchData()
})

async function fetchData() {
  loading.value = true
  try {
    const [walletInfo, packageList] = await Promise.all([
      getWalletInfo().catch(() => null),
      getRechargePackages(),
    ])
    wallet.value = {
      ...wallet.value,
      ...(walletInfo || {}),
    }
    packages.value = Array.isArray(packageList) ? packageList : []
    if (!selectedId.value && packages.value.length) {
      selectedId.value = Number(packages.value[0].id)
    }
  } catch (e) {
    uni.showToast({ title: e?.message || '获取充值套餐失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function selectPackage(item) {
  selectedId.value = Number(item.id)
}

function formatAmount(value) {
  const n = Number(value || 0)
  return n.toFixed(2)
}

function giftText(item) {
  const gift = Number(item.gift_amount || 0)
  return gift > 0 ? `赠送 ¥${gift.toFixed(2)}` : '无赠送'
}

function cornerText(item) {
  const gift = Number(item.gift_amount || 0)
  return gift > 0 ? `送${gift.toFixed(0)}` : ''
}

function packageBg(item) {
  return item.background_image_full_url || item.background_image || defaultCardBg
}

function goRechargeRecords() {
  uni.navigateTo({ url: '/pages-sub/wallet/records?biz_type=recharge&type=income' })
}

async function submitRecharge() {
  if (submitLoading.value) return
  if (!selectedPackage.value) {
    uni.showToast({ title: '请选择充值套餐', icon: 'none' })
    return
  }

  submitLoading.value = true
  try {
    const methods = await getRechargeMethods()
    const platform = getPlatform()
    const channels = (Array.isArray(methods) ? methods : []).filter((item) => {
      const code = Number(item?.code)
      if (code === 1) return true
      if (code === 2) return platform === 'h5'
      return false
    })

    if (channels.length === 0) {
      uni.showToast({ title: '当前无可用充值方式', icon: 'none' })
      return
    }

    uni.showActionSheet({
      itemList: channels.map((item) => item.name),
      success: ({ tapIndex }) => {
        const channel = channels[tapIndex]
        uni.showToast({
          title: `${channel.name}充值即将开放`,
          icon: 'none',
        })
      },
    })
  } catch (e) {
    uni.showToast({ title: e?.message || '获取充值方式失败', icon: 'none' })
  } finally {
    submitLoading.value = false
  }
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="余额充值" bg-color="var(--color-bg, #ffffff)" />

    <view class="top-action" @tap="goRechargeRecords">
      <text class="top-action__icon">▤</text>
      <text class="top-action__text">充值记录</text>
    </view>

    <view class="summary">
      <text class="summary__label">当前余额</text>
      <view class="summary__amount">
        <text class="summary__symbol">¥</text>
        <text class="summary__value">{{ balanceText }}</text>
      </view>
    </view>

    <view class="section">
      <view class="section__header section__header--ornament">
        <text class="section__line" />
        <text class="section__dot" />
        <text class="section__title">选择充值套餐</text>
        <text class="section__dot" />
        <text class="section__line" />
      </view>

      <view v-if="packages.length" class="package-list">
        <view
          v-for="item in packages"
          :key="item.id"
          class="package-card"
          :class="{ 'package-card--active': Number(item.id) === Number(selectedId) }"
          :style="{ backgroundImage: `url(${packageBg(item)})` }"
          @tap="selectPackage(item)"
        >
          <view v-if="cornerText(item)" class="package-card__corner">
            <text class="package-card__corner-text">{{ cornerText(item) }}</text>
          </view>
          <view v-if="Number(item.id) === Number(selectedId)" class="package-card__selected">
            <text class="package-card__selected-dot">✓</text>
            <text class="package-card__selected-text">已选择</text>
          </view>
          <view class="package-card__main">
            <text class="package-card__name">{{ item.name }}</text>
            <text class="package-card__gift">{{ giftText(item) }}</text>
          </view>
          <view class="package-card__meta">
            <view class="package-card__metric">
              <text class="package-card__metric-label">到账</text>
              <text class="package-card__amount">¥{{ formatAmount(item.balance_amount) }}</text>
            </view>
            <view class="package-card__divider" />
            <view class="package-card__metric">
              <text class="package-card__metric-label">实付</text>
              <text class="package-card__pay">¥{{ formatAmount(item.pay_amount) }}</text>
            </view>
          </view>
        </view>
      </view>

      <view v-else class="empty">
        <text class="empty__title">{{ loading ? '加载中' : '暂无可用套餐' }}</text>
        <text class="empty__desc">请稍后再试</text>
      </view>
    </view>

    <view class="footer">
      <view class="footer__info">
        <text class="footer__label">到账余额</text>
        <text class="footer__amount">
          ¥{{ selectedPackage ? formatAmount(selectedPackage.balance_amount) : '0.00' }}
        </text>
      </view>
      <view
        class="footer__button"
        :class="{ 'footer__button--disabled': !selectedPackage || submitLoading }"
        @tap="submitRecharge"
      >
        <text class="footer__button-text">{{ submitLoading ? '处理中' : '立即充值' }}</text>
      </view>
    </view>
      <mb-floating-action />
</view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 156rpx;
  background:
    radial-gradient(circle at 92% 8%, rgba(224, 58, 45, 0.12) 0, transparent 180rpx),
    linear-gradient(180deg, #fff6e8 0%, #fffdf8 42%, #faf8ff 100%);
}

.top-action {
  position: absolute;
  top: 28rpx;
  right: $mb-spacing-page;
  z-index: 2;
  display: flex;
  align-items: center;
  height: 56rpx;
  padding: 0 22rpx;
  background: rgba(255, 255, 255, 0.72);
  border: 1rpx solid rgba(211, 54, 43, 0.18);
  border-radius: $mb-radius-full;
}

.top-action__icon,
.top-action__text {
  color: #c9302c;
  font-size: $mb-font-sm;
  font-weight: 700;
}

.top-action__icon {
  margin-right: 8rpx;
}

.summary {
  position: relative;
  overflow: hidden;
  margin-top: 36rpx;
  padding: 32rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid rgba(233, 198, 146, 0.42);
  border-radius: 28rpx;
}

.summary::after {
  position: absolute;
  right: 34rpx;
  bottom: 18rpx;
  color: rgba(214, 154, 78, 0.12);
  font-size: 112rpx;
  font-weight: 800;
  content: '¥';
}

.section {
  margin-top: 34rpx;
}

.summary__label,
.section__title {
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-sm;
}

.summary__amount {
  display: flex;
  align-items: baseline;
  margin-top: 14rpx;
}

.summary__symbol {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-lg;
  font-weight: 700;
}

.summary__value {
  margin-left: 6rpx;
  color: var(--color-text-title, #191b23);
  font-size: 64rpx;
  font-weight: 800;
}

.section__header {
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 28rpx;
}

.section__title {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-xl;
  font-weight: 700;
}

.section__line {
  width: 88rpx;
  height: 2rpx;
  margin: 0 18rpx;
  background: linear-gradient(90deg, transparent, rgba(229, 158, 83, 0.8), transparent);
}

.section__dot {
  width: 12rpx;
  height: 12rpx;
  background: #d64232;
  transform: rotate(45deg);
}

.package-list {
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.package-card {
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 292rpx;
  padding: 34rpx 42rpx 30rpx;
  background-color: #fff9ee;
  background-position: center;
  background-repeat: no-repeat;
  background-size: cover;
  border: 3rpx solid rgba(236, 199, 145, 0.76);
  border-radius: 24rpx;
}

.package-card--active {
  border-color: var(--color-primary, #0d50d5);
  box-shadow: inset 0 0 0 1rpx var(--color-primary, #0d50d5);
}

.package-card__corner {
  position: absolute;
  top: -6rpx;
  right: -6rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 120rpx;
  height: 84rpx;
  background: linear-gradient(135deg, #f26b54 0%, #c32728 100%);
  transform: rotate(42deg) translate(30rpx, -14rpx);
}

.package-card__corner-text {
  color: #ffffff;
  font-size: $mb-font-sm;
  font-weight: 800;
}

.package-card__selected {
  position: absolute;
  top: 28rpx;
  right: 28rpx;
  display: flex;
  align-items: center;
  height: 52rpx;
  padding: 0 18rpx;
  background: var(--color-primary, #0d50d5);
  border-radius: $mb-radius-full;
}

.package-card__selected-dot {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28rpx;
  height: 28rpx;
  margin-right: 8rpx;
  color: var(--color-primary, #0d50d5);
  font-size: 20rpx;
  font-weight: 800;
  background: #ffffff;
  border-radius: 50%;
}

.package-card__selected-text {
  color: #ffffff;
  font-size: $mb-font-sm;
  font-weight: 700;
}

.package-card__main,
.package-card__metric {
  display: flex;
  flex-direction: column;
}

.package-card__name {
  color: #c9302c;
  font-size: 48rpx;
  font-weight: 800;
  line-height: 1.15;
}

.package-card__gift {
  align-self: flex-start;
  margin-top: 18rpx;
  padding: 8rpx 18rpx;
  color: #a56b19;
  font-size: $mb-font-sm;
  background: rgba(255, 248, 232, 0.86);
  border: 1rpx solid rgba(217, 154, 65, 0.5);
  border-radius: $mb-radius-sm;
}

.package-card__meta {
  display: flex;
  align-items: flex-end;
  margin-top: 34rpx;
}

.package-card__metric-label {
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-md;
}

.package-card__divider {
  width: 1rpx;
  height: 64rpx;
  margin: 0 42rpx 4rpx;
  background: rgba(170, 127, 82, 0.24);
}

.package-card__amount {
  margin-top: 8rpx;
  color: var(--color-primary, #0d50d5);
  font-size: 42rpx;
  font-weight: 800;
}

.package-card__pay {
  margin-top: 8rpx;
  color: var(--color-text-title, #191b23);
  font-size: 40rpx;
  font-weight: 800;
}

.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 56rpx 0;
}

.empty__title {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-md;
  font-weight: 700;
}

.empty__desc {
  margin-top: 8rpx;
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-sm;
}

.footer {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 5;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20rpx $mb-spacing-page calc(20rpx + env(safe-area-inset-bottom));
  background: var(--color-bg, #ffffff);
  border-top: 1rpx solid var(--color-divider, #f0f2f5);
}

.footer__info {
  display: flex;
  flex-direction: column;
}

.footer__label {
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-xs;
}

.footer__amount {
  margin-top: 4rpx;
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-xl;
  font-weight: 800;
}

.footer__button {
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 220rpx;
  height: $mb-btn-height-md;
  padding: 0 36rpx;
  background: var(--color-primary, #0d50d5);
  border-radius: $mb-radius-full;
}

.footer__button--disabled {
  opacity: 0.5;
}

.footer__button-text {
  color: #ffffff;
  font-size: $mb-font-md;
  font-weight: 700;
}
</style>
