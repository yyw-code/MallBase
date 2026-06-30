<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar :title="title" />
    <scroll-view class="content" scroll-y>
      <rich-text v-if="htmlContent" :nodes="htmlContent" />
      <view v-else class="empty">
        <text class="empty-title">{{ title }}</text>
        <text class="empty-desc">相关内容暂未配置，请在后台客户端配置中完善。</text>
      </view>
    </scroll-view>
      <mb-floating-action />
</view>
</template>

<script setup>
import { useDecorateStore } from '@/store/decorate'
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useAppStore } from '@/store/app'
const decorateStore = useDecorateStore()

const appStore = useAppStore()
const type = ref('service')

const contentMap = {
  about: {
    title: '关于我们',
    field: 'client_about_content',
  },
  after_sale: {
    title: '售后政策',
    field: 'client_after_sale_policy',
  },
  privacy: {
    title: '隐私政策',
    field: 'client_privacy',
  },
  rules: {
    title: '平台规则',
    field: 'client_platform_rules',
  },
  service: {
    title: '用户协议',
    field: 'client_agreement',
  },
}

onLoad((query) => {
  type.value = contentMap[query?.type] ? query.type : 'service'
  if (!appStore.siteConfig) {
    appStore.fetchBasicConfig()
  }
})

const title = computed(() => contentMap[type.value]?.title || '用户协议')
const htmlContent = computed(() => {
  const config = appStore.siteConfig || {}
  const field = contentMap[type.value]?.field || 'client_agreement'
  return config[field] || ''
})
</script>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: var(--color-bg, #ffffff);
}

.content {
  box-sizing: border-box;
  height: calc(100vh - 96rpx);
  padding: 32rpx $mb-spacing-page 80rpx;
  font-size: $mb-font-md;
  line-height: 1.8;
  color: var(--color-text, #191b23);
}

.empty {
  min-height: 420rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16rpx;
  text-align: center;
}

.empty-title {
  font-size: $mb-font-xl;
  font-weight: 600;
  color: var(--color-text, #191b23);
}

.empty-desc {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}
</style>
