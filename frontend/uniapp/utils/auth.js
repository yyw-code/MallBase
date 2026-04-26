const TOKEN_KEY = 'mb_access_token'

export function getToken() {
  return uni.getStorageSync(TOKEN_KEY) || ''
}

export function isLoggedIn() {
  return !!getToken()
}

export function requireLogin(redirectUrl) {
  if (!isLoggedIn()) {
    const url = redirectUrl ? `/pages-sub/user/login?redirect=${encodeURIComponent(redirectUrl)}` : '/pages-sub/user/login'
    uni.navigateTo({ url })
    return false
  }
  return true
}
