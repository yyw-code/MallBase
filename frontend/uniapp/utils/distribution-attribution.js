import { autoBindDistributionInvite } from '@/api/distribution/distribution'
import { getToken } from '@/utils/auth'

const STORAGE_KEY = 'mb_distribution_attribution'
const INVITE_CODE_KEY = 'mb_distribution_invite_code'

let autoBinding = false
let autoBindingKey = ''
let lastHandledAttributionKey = ''

function normalizeQuery(query = {}) {
  const result = {}
  Object.keys(query || {}).forEach((key) => {
    const value = query[key]
    result[key] = Array.isArray(value) ? value[0] : value
  })
  const sceneParams = parseSceneQuery(result.scene)
  return {
    ...sceneParams,
    ...result,
  }
}

function parseSceneQuery(scene) {
  const value = decodeSceneValue(scene)
  if (!value) return {}
  if (!value.includes('=')) {
    return normalizeCompactScene(value)
  }
  const params = value.split('&').reduce((result, pair) => {
    const [rawKey, ...rawValue] = pair.split('=')
    const key = decodeSceneValue(rawKey).trim()
    if (!key) return result
    result[key] = decodeSceneValue(rawValue.join('='))
    return result
  }, {})
  return normalizeSceneAliases(params)
}

function decodeSceneValue(value) {
  let result = String(value || '').trim()
  if (!result) return ''
  for (let i = 0; i < 2; i += 1) {
    try {
      const decoded = decodeURIComponent(result)
      if (decoded === result) break
      result = decoded
    } catch (_) {
      break
    }
  }
  return result
}

function normalizeCompactScene(value) {
  const [inviteCode, scene, targetType, targetId] = String(value || '')
    .split('.')
    .map((item) => decodeSceneValue(item))
  if (!inviteCode) return {}
  return normalizeSceneAliases({
    c: inviteCode,
    i: targetId || '',
    s: scene || 'l',
    t: targetType || '',
  })
}

function normalizeSceneAliases(params = {}) {
  return {
    ...params,
    invite_code: params.invite_code || params.ic || params.c || params.code || '',
    dist_scene: decodeSceneAlias(params.dist_scene || params.s || 'share_link'),
    dist_target_type: decodeTargetTypeAlias(params.dist_target_type || params.t || ''),
    dist_target_id: decodeTargetId(params.dist_target_id || params.id || params.i || 0),
  }
}

function decodeSceneAlias(value) {
  const scene = String(value || '').trim()
  return {
    l: 'share_link',
    m: 'manual',
    p: 'poster',
  }[scene] || scene || 'share_link'
}

function decodeTargetTypeAlias(value) {
  const targetType = String(value || '').trim()
  return {
    a: 'article',
    g: 'goods',
    p: 'page',
  }[targetType] || targetType
}

function decodeTargetId(value) {
  const id = String(value || '').trim()
  if (!id) return 0
  if (/^\d+$/.test(id)) return Number(id) || 0
  if (/^[0-9a-z]+$/i.test(id)) return parseInt(id, 36) || 0
  return Number(id) || 0
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
  tryAutoBindDistributionInvite().catch(() => {})
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

export async function tryAutoBindDistributionInvite() {
  if (!getToken()) {
    return { attempted: false, bound: false }
  }

  const attribution = getDistributionAttribution()
  const inviteCode = String(attribution?.invite_code || '').trim()
  if (!inviteCode) {
    return { attempted: false, bound: false }
  }

  const attributionKey = buildAttributionKey(attribution)
  if (autoBinding && attributionKey === autoBindingKey) {
    return { attempted: false, bound: false }
  }
  if (attributionKey === lastHandledAttributionKey) {
    clearDistributionAttribution()
    return { attempted: false, bound: false }
  }

  autoBinding = true
  autoBindingKey = attributionKey
  try {
    await autoBindDistributionInvite({
      invite_code: inviteCode,
      dist_page: attribution?.dist_page || '',
      dist_scene: attribution?.dist_scene || 'share_link',
      dist_target_id: attribution?.dist_target_id || 0,
      dist_target_type: attribution?.dist_target_type || '',
    })
    lastHandledAttributionKey = attributionKey
    clearDistributionAttribution()
    return { attempted: true, bound: true }
  } catch (error) {
    if (shouldClearAttributionAfterBindFail(error)) {
      lastHandledAttributionKey = attributionKey
      clearDistributionAttribution()
    }
    return { attempted: true, bound: false }
  } finally {
    autoBinding = false
    autoBindingKey = ''
  }
}

function buildAttributionKey(attribution) {
  return [
    attribution?.invite_code || '',
    attribution?.dist_scene || '',
    attribution?.dist_page || '',
    attribution?.dist_target_type || '',
    attribution?.dist_target_id || 0,
  ].join('|')
}

function shouldClearAttributionAfterBindFail(error) {
  const message = String(error?.message || '')
  if (!message) return false
  return [
    '已绑定邀请关系',
    '不能绑定自己为上级',
    '不能形成循环邀请关系',
    '邀请码无效或分销员已禁用',
    '分销功能未开启',
  ].some((item) => message.includes(item))
}
