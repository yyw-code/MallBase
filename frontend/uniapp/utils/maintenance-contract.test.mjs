import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const maintenanceSource = readFileSync(new URL('./maintenance.js', import.meta.url), 'utf8')
const requestSource = readFileSync(new URL('../api/request.js', import.meta.url), 'utf8')
const apiSource = readFileSync(new URL('../api/maintenance.js', import.meta.url), 'utf8')
const pageSource = readFileSync(new URL('../pages/system/maintenance.vue', import.meta.url), 'utf8')
const pagesSource = readFileSync(new URL('../pages.json', import.meta.url), 'utf8')
const appSource = readFileSync(new URL('../App.vue', import.meta.url), 'utf8')

test('maintenance redirect is single-flight and uses the main-package page', () => {
  assert.match(maintenanceSource, /let redirecting = false/)
  assert.match(maintenanceSource, /SYSTEM_MAINTENANCE/)
  assert.match(maintenanceSource, /uni\.reLaunch/)
  assert.match(maintenanceSource, /pages\/system\/maintenance/)
  assert.match(pagesSource, /"path": "pages\/system\/maintenance"/)
})

test('request and upload inspect the same maintenance contract before generic branches', () => {
  assert.match(requestSource, /allowMaintenanceResponse = false/)
  const maintenanceCalls = requestSource.match(/handleMaintenanceBody\(body\)/g) || []
  assert.equal(maintenanceCalls.length, 2)
  assert.ok(
    requestSource.indexOf('handleMaintenanceBody(body)') < requestSource.indexOf('body.code === 200'),
  )
  assert.ok(
    requestSource.lastIndexOf('handleMaintenanceBody(body)') <
      requestSource.lastIndexOf('body.code === 200'),
  )
  assert.ok(
    requestSource.indexOf('handleMaintenanceBody(body)') < requestSource.indexOf("uni.showToast({ title: message"),
  )
})

test('maintenance polling uses a real public route and keeps the traffic gate active', () => {
  assert.match(apiSource, /\/client\/api\/setting\/basic/)
  assert.doesNotMatch(apiSource, /\/upgrade\/api\/maintenance/)
  assert.doesNotMatch(apiSource, /allowMaintenanceResponse: true/)
  assert.match(apiSource, /redirectOnUnauthorized: false/)
  assert.match(apiSource, /showErrorToast: false/)
  assert.match(apiSource, /maintenance: false/)
  assert.match(apiSource, /state: 'normal'/)
})

test('page polls and app onShow silently checks maintenance state', () => {
  assert.match(pageSource, /fetchMaintenanceStatus/)
  assert.match(pageSource, /5000/)
  assert.match(pageSource, /pages\/index\/index/)
  assert.match(appSource, /import \{ onLaunch, onShow \}/)
  assert.match(appSource, /onShow\(/)
  assert.match(appSource, /fetchMaintenanceStatus/)
  assert.match(appSource, /handleMaintenanceBody/)
})
