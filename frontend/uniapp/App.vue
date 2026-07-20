<script setup>
import { onLaunch, onShow } from '@dcloudio/uni-app'
import { fetchMaintenanceStatus } from '@/api/maintenance'
import { useAppStore } from '@/store/app'
import { useDecorateStore } from '@/store/decorate'
import { useUserStore } from '@/store/user'
import { captureDistributionAttribution } from '@/utils/distribution-attribution'
import { setupRouterGuard } from '@/utils/router'
import { handleMaintenanceBody } from '@/utils/maintenance'

onLaunch((options) => {
  const appStore = useAppStore()
  const decorateStore = useDecorateStore()
  const userStore = useUserStore()
  captureDistributionAttribution(options?.query || {}, options?.path || '')
  userStore.restoreToken()
  decorateStore.init()
  appStore.fetchBasicConfig()
  setupRouterGuard()
})

onShow(async () => {
  try {
    const status = await fetchMaintenanceStatus()
    handleMaintenanceBody({ data: status })
  } catch (_) {
    // 旧版本后端或瞬时网络异常不阻断应用恢复，业务请求仍会执行统一维护判断。
  }
})
</script>

<style>
page {
  --color-primary: #0d50d5;
  --color-primary-light: #386bef;
  --color-primary-on-bg: #0d50d5;
  --color-primary-on-page: #0d50d5;
  --color-primary-on-surface: #0d50d5;
  --color-primary-soft: rgba(13, 80, 213, 0.1);
  --color-primary-softer: rgba(13, 80, 213, 0.05);
  --color-primary-border: rgba(13, 80, 213, 0.24);
  --color-bg: #ffffff;
  --color-bg-secondary: #faf8ff;
  --color-bg-surface: #f3f3fe;
  --color-page-bg: #ffffff;
  --color-text: #191b23;
  --color-text-title: #191b23;
  --color-text-title-on-bg: #191b23;
  --color-text-title-on-page: #191b23;
  --color-text-title-on-surface: #191b23;
  --color-text-secondary: #434654;
  --color-text-secondary-on-bg: #434654;
  --color-text-secondary-on-page: #434654;
  --color-text-secondary-on-surface: #434654;
  --color-text-tertiary: #737686;
  --color-text-tertiary-on-bg: #737686;
  --color-text-tertiary-on-page: #737686;
  --color-text-tertiary-on-surface: #737686;
  --color-text-inverse: #ffffff;
  --color-text-on-bg: #191b23;
  --color-text-on-page: #191b23;
  --color-text-on-primary: #ffffff;
  --color-text-on-surface: #191b23;
  --color-border: #e0e4e8;
  --color-divider: #f0f2f5;
  --color-price: #ff5a1f;
  --color-price-soft: rgba(255, 90, 31, 0.1);
  --color-error: #ba1a1a;
  --color-error-on-bg: #ba1a1a;
  --color-error-on-page: #ba1a1a;
  --color-error-on-surface: #ba1a1a;
  --color-success: #34c759;
  --color-success-soft: rgba(52, 199, 89, 0.1);
  --color-warning: #f0ad4e;
  --color-warning-soft: rgba(240, 173, 78, 0.12);
  --color-error-soft: rgba(186, 26, 26, 0.1);
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

body .uni-actionsheet,
body .uni-actionsheet__menu,
body .uni-actionsheet__action,
body .uni-actionsheet__cell {
  color: #191b23 !important;
}

body .uni-actionsheet__menu,
body .uni-actionsheet__action {
  background-color: #fcfcfd !important;
}

body .uni-actionsheet__title {
  color: #737686 !important;
  background-color: #ffffff !important;
}

body .uni-actionsheet__cell:active {
  background-color: #ececec !important;
}

body .uni-actionsheet__cell::before {
  border-top-color: #e5e5e5 !important;
  color: #e5e5e5 !important;
}
</style>
