const TOKEN_KEY = 'mb_access_token'
const AUTH_SESSION_KEY = 'mb_auth_session_id'

export const AUTH_CLEARED_EVENT = 'mallbase:auth-cleared'

export function getToken() {
  return uni.getStorageSync(TOKEN_KEY) || ''
}

export function isLoggedIn() {
  return !!getToken()
}

function createAuthSessionId() {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
}

export function getAuthSessionId() {
  if (!isLoggedIn()) return ''
  const current = uni.getStorageSync(AUTH_SESSION_KEY)
  if (current) return String(current)

  const sessionId = createAuthSessionId()
  uni.setStorageSync(AUTH_SESSION_KEY, sessionId)
  return sessionId
}

export function rotateAuthSessionId() {
  const sessionId = createAuthSessionId()
  uni.setStorageSync(AUTH_SESSION_KEY, sessionId)
  return sessionId
}

export function notifyAuthCleared() {
  uni.removeStorageSync(AUTH_SESSION_KEY)
  uni.$emit(AUTH_CLEARED_EVENT)
}

export function requireLogin(redirectUrl) {
  if (!isLoggedIn()) {
    const url = redirectUrl ? `/pages-sub/user/login?redirect=${encodeURIComponent(redirectUrl)}` : '/pages-sub/user/login'
    uni.navigateTo({ url })
    return false
  }
  return true
}
