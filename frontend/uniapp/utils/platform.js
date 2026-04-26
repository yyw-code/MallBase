export function getPlatform() {
  // #ifdef MP-WEIXIN
  return 'mp-weixin'
  // #endif
  // #ifdef H5
  const ua = navigator.userAgent.toLowerCase()
  if (ua.includes('micromessenger')) return 'h5-wechat'
  return 'h5'
  // #endif
  // #ifdef APP-PLUS
  return 'app'
  // #endif
}

export function isWechatEnv() {
  const p = getPlatform()
  return p === 'mp-weixin' || p === 'h5-wechat'
}

export function isMpWeixin() {
  // #ifdef MP-WEIXIN
  return true
  // #endif
  return false
}
