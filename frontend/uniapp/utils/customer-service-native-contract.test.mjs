import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import test from 'node:test'

function source(relativeUrl) {
  return readFileSync(new URL(relativeUrl, import.meta.url), 'utf8')
}

async function importSource(relativeUrl) {
  const encoded = Buffer.from(source(relativeUrl)).toString('base64')
  return import(`data:text/javascript;base64,${encoded}`)
}

function createUnauthorizedHarness(currentRoute = 'pages/profile/index') {
  const start = requestSource.indexOf('const TOKEN_KEY')
  const end = requestSource.indexOf('function refreshAccessToken')
  const sourceBlock = requestSource.slice(start, end)
  const navigateCalls = []
  const removedKeys = []
  const authClearedEvents = []
  const uni = {
    removeStorageSync(key) {
      removedKeys.push(key)
    },
    navigateTo(options) {
      navigateCalls.push(options)
    },
  }
  const getCurrentPages = () => [{ route: currentRoute, options: {} }]
  const notifyAuthCleared = () => authClearedEvents.push('cleared')
  const create = new Function(
    'uni',
    'getCurrentPages',
    'notifyAuthCleared',
    `${sourceBlock}; return handleUnauthorized`,
  )
  return {
    handleUnauthorized: create(uni, getCurrentPages, notifyAuthCleared),
    authClearedEvents,
    navigateCalls,
    removedKeys,
  }
}

const entrySource = source('./customer-service.js')
const authSource = source('./auth.js')
const pageSource = source('../pages-sub/customer-service/index.vue')
const socketSource = source('./customer-service-socket.js')
const apiSource = source('../api/customer-service.js')
const requestSource = source('../api/request.js')
const pagesSource = source('../pages.json')
const adminSource = source('../../admin/apps/web-antd/src/views/client/config/index.vue')
const serviceSource = source('../../../backend/app/service/client/CustomerServiceContextService.php')
const designSource = source('../../../docs/development/customer-service-native-page.md')

