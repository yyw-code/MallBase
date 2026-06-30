<script setup>
import { useDecorateStore } from '@/store/decorate'
import { onLoad } from '@dcloudio/uni-app'
const decorateStore = useDecorateStore()

onLoad((query) => {
  const tab = query?.tab || ''
  if (tab) {
    uni.setStorageSync('order_initial_tab', tab)
  }
  uni.switchTab({ url: '/pages/order/index' })
})
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="我的订单" />
      <mb-floating-action />
</view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}
</style>
