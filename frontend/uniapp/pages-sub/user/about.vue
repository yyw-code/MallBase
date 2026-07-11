<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="关于我们" />

    <scroll-view class="content" scroll-y>
      <view class="brand-panel">
        <image class="brand-panel__logo" :src="logo" mode="aspectFit" />
        <text class="brand-panel__name">{{ siteName }}</text>
        <text class="brand-panel__slogan">{{ slogan }}</text>
      </view>

      <view class="section">
        <text class="section__title">项目定位</text>
        <view class="intro">
          <rich-text v-if="aboutContent" :nodes="aboutContent" />
          <text v-else class="intro__text">
            MallBase 是面向中小型商城业务的开源项目基础底座，覆盖客户端、后台管理、接口服务和部署路径，适合二次开发与长期维护。
          </text>
        </view>
      </view>

      <view class="section">
        <text class="section__title">服务信息</text>
        <view class="cell-group">
          <view class="cell" @tap="openAgreement('service')">
            <text class="cell__label">用户协议</text>
            <view class="cell__right">
              <view class="arrow-icon" />
            </view>
          </view>
          <view class="cell" @tap="openAgreement('privacy')">
            <text class="cell__label">隐私政策</text>
            <view class="cell__right">
              <view class="arrow-icon" />
            </view>
          </view>
          <view class="cell" @tap="openAgreement('rules')">
            <text class="cell__label">平台规则</text>
            <view class="cell__right">
              <view class="arrow-icon" />
            </view>
          </view>
          <view class="cell" @tap="openAgreement('after_sale')">
            <text class="cell__label">售后政策</text>
            <view class="cell__right">
              <view class="arrow-icon" />
            </view>
          </view>
          <view v-if="companyUrl" class="cell" @tap="openCompanyUrl">
            <text class="cell__label">公司主页</text>
            <view class="cell__right">
              <text class="cell__value">{{ companyName }}</text>
              <view class="arrow-icon" />
            </view>
          </view>
        </view>
      </view>

      <mb-copyright-footer />
      <view class="bottom-spacer" />
    </scroll-view>
      <mb-floating-action />
</view>
</template>

<script setup>
import { computed } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useAppStore } from '@/store/app'
import { useDecorateStore } from '@/store/decorate'

const appStore = useAppStore()
const decorateStore = useDecorateStore()

const siteConfig = computed(() => appStore.siteConfig || {})
const siteName = computed(() => siteConfig.value.client_site_name || siteConfig.value.site_name || 'MallBase')
const slogan = computed(() => (
  siteConfig.value.site_slogan ||
  siteConfig.value.client_share_desc ||
  '商城业务基础底座'
))
const logo = computed(() => siteConfig.value.client_logo || '/static/logo.png')
const aboutContent = computed(() => siteConfig.value.client_about_content || '')
const companyName = computed(() => siteConfig.value.copyright_company || 'MallBase Team')
const companyUrl = computed(() => siteConfig.value.copyright_company_url || '')
onShow(() => {
  if (!appStore.siteConfig) {
    appStore.fetchBasicConfig()
  }
})

function openAgreement(type) {
  uni.navigateTo({ url: `/pages-sub/user/agreement?type=${type}` })
}

function openCompanyUrl() {
  const url = companyUrl.value
  if (!url) return

  // #ifdef H5
  window.open(url, '_blank')
  // #endif

  // #ifndef H5
  uni.setClipboardData({
    data: url,
    success() {
      uni.showToast({ title: '链接已复制', icon: 'none' })
    },
  })
  // #endif
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

.brand-panel {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 48rpx 32rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
  text-align: center;
}

.brand-panel__logo {
  width: 128rpx;
  height: 128rpx;
  border-radius: $mb-radius-lg;
  background: var(--color-bg-secondary, #faf8ff);
}

.brand-panel__name {
  margin-top: 28rpx;
  font-size: 42rpx;
  font-weight: 700;
  line-height: 1.25;
  color: var(--color-text-title-on-bg, var(--color-text-title, #191b23));
}

.brand-panel__slogan {
  margin-top: 12rpx;
  font-size: $mb-font-md;
  line-height: 1.5;
  color: var(--color-text-secondary-on-bg, var(--color-text-secondary, #434654));
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

.intro {
  padding: 28rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.intro__text {
  font-size: $mb-font-md;
  line-height: 1.8;
  color: var(--color-text-on-bg, var(--color-text, #191b23));
}

.cell-group {
  overflow: hidden;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.cell {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 112rpx;
  gap: 20rpx;
  padding: 0 28rpx;

  &:not(:last-child)::after {
    content: '';
    position: absolute;
    right: 28rpx;
    bottom: 0;
    left: 28rpx;
    height: 1rpx;
    background: var(--color-divider, #f0f2f5);
  }

  &:active {
    background: var(--color-bg-surface, #f3f3fe);
  }
}

.cell__label {
  font-size: $mb-font-md;
  font-weight: 500;
  line-height: 1.4;
  color: var(--color-text-on-bg, var(--color-text, #191b23));
}

.cell__right {
  display: flex;
  align-items: center;
  gap: 12rpx;
  flex-shrink: 0;
}

.cell__value {
  max-width: 260rpx;
  overflow: hidden;
  font-size: $mb-font-sm;
  line-height: 1.4;
  color: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  text-overflow: ellipsis;
  white-space: nowrap;
}

.arrow-icon {
  width: 14rpx;
  height: 14rpx;
  border-right: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  border-bottom: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  transform: rotate(-45deg);
}

.bottom-spacer {
  height: calc(88rpx + env(safe-area-inset-bottom));
}
</style>