test('native customer service page replaces Widget and web-view navigation', () => {
  assert.equal(existsSync(new URL('../pages-sub/customer-service/webview.vue', import.meta.url)), false)
  assert.doesNotMatch(pageSource, /<web-view|window\.location|resourceUrlTemplates/)
  assert.match(pageSource, /createExternalCustomerServiceConversation/)
  assert.match(pageSource, /socket\.emit\('conversation:join'/)
  assert.match(pageSource, /socket\.emit\('message:send'/)
  assert.match(pageSource, /socket\.on\('message:new'/)
  assert.match(pageSource, /uploadCustomerServiceAttachment/)

  const packageStart = pagesSource.indexOf('"root": "pages-sub/customer-service"')
  const packageEnd = pagesSource.indexOf('"root":', packageStart + 10)
  const customerServicePackage = pagesSource.slice(
    packageStart,
    packageEnd > packageStart ? packageEnd : undefined,
  )
  assert.match(customerServicePackage, /"path": "index"/)
  assert.doesNotMatch(customerServicePackage, /"path": "webview"/)
})

test('short-lived context handoff never places credentials in the page URL', () => {
  assert.match(entrySource, /mallbase_customer_service_launch:current/)
  assert.match(entrySource, /uni\.removeStorageSync\(CUSTOMER_SERVICE_LAUNCH_KEY\)/)
  assert.match(entrySource, /url: '\/pages-sub\/customer-service\/index'/)
  assert.doesNotMatch(entrySource, /contextToken[^\n]*encodeURIComponent/)
  assert.doesNotMatch(pageSource, /setStorageSync|visitorToken[^\n]*url/)
})

test('system customer service requires login and never accepts a client conversation key', () => {
  assert.match(entrySource, /requireCustomerServiceLogin/)
  assert.match(entrySource, /if \(!requireCustomerServiceLogin\(\)\) return false/)
  assert.match(entrySource, /if \(!isLoggedIn\(\)\) return false/)
  assert.doesNotMatch(entrySource, /conversation_key|options\.conversationKey/)
  assert.match(pageSource, /ensureCustomerServiceLogin/)
  assert.match(pageSource, /clearCustomerServiceLaunch/)
  assert.match(pageSource, /url: '\/pages-sub\/user\/login'/)
  assert.match(requestSource, /UNAUTHORIZED_REDIRECT_DEDUP_MS/)
  assert.match(requestSource, /now - lastUnauthorizedRedirectAt/)
})

test('concurrent unauthorized responses schedule only one login navigation', () => {
  const harness = createUnauthorizedHarness()
  harness.handleUnauthorized()
  harness.handleUnauthorized()

  assert.equal(harness.navigateCalls.length, 1)
  assert.equal(harness.authClearedEvents.length, 2)
  assert.deepEqual(harness.removedKeys, [
    'mb_access_token',
    'mb_refresh_token',
    'mb_access_token',
    'mb_refresh_token',
  ])
})

test('customer service unauthorized redirect never restores an old visitor session', () => {
  const harness = createUnauthorizedHarness('pages-sub/customer-service/index')
  harness.handleUnauthorized()

  assert.equal(harness.navigateCalls.length, 1)
  assert.equal(harness.navigateCalls[0].url, '/pages-sub/user/login')
  assert.equal(harness.authClearedEvents.length, 1)
  assert.match(pageSource, /uni\.\$on\(AUTH_CLEARED_EVENT, handleAuthCleared\)/)
  assert.match(pageSource, /getAuthSessionId\(\) !== authSessionId/)
  assert.match(pageSource, /登录状态已变化，请返回原页面重新进入客服/)
})

test('auth session identity survives token refresh and rotates on a new login', async () => {
  const storage = new Map([['mb_access_token', 'access-token-1']])
  const emitted = []
  globalThis.uni = {
    getStorageSync(key) {
      return storage.get(key) || ''
    },
    setStorageSync(key, value) {
      storage.set(key, value)
    },
    removeStorageSync(key) {
      storage.delete(key)
    },
    $emit(event) {
      emitted.push(event)
    },
  }

  try {
    const {
      getAuthSessionId,
      notifyAuthCleared,
      rotateAuthSessionId,
    } = await importSource('./auth.js')
    const originalSessionId = getAuthSessionId()
    storage.set('mb_access_token', 'refreshed-access-token')
    assert.equal(getAuthSessionId(), originalSessionId)

    const nextSessionId = rotateAuthSessionId()
    assert.notEqual(nextSessionId, originalSessionId)
    notifyAuthCleared()
    assert.equal(storage.has('mb_auth_session_id'), false)
    assert.deepEqual(emitted, ['mallbase:auth-cleared'])
  } finally {
    delete globalThis.uni
  }

  assert.match(authSource, /const AUTH_SESSION_KEY = 'mb_auth_session_id'/)
})

test('socket rejoin reloads messages missed while disconnected', () => {
  const joinedStart = pageSource.indexOf("socket.on('conversation:joined'")
  const joinedEnd = pageSource.indexOf("socket.on('message:new'", joinedStart)
  const joinedHandler = pageSource.slice(joinedStart, joinedEnd)
  assert.match(joinedHandler, /loadMessages\(\)/)
  assert.match(pageSource, /reloadMessagesPending = true/)
  assert.match(pageSource, /if \(reloadMessagesPending && pageVisible && !destroyed && sessionReady\.value\)/)
})

test('hidden customer service page disconnects the visitor socket', () => {
  const hideStart = pageSource.indexOf('onHide(() =>')
  const hideEnd = pageSource.indexOf('onUnload(() =>', hideStart)
  const hideHandler = pageSource.slice(hideStart, hideEnd)
  assert.match(hideHandler, /pageVisible = false/)
  assert.match(hideHandler, /reloadMessagesPending = false/)
  assert.match(hideHandler, /disconnectSocket\(\)/)

  const showStart = pageSource.indexOf('onShow(() =>')
  const showEnd = pageSource.indexOf('onHide(() =>', showStart)
  const showHandler = pageSource.slice(showStart, showEnd)
  assert.match(showHandler, /if \(!socket\) connectSocket\(\)/)
  assert.match(pageSource, /if \(pageVisible && !socket\) connectSocket\(\)/)
})

test('external protocol uses isolated DTO validation and UniApp Socket transport', () => {
  const externalRequestSource = requestSource.slice(requestSource.indexOf('function normalizeExternalBaseUrl'))
  assert.match(externalRequestSource, /typeof validate !== 'function'/)
  assert.doesNotMatch(externalRequestSource, /Authorization|Bearer/)
  assert.match(apiSource, /x-visitor-token/)
  assert.match(socketSource, /uni\.connectSocket/)
  assert.match(socketSource, /path: '\/socket\.io'/)
  assert.match(socketSource, /autoConnect: false/)
})

test('Widget URL is no longer a client configuration requirement', () => {
  const configuredSource = serviceSource.slice(
    serviceSource.indexOf('private function isClientConfigured'),
    serviceSource.indexOf('private function disabledPayload'),
  )
  assert.match(configuredSource, /apiBase\(\) !== ''/)
  assert.match(configuredSource, /socketBase\(\) !== ''/)
  assert.doesNotMatch(configuredSource, /widgetUrl/)
  assert.doesNotMatch(adminSource, /customer_service_widget_url|Widget 地址/)
})

test('resource routes accept only local product and order ids', async () => {
  const {
    buildCustomerServiceResourceRoute,
    normalizeCustomerServiceConversationResource,
    parseCustomerServiceResourceCard,
  } = await importSource('./customer-service-resource.js')

  assert.equal(buildCustomerServiceResourceRoute('goods', '12'), '/pages-sub/goods/detail?id=12')
  assert.equal(buildCustomerServiceResourceRoute('order', 34), '/pages-sub/order/detail?id=34')
  assert.equal(buildCustomerServiceResourceRoute('product', '../settings'), '')
  assert.equal(buildCustomerServiceResourceRoute('url', '12'), '')

  assert.deepEqual(
    normalizeCustomerServiceConversationResource({
      type: 'product',
      externalId: '8',
      title: '测试商品',
      url: 'https://untrusted.example/path',
    }),
    {
      type: 'product',
      externalId: '8',
      label: '商品',
      title: '测试商品',
      summary: '',
      route: '/pages-sub/goods/detail?id=8',
    },
  )

  const card = parseCustomerServiceResourceCard(JSON.stringify({
    resourceType: 'order',
    externalId: '9',
    title: '测试订单',
    url: 'https://untrusted.example/path',
    action: { code: 'DELETE_ANYTHING', params: { externalId: '9' } },
  }))
  assert.equal(card.route, '/pages-sub/order/detail?id=9')
  assert.equal('url' in card, false)
  assert.equal('action' in card, false)
})

test('native design documents runtime and mini-program boundaries', () => {
  assert.match(designSource, /H5 和微信小程序/)
  assert.match(designSource, /request.*uploadFile.*socket/s)
  assert.match(designSource, /visitorToken.*内存/s)
  assert.match(designSource, /WebRTC/)
})
