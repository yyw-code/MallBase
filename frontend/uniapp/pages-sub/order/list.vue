<script setup>
import { onLoad } from '@dcloudio/uni-app'

onLoad((query) => {
  const tab = query?.tab || ''
  if (tab) {
    uni.setStorageSync('order_initial_tab', tab)
  }
  uni.switchTab({ url: '/pages/order/index' })
})
</script>

<template>
  <view class="page">
    <mb-navbar title="我的订单" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #fff;
}
</style>
