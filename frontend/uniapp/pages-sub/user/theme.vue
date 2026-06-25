<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="主题设置" />

    <scroll-view class="content" scroll-y>
      <view class="summary">
        <view class="summary__body">
          <text class="summary__label">当前主题</text>
          <text class="summary__title">{{ themeLabel }}</text>
          <text class="summary__desc">{{ summaryText }}</text>
        </view>
        <view class="summary__preview">
          <view class="preview-dot preview-dot--primary" />
          <view class="preview-dot preview-dot--surface" />
          <view class="preview-dot preview-dot--text" />
        </view>
      </view>

      <view v-if="!allowUserThemeSelect" class="notice">
        <view class="notice__icon">
          <view class="notice-lock" />
        </view>
        <view class="notice__body">
          <text class="notice__title">主题由管理员统一设置</text>
          <text class="notice__desc">当前账号将跟随后台配置展示。</text>
        </view>
      </view>

      <view class="section">
        <text class="section__title">可选主题</text>
        <view class="option-list">
          <view
            v-for="item in themeOptions"
            :key="`${item.mode}-${item.theme_id || 0}`"
            class="theme-option"
            :class="{
              'theme-option--active': isActive(item),
              'theme-option--disabled': !allowUserThemeSelect,
            }"
            @tap="selectTheme(item)"
          >
            <view class="theme-option__swatches">
              <view
                v-for="color in swatchesOf(item)"
                :key="color"
                class="theme-option__swatch"
                :style="{ backgroundColor: color }"
              />
            </view>
            <view class="theme-option__body">
              <text class="theme-option__title">{{ item.label }}</text>
              <text class="theme-option__desc">{{ descriptionOf(item) }}</text>
            </view>
            <view v-if="isActive(item)" class="check-icon" />
          </view>
        </view>
      </view>

      <view class="bottom-spacer" />
    </scroll-view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { DEFAULT_DARK_THEME, DEFAULT_LIGHT_THEME } from '@/config/theme'
import { useDecorateStore } from '@/store/decorate'

const decorateStore = useDecorateStore()
const loadingKey = ref('')

const allowUserThemeSelect = computed(() => decorateStore.allowUserThemeSelect)
const themeOptions = computed(() => decorateStore.availableThemeOptions)
const themeLabel = computed(() => decorateStore.themeLabel)
const summaryText = computed(() => (
  allowUserThemeSelect.value
    ? '已保存为当前客户端偏好'
    : '后台统一配置已生效'
))

onShow(async () => {
  await decorateStore.fetchThemes({ force: true })
  await decorateStore.fetchMyThemePreference({ force: true })
})

function keyOf(item) {
  return `${item.mode}-${item.theme_id || 0}`
}

function isActive(item) {
  return (
    decorateStore.themeMode === item.mode &&
    Number(decorateStore.themeId || 0) === Number(item.theme_id || 0)
  )
}

function customThemeOf(item) {
  if (item.mode !== 'custom') return null
  return (decorateStore.customThemes || []).find(
    (theme) => Number(theme.id) === Number(item.theme_id),
  )
}

function normalizeTokens(value) {
  if (!value) return {}
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value)
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
        ? parsed
        : {}
    } catch (_) {
      return {}
    }
  }
  return typeof value === 'object' && !Array.isArray(value) ? value : {}
}

function swatchesOf(item) {
  if (item.mode === 'dark') {
    return [
      DEFAULT_DARK_THEME.colorPrimary,
      DEFAULT_DARK_THEME.colorBg,
      DEFAULT_DARK_THEME.colorText,
    ]
  }

  if (item.mode === 'custom') {
    const theme = customThemeOf(item)
    const tokens = normalizeTokens(theme?.tokens)
    return [
      tokens.colorPrimary || DEFAULT_LIGHT_THEME.colorPrimary,
      tokens.colorBg || DEFAULT_LIGHT_THEME.colorBg,
      tokens.colorText || DEFAULT_LIGHT_THEME.colorText,
    ]
  }

  if (item.mode === 'system') {
    return [
      DEFAULT_LIGHT_THEME.colorPrimary,
      DEFAULT_LIGHT_THEME.colorBg,
      DEFAULT_DARK_THEME.colorBg,
    ]
  }

  return [
    DEFAULT_LIGHT_THEME.colorPrimary,
    DEFAULT_LIGHT_THEME.colorBg,
    DEFAULT_LIGHT_THEME.colorText,
  ]
}

function descriptionOf(item) {
  if (item.mode === 'system') return '跟随设备外观自动切换'
  if (item.mode === 'dark') return '适合夜间与弱光环境'
  if (item.mode === 'custom') return '后台发布的品牌主题'
  return '清爽明亮的默认界面'
}

async function selectTheme(item) {
  if (!allowUserThemeSelect.value) {
    uni.showToast({ title: '主题由管理员统一设置', icon: 'none' })
    return
  }
  if (loadingKey.value || isActive(item)) return

  loadingKey.value = keyOf(item)
  try {
    const changed = await decorateStore.setThemeMode(item.mode, {
      theme_id: item.theme_id,
    })
    if (changed) {
      uni.showToast({ title: `已切换为${item.label}`, icon: 'none' })
    }
  } finally {
    loadingKey.value = ''
  }
}
</script>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

