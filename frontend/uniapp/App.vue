<script setup>
import { onLaunch } from '@dcloudio/uni-app'
import { useAppStore } from '@/store/app'
import { useUserStore } from '@/store/user'
import { setupRouterGuard } from '@/utils/router'

onLaunch(() => {
  const appStore = useAppStore()
  const userStore = useUserStore()
  userStore.restoreToken()
  appStore.initTheme()
  appStore.fetchBasicConfig()
  setupRouterGuard()
})
</script>

<style>
page {
  --color-primary: #0d50d5;
  --color-primary-light: #386bef;
  --color-bg: #ffffff;
  --color-bg-secondary: #f7f9fb;
  --color-text: #1b1b1b;
  --color-text-title: #131b2e;
  --color-text-secondary: #5e5e5e;
  --color-text-tertiary: #848484;
  --color-border: #e0e3e5;
  --color-error: #ba1a1a;
  --color-success: #34c759;
  --radius-sm: 8rpx;
  --radius-md: 16rpx;
  --radius-lg: 24rpx;
  --radius-xl: 32rpx;

  background-color: var(--color-bg-secondary);
  font-family: -apple-system, BlinkMacSystemFont, 'Helvetica Neue', 'PingFang SC', 'Microsoft YaHei', sans-serif;
  color: var(--color-text);
  font-size: 28rpx;
  -webkit-font-smoothing: antialiased;
}
</style>
