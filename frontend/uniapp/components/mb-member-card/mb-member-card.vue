<script setup>
import { computed } from 'vue'

const props = defineProps({
  member: {
    type: Object,
    default: () => ({}),
  },
  logged: {
    type: Boolean,
    default: true,
  },
  enabled: {
    type: Boolean,
    default: true,
  },
  variant: {
    type: String,
    default: 'compact',
  },
  title: {
    type: String,
    default: '',
  },
  clickable: {
    type: Boolean,
    default: true,
  },
  showDiscount: {
    type: Boolean,
    default: true,
  },
  showGrowth: {
    type: Boolean,
    default: true,
  },
  showProgress: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['tap'])

const visible = computed(
  () => props.logged && props.enabled && props.member?.enabled === true,
)
const level = computed(() => props.member?.level || null)
const account = computed(() => props.member?.account || {})
const levelName = computed(
  () => level.value?.name || account.value?.level_name || '普通会员',
)
const discountText = computed(() => {
  if (!props.showDiscount) return ''
  const text = props.member?.discount_text || ''
  return text || (props.variant === 'full' ? '暂无专属折扣' : '')
})
const growthValue = computed(() => Number(props.member?.growth_value || 0))
const growthToNext = computed(() => Number(props.member?.growth_to_next || 0))
const nextLevel = computed(() => props.member?.next_level || null)
const progressPercent = computed(() =>
  Math.max(0, Math.min(100, Number(props.member?.progress_percent || 0))),
)
const progressStyle = computed(() => ({ width: `${progressPercent.value}%` }))
const labelText = computed(
  () => props.title || (props.variant === 'full' ? '当前等级' : '会员等级'),
)
const nextLevelText = computed(() => {
  if (!nextLevel.value) return '已达最高等级'
  if (growthToNext.value <= 0) return `已满足 ${nextLevel.value.name} 条件`
  return `距 ${nextLevel.value.name} 还差 ${growthToNext.value} 成长值`
})

function handleTap(event) {
  if (!props.clickable) return
  if (event?.stopPropagation) {
    event.stopPropagation()
  }
  emit('tap')
}
</script>

<template>
  <view
    v-if="visible"
    class="mb-member-card"
    :class="[
      `mb-member-card--${variant}`,
      { 'mb-member-card--clickable': clickable },
    ]"
    @tap="handleTap"
  >
    <view class="mb-member-card__top">
      <view class="mb-member-card__title-group">
        <text class="mb-member-card__label">{{ labelText }}</text>
        <text class="mb-member-card__name">{{ levelName }}</text>
      </view>
      <view class="mb-member-card__aside">
        <view v-if="discountText" class="mb-member-card__badge">
          <text class="mb-member-card__badge-text">{{ discountText }}</text>
        </view>
        <view v-if="clickable" class="mb-member-card__arrow" />
      </view>
    </view>

    <view v-if="showGrowth || showProgress" class="mb-member-card__growth">
      <view v-if="variant === 'full' && showGrowth" class="mb-member-card__growth-head">
        <text class="mb-member-card__growth-label">成长值</text>
        <text class="mb-member-card__growth-value">{{ growthValue }}</text>
      </view>
      <view v-if="showProgress" class="mb-member-card__progress">
        <view class="mb-member-card__progress-bar" :style="progressStyle" />
      </view>
      <view v-if="showGrowth || showProgress" class="mb-member-card__meta">
        <text v-if="variant !== 'full' && showGrowth" class="mb-member-card__meta-text">
          成长值 {{ growthValue }}
        </text>
        <text
          class="mb-member-card__meta-text"
          :class="{ 'mb-member-card__meta-text--right': variant !== 'full' }"
        >
          {{ nextLevelText }}
        </text>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.mb-member-card {
  box-sizing: border-box;
  width: 100%;
  padding: 18rpx;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: 18rpx;
  background: var(--color-bg, #ffffff);
  color: var(--color-text-title, #191b23);
}

.mb-member-card--strip {
  padding: 20rpx 22rpx;
  border-radius: 16rpx;
}

.mb-member-card--full {
  padding: 34rpx;
  border-radius: 20rpx;
}

.mb-member-card__top,
.mb-member-card__aside,
.mb-member-card__growth-head,
.mb-member-card__meta {
  display: flex;
  align-items: center;
}

.mb-member-card__top,
.mb-member-card__growth-head,
.mb-member-card__meta {
  justify-content: space-between;
  gap: 16rpx;
}

.mb-member-card__title-group {
  min-width: 0;
  display: flex;
  flex: 1;
  flex-direction: column;
}

.mb-member-card__label,
.mb-member-card__growth-label {
  color: var(--color-text-tertiary, #737686);
  font-size: 20rpx;
  line-height: 1.3;
}

.mb-member-card--full .mb-member-card__label,
.mb-member-card--full .mb-member-card__growth-label {
  color: var(--color-text-secondary, #434654);
  font-size: 24rpx;
}

.mb-member-card__name {
  margin-top: 6rpx;
  color: var(--color-text-title, #191b23);
  font-size: 26rpx;
  font-weight: 700;
  line-height: 1.2;
}

.mb-member-card--strip .mb-member-card__name {
  font-size: 28rpx;
}

.mb-member-card--full .mb-member-card__name {
  margin-top: 10rpx;
  font-size: 42rpx;
}

.mb-member-card__aside {
  min-width: 0;
  flex-shrink: 0;
  gap: 12rpx;
}

.mb-member-card__badge {
  max-width: 220rpx;
  padding: 6rpx 14rpx;
  border-radius: 999rpx;
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.1));
}

.mb-member-card--full .mb-member-card__badge {
  max-width: 260rpx;
  padding: 10rpx 18rpx;
}

.mb-member-card__badge-text {
  display: block;
  overflow: hidden;
  color: var(--color-primary, #0d50d5);
  font-size: 20rpx;
  font-weight: 600;
  line-height: 1.3;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mb-member-card--full .mb-member-card__badge-text {
  font-size: 24rpx;
}

.mb-member-card__arrow {
  width: 14rpx;
  height: 14rpx;
  flex-shrink: 0;
  border-top: 3rpx solid var(--color-text-tertiary, #737686);
  border-right: 3rpx solid var(--color-text-tertiary, #737686);
  transform: rotate(45deg);
}

.mb-member-card__growth {
  margin-top: 14rpx;
}

.mb-member-card--full .mb-member-card__growth {
  margin-top: 34rpx;
}

.mb-member-card__growth-value {
  color: var(--color-text-title, #191b23);
  font-size: 32rpx;
  font-weight: 700;
}

.mb-member-card__progress {
  height: 10rpx;
  overflow: hidden;
  border-radius: 999rpx;
  background: var(--color-bg-surface, #f3f3fe);
}

.mb-member-card--full .mb-member-card__progress {
  margin-top: 16rpx;
  height: 14rpx;
}

.mb-member-card__progress-bar {
  height: 100%;
  border-radius: 999rpx;
  background: var(--color-primary, #0d50d5);
}

.mb-member-card__meta {
  margin-top: 10rpx;
}

.mb-member-card--full .mb-member-card__meta {
  margin-top: 14rpx;
}

.mb-member-card__meta-text {
  min-width: 0;
  color: var(--color-text-secondary, #434654);
  font-size: 20rpx;
  line-height: 1.4;
}

.mb-member-card__meta-text--right {
  flex: 1;
  overflow: hidden;
  text-align: right;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mb-member-card--full .mb-member-card__meta-text {
  font-size: 24rpx;
}
</style>