.content {
  box-sizing: border-box;
  height: calc(100vh - 96rpx);
  padding: $mb-spacing-md $mb-spacing-page 0;
}

.summary {
  display: flex;
  align-items: center;
  gap: 28rpx;
  padding: 32rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.summary__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.summary__label {
  font-size: $mb-font-sm;
  line-height: 1.4;
  color: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
}

.summary__title {
  font-size: 40rpx;
  font-weight: 700;
  line-height: 1.25;
  color: var(--color-text-title-on-bg, var(--color-text-title, #191b23));
}

.summary__desc {
  font-size: $mb-font-sm;
  line-height: 1.5;
  color: var(--color-text-secondary-on-bg, var(--color-text-secondary, #434654));
}

.summary__preview {
  position: relative;
  width: 104rpx;
  height: 104rpx;
  flex-shrink: 0;
}

.preview-dot {
  position: absolute;
  border: 4rpx solid var(--color-bg, #ffffff);
  border-radius: $mb-radius-full;
}

.preview-dot--primary {
  top: 0;
  left: 20rpx;
  width: 64rpx;
  height: 64rpx;
  background: var(--color-primary, #0d50d5);
}

.preview-dot--surface {
  right: 0;
  bottom: 8rpx;
  width: 56rpx;
  height: 56rpx;
  background: var(--color-bg-surface, #f3f3fe);
  border-color: var(--color-border, #e0e4e8);
}

.preview-dot--text {
  bottom: 0;
  left: 0;
  width: 42rpx;
  height: 42rpx;
  background: var(--color-text-on-bg, #191b23);
}

.notice {
  display: flex;
  gap: 20rpx;
  margin-top: $mb-spacing-lg;
  padding: 24rpx;
  background: var(--color-warning-soft, rgba(240, 173, 78, 0.12));
  border: 1rpx solid rgba(240, 173, 78, 0.24);
  border-radius: $mb-radius-lg;
}

.notice__icon {
  width: 44rpx;
  height: 44rpx;
  border-radius: $mb-radius-full;
  background: rgba(240, 173, 78, 0.18);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.notice-lock {
  position: relative;
  width: 20rpx;
  height: 16rpx;
  border: 3rpx solid var(--color-warning, #f0ad4e);
  border-radius: 4rpx;

  &::before {
    content: '';
    position: absolute;
    top: -14rpx;
    left: 3rpx;
    width: 8rpx;
    height: 14rpx;
    border: 3rpx solid var(--color-warning, #f0ad4e);
    border-bottom: 0;
    border-radius: 10rpx 10rpx 0 0;
  }
}

.notice__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}

.notice__title {
  font-size: $mb-font-md;
  font-weight: 600;
  line-height: 1.4;
  color: var(--color-text-title-on-page, var(--color-text-title, #191b23));
}

.notice__desc {
  font-size: $mb-font-sm;
  line-height: 1.5;
  color: var(--color-text-secondary-on-page, var(--color-text-secondary, #434654));
}

.section {
  margin-top: $mb-spacing-lg;
}

.section__title {
  display: block;
  margin: 0 8rpx 14rpx;
  font-size: $mb-font-sm;
  font-weight: 600;
  line-height: 1.4;
  color: var(--color-text-secondary-on-page, var(--color-text-secondary, #434654));
}

.option-list {
  overflow: hidden;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.theme-option {
  position: relative;
  display: flex;
  align-items: center;
  gap: 20rpx;
  min-height: 124rpx;
  padding: 24rpx 28rpx;

  &:not(:last-child)::after {
    content: '';
    position: absolute;
    right: 28rpx;
    bottom: 0;
    left: 132rpx;
    height: 1rpx;
    background: var(--color-divider, #f0f2f5);
  }

  &:not(.theme-option--disabled):active {
    background: var(--color-bg-surface, #f3f3fe);
  }
}

.theme-option--active {
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.05));
}

.theme-option--disabled {
  opacity: 0.72;
}

.theme-option__swatches {
  width: 84rpx;
  height: 60rpx;
  display: flex;
  align-items: center;
  flex-shrink: 0;
}

.theme-option__swatch {
  width: 40rpx;
  height: 40rpx;
  margin-left: -12rpx;
  border: 3rpx solid var(--color-bg, #ffffff);
  border-radius: $mb-radius-full;

  &:first-child {
    margin-left: 0;
  }
}

.theme-option__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.theme-option__title {
  font-size: $mb-font-md;
  font-weight: 600;
  line-height: 1.4;
  color: var(--color-text-title-on-bg, var(--color-text-title, #191b23));
}

.theme-option__desc {
  font-size: $mb-font-sm;
  line-height: 1.5;
  color: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
}

.check-icon {
  position: relative;
  width: 40rpx;
  height: 40rpx;
  border-radius: $mb-radius-full;
  background: var(--color-primary, #0d50d5);
  flex-shrink: 0;

  &::after {
    content: '';
    position: absolute;
    top: 10rpx;
    left: 14rpx;
    width: 10rpx;
    height: 18rpx;
    border-right: 4rpx solid var(--color-text-on-primary, #ffffff);
    border-bottom: 4rpx solid var(--color-text-on-primary, #ffffff);
    transform: rotate(45deg);
  }
}

.bottom-spacer {
  height: calc(88rpx + env(safe-area-inset-bottom));
}
</style>
