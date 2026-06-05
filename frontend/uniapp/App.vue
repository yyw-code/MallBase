<script setup>
import { onLaunch } from '@dcloudio/uni-app'
import { useAppStore } from '@/store/app'
import { useDecorateStore } from '@/store/decorate'
import { useUserStore } from '@/store/user'
import { setupRouterGuard } from '@/utils/router'

onLaunch(() => {
  const appStore = useAppStore()
  const decorateStore = useDecorateStore()
  const userStore = useUserStore()
  userStore.restoreToken()
  appStore.initTheme()
  decorateStore.init()
  appStore.fetchBasicConfig()
  setupRouterGuard()
})
</script>

<style>
page {
  --color-primary: #0d50d5;
  --color-primary-light: #386bef;
  --color-bg: #ffffff;
  --color-bg-secondary: #faf8ff;
  --color-bg-surface: #f3f3fe;
  --color-text: #191b23;
  --color-text-title: #191b23;
  --color-text-secondary: #434654;
  --color-text-tertiary: #737686;
  --color-border: #e0e4e8;
  --color-divider: #f0f2f5;
  --color-error: #ba1a1a;
  --color-success: #34c759;
  --color-warning: #f0ad4e;
  --radius-sm: 8rpx;
  --radius-md: 12rpx;
  --radius-lg: 20rpx;
  --radius-xl: 20rpx;
  --shadow-card: none;
  --shadow-bar: 0 -1rpx 0 rgba(224, 228, 232, 0.9);

  background-color: var(--color-bg-secondary);
  font-family: -apple-system, BlinkMacSystemFont, 'Helvetica Neue', 'PingFang SC', 'Microsoft YaHei', sans-serif;
  color: var(--color-text);
  font-size: 28rpx;
  -webkit-font-smoothing: antialiased;
}

view,
text,
input,
button,
textarea,
scroll-view {
  box-sizing: border-box;
}

button {
  border-radius: 12rpx;
}
</style>
