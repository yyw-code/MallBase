<template>
  <view class="page">
    <mb-navbar :title="title" />
    <scroll-view class="content" scroll-y>
      <rich-text v-if="htmlContent" :nodes="htmlContent" />
      <view v-else class="empty">
        <text class="empty-title">{{ title }}</text>
        <text class="empty-desc">相关内容暂未配置，请在后台客户端配置中完善。</text>
      </view>
    </scroll-view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useAppStore } from '@/store/app'

const appStore = useAppStore()
const type = ref('service')

onLoad((query) => {
  type.value = query?.type === 'privacy' ? 'privacy' : 'service'
  if (!appStore.siteConfig) {
    appStore.fetchBasicConfig()
  }
})

const title = computed(() => (type.value === 'privacy' ? '隐私权政策' : '服务协议'))
const htmlContent = computed(() => {
  const config = appStore.siteConfig || {}
  return type.value === 'privacy'
    ? (config.client_privacy || '')
    : (config.client_agreement || '')
})
</script>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: $mb-color-bg;
}

.content {
  box-sizing: border-box;
  height: calc(100vh - 96rpx);
  padding: 32rpx $mb-spacing-page 80rpx;
  font-size: $mb-font-md;
  line-height: 1.8;
  color: $mb-color-text;
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
  color: $mb-color-text;
}

.empty-desc {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}
</style>
