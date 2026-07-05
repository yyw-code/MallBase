const STORAGE_KEY = 'mb_distribution_attribution'
const INVITE_CODE_KEY = 'mb_distribution_invite_code'

function normalizeQuery(query = {}) {
  const result = {}
  Object.keys(query || {}).forEach((key) => {
    const value = query[key]
    result[key] = Array.isArray(value) ? value[0] : value
  })
  return result
}

export function captureDistributionAttribution(query = {}, page = '') {
  const data = normalizeQuery(query)
  const inviteCode = String(data.invite_code || data.code || '').trim()
  if (!inviteCode) return null

  const attribution = {
    invite_code: inviteCode,
    dist_scene: String(data.dist_scene || 'share_link').slice(0, 32),
    dist_page: String(data.dist_page || page || '').slice(0, 128),
    dist_target_type: String(data.dist_target_type || '').slice(0, 32),
    dist_target_id: Number(data.dist_target_id || 0) || 0,
  }
  uni.setStorageSync(STORAGE_KEY, attribution)
  return attribution
}

export function getDistributionAttribution() {
  return uni.getStorageSync(STORAGE_KEY) || null
}

export function clearDistributionAttribution() {
  uni.removeStorageSync(STORAGE_KEY)
}

export function setDistributionInviteCode(inviteCode) {
  if (inviteCode) {
    uni.setStorageSync(INVITE_CODE_KEY, inviteCode)
  }
}

export function getDistributionInviteCode() {
  return uni.getStorageSync(INVITE_CODE_KEY) || ''
}

export function appendDistributionParams(path, options = {}) {
  const inviteCode = options.invite_code || getDistributionInviteCode()
  if (!inviteCode) return path

  const params = {
    invite_code: inviteCode,
    dist_scene: options.dist_scene || 'share_link',
    dist_page: options.dist_page || path.split('?')[0],
    dist_target_type: options.dist_target_type || '',
    dist_target_id: options.dist_target_id || 0,
  }
  const query = Object.entries(params)
    .filter(([, value]) => value !== '' && value !== undefined && value !== null)
    .map(([key, value]) => `${key}=${encodeURIComponent(value)}`)
    .join('&')

  return `${path}${path.includes('?') ? '&' : '?'}${query}`
}
