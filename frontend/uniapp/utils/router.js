import { isLoggedIn } from './auth'

const AUTH_PAGES = [
  '/pages-sub/order/confirm',
  '/pages-sub/order/detail',
  '/pages-sub/order/pay-result',
  '/pages-sub/logistics/detail',
  '/pages-sub/address/list',
  '/pages-sub/address/edit',
  '/pages-sub/user/edit-profile',
  '/pages-sub/user/change-password',
  '/pages-sub/user/settings',
  '/pages-sub/user/bind-mobile',
  '/pages-sub/refund/apply',
  '/pages-sub/refund/list',
  '/pages-sub/refund/detail',
  '/pages-sub/review/post',
]

export function setupRouterGuard() {
  uni.addInterceptor('navigateTo', { invoke: checkAuth })
  uni.addInterceptor('redirectTo', { invoke: checkAuth })
}

function checkAuth(args) {
  const url = (args.url || '').split('?')[0]
  if (AUTH_PAGES.some((p) => url === p) && !isLoggedIn()) {
    uni.navigateTo({
      url: `/pages-sub/user/login?redirect=${encodeURIComponent(args.url)}`,
    })
    return false
  }
}
