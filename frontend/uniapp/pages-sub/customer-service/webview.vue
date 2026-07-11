<template>
  <view class="customer-service-page">
    <view v-if="!targetUrl" class="customer-service-page__empty">
      <text class="customer-service-page__empty-title">客服入口已失效</text>
      <text class="customer-service-page__empty-text">{{ loadError }}</text>
      <view class="customer-service-page__empty-actions">
        <view class="customer-service-page__empty-button" @tap="goHome">回到首页</view>
        <view
          class="customer-service-page__empty-button customer-service-page__empty-button--primary"
          @tap="goBack"
        >
          返回上一页
        </view>
      </view>
    </view>

    <!-- #ifdef H5 -->
    <view v-else class="customer-service-page__loading">
      <text>正在打开在线客服…</text>
    </view>
    <!-- #endif -->

    <!-- #ifndef H5 -->
    <web-view v-else class="customer-service-page__content" :src="targetUrl" />
    <!-- #endif -->
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'

const CUSTOMER_SERVICE_URL_CACHE_KEY = 'mallbase_customer_service_url:current'
const CUSTOMER_SERVICE_URL_CACHE_MAX_AGE = 5 * 60 * 1000

const targetUrl = ref('')
const targetFromCache = ref(false)
const cacheKey = ref('')
const loadError = ref('请重新从页面客服入口打开')

onLoad((query) => {
  const url = readTargetUrl(query || {})
  if (!url) return

  targetUrl.value = url
  // #ifdef H5
  if (!targetFromCache.value) {
    targetUrl.value = ''
    loadError.value = '客服入口已失效，请重新从页面客服入口打开'
    return
  }
  openH5CustomerService(url)
  // #endif
})

function readTargetUrl(query) {
  targetFromCache.value = false
  const current = readCachedUrl(CUSTOMER_SERVICE_URL_CACHE_KEY)
  if (current) {
    targetFromCache.value = true
    cacheKey.value = CUSTOMER_SERVICE_URL_CACHE_KEY
    return current
  }

  const key = safeDecode(query.key || '')
  if (key) {
    const cached = readCachedUrl(key)
    if (cached) {
      targetFromCache.value = true
      cacheKey.value = key
      return cached
    }
  }

  return readLegacyUrl(query)
}

function readCachedUrl(key) {
  try {
    const cached = uni.getStorageSync(key)
    const url = cached && typeof cached === 'object' ? cached.url : ''
    const createdAt = Number(cached?.created_at || 0)
    if (!url || !createdAt || Date.now() - createdAt > CUSTOMER_SERVICE_URL_CACHE_MAX_AGE) {
      uni.removeStorageSync(key)
      return ''
    }
    return String(url)
  } catch {
    return ''
  }
}

function readLegacyUrl(query) {
  const url = safeDecode(query.url || '')
  if (!url) return ''

  return appendQuery(url, {
    contextToken: safeDecode(query.contextToken || ''),
    platform: safeDecode(query.platform || ''),
  })
}

function safeDecode(value) {
  let decoded = String(value || '')
  for (let index = 0; index < 3; index += 1) {
    try {
      const next = decodeURIComponent(decoded)
      if (next === decoded) break
      decoded = next
    } catch {
      break
    }
  }
  return decoded
}

function appendQuery(url, query) {
  const params = Object.entries(query)
    .filter(([key, value]) => value && !hasQueryParam(url, key))
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
    .join('&')
  if (!params) return url
  return `${url}${url.includes('?') ? '&' : '?'}${params}`
}

function hasQueryParam(url, key) {
  return new RegExp(`[?&]${key}=`).test(url)
}

// #ifdef H5
function openH5CustomerService(url) {
  try {
    const target = new URL(url)
    if (target.protocol !== 'http:' && target.protocol !== 'https:') {
      throw new Error('unsupported customer-service protocol')
    }
  } catch {
    targetUrl.value = ''
    loadError.value = '客服地址无效，请重新从页面客服入口打开'
    return
  }

  removeCachedUrl()
  window.location.replace(url)
}
// #endif

function goBack() {
  removeCachedUrl()
  uni.navigateBack({
    fail() {
      goHome()
    },
  })
}

function goHome() {
  removeCachedUrl()
  uni.reLaunch({ url: '/pages/index/index' })
}

function removeCachedUrl() {
  const keys = new Set([CUSTOMER_SERVICE_URL_CACHE_KEY, cacheKey.value].filter(Boolean))
  try {
    keys.forEach((key) => uni.removeStorageSync(key))
  } catch {}
  cacheKey.value = ''
}
</script>

<style scoped>
.customer-service-page {
  width: 100%;
  height: 100vh;
  overflow: hidden;
  background: #f6f8fb;
}

.customer-service-page__content {
  width: 100%;
  height: 100%;
}

.customer-service-page__loading,
.customer-service-page__empty {
  display: flex;
  height: 100%;
  box-sizing: border-box;
  align-items: center;
  justify-content: center;
  color: var(--color-text-tertiary, #737686);
  font-size: 26rpx;
}

.customer-service-page__empty {
  flex-direction: column;
  gap: 16rpx;
  padding: 48rpx;
}

.customer-service-page__empty-title {
  color: var(--color-text-title, #191b23);
  font-size: 30rpx;
  font-weight: 600;
  line-height: 1.35;
}

.customer-service-page__empty-text {
  line-height: 1.45;
  text-align: center;
}

.customer-service-page__empty-actions {
  display: flex;
  gap: 16rpx;
  margin-top: 8rpx;
}

.customer-service-page__empty-button {
  min-width: 160rpx;
  padding: 18rpx 24rpx;
  border: 1rpx solid var(--color-border, #d8dce6);
  border-radius: 12rpx;
  color: var(--color-text-regular, #303342);
  line-height: 1;
  text-align: center;
  background: #ffffff;
}

.customer-service-page__empty-button--primary {
  border-color: #2563eb;
  color: #ffffff;
  background: #2563eb;
}
</style>
