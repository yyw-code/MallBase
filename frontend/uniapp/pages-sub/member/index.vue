<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useDecorateStore } from '@/store/decorate'
import { useUserStore } from '@/store/user'
import { requireLogin } from '@/utils/auth'

const decorateStore = useDecorateStore()
const userStore = useUserStore()
const loading = ref(false)

const member = computed(() => userStore.userInfo?.member || {})
const enabled = computed(() => member.value?.enabled === true)
const account = computed(() => member.value?.account || {})
const totalGrowthValue = computed(() => Number(member.value?.total_growth_value || 0))
const levelLocked = computed(() => member.value?.level_locked === true)
const levelSourceText = computed(() => {
  if (!levelLocked.value) return '成长值自动匹配'
  const lockUntil = account.value?.level_lock_until
  return lockUntil ? `人工保级至 ${lockUntil}` : '人工保级中'
})

onShow(() => {
  if (!requireLogin('/pages-sub/member/index')) return
  fetchMember()
})

async function fetchMember() {
  loading.value = true
  try {
    await userStore.fetchUserInfo()
  } catch {
    uni.showToast({ title: '会员信息加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function goProfile() {
  uni.switchTab({ url: '/pages/profile/index' })
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="会员等级" bg-color="var(--color-bg, #ffffff)" />

    <view v-if="enabled" class="member-page__card">
      <mb-member-card
        :clickable="false"
        :enabled="enabled"
        :logged="true"
        :member="member"
        variant="full"
      />
    </view>

    <view v-if="enabled" class="stats-grid">
      <view class="stats-item">
        <text class="stats-item__label">累计成长值</text>
        <text class="stats-item__value">{{ totalGrowthValue }}</text>
      </view>
      <view class="stats-item">
        <text class="stats-item__label">等级状态</text>
        <text class="stats-item__value stats-item__value--text">{{ levelSourceText }}</text>
      </view>
    </view>

    <view v-if="enabled" class="section">
      <text class="section__title">成长规则</text>
      <view class="rule-row">
        <text class="rule-row__dot" />
        <text class="rule-row__text">订单完成后按实付金额累计成长值</text>
      </view>
      <view class="rule-row">
        <text class="rule-row__dot" />
        <text class="rule-row__text">等级折扣以结算页实时计算结果为准</text>
      </view>
      <view class="rule-row">
        <text class="rule-row__dot" />
        <text class="rule-row__text">后台人工保级时，自动升级会遵循锁定规则</text>
      </view>
    </view>

    <view v-if="!enabled && !loading" class="empty">
      <text class="empty__title">会员功能未开启</text>
      <text class="empty__desc">开启后可查看等级、成长值和会员权益</text>
      <view class="empty__btn" @tap="goProfile">
        <text class="empty__btn-text">返回个人中心</text>
      </view>
    </view>
    <mb-copyright-footer />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 48rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.member-page__card {
  margin-top: $mb-spacing-md;
}

.stats-grid,
.rule-row {
  display: flex;
  align-items: center;
}

.stats-item__label {
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-sm;
}

.stats-grid {
  gap: 20rpx;
  margin-top: 20rpx;
}

.stats-item {
  flex: 1;
  min-width: 0;
  padding: 26rpx;
  border-radius: $mb-radius-lg;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
}

.stats-item__value {
  display: block;
  margin-top: 12rpx;
  color: var(--color-text-title, #191b23);
  font-size: 32rpx;
  font-weight: 700;
}

.stats-item__value--text {
  font-size: $mb-font-md;
  line-height: 1.35;
}

.section {
  margin-top: 20rpx;
  padding: 28rpx;
  border-radius: $mb-radius-lg;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
}

.section__title {
  display: block;
  margin-bottom: 18rpx;
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-lg;
  font-weight: 700;
}

.rule-row {
  gap: 14rpx;
  padding: 12rpx 0;
}

.rule-row__dot {
  width: 10rpx;
  height: 10rpx;
  border-radius: 50%;
  background: var(--color-primary, #0d50d5);
}

.rule-row__text {
  flex: 1;
  min-width: 0;
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-sm;
  line-height: 1.5;
}

.empty {
  margin-top: 140rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.empty__title {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-lg;
  font-weight: 700;
}

.empty__desc {
  margin-top: 12rpx;
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-sm;
}

.empty__btn {
  margin-top: 28rpx;
  height: 72rpx;
  padding: 0 34rpx;
  display: flex;
  align-items: center;
  border-radius: $mb-radius-full;
  background: var(--color-primary, #0d50d5);
}

.empty__btn-text {
  color: #ffffff;
  font-size: $mb-font-md;
  font-weight: 600;
}
</style>
