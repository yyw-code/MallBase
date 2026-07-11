import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import test from 'node:test'

const customerServiceSource = readFileSync(new URL('./customer-service.js', import.meta.url), 'utf8')
const webviewSource = readFileSync(
  new URL('../pages-sub/customer-service/webview.vue', import.meta.url),
  'utf8',
)
const designSource = readFileSync(
  new URL('../../../docs/development/customer-service-h5-resource-action.md', import.meta.url),
  'utf8',
)

test('H5 uses top-level Widget navigation with product and order URL templates', () => {
  assert.match(webviewSource, /window\.location\.replace\(url\)/)
  assert.match(
    customerServiceSource,
    /query\.resourceUrlTemplates\s*=\s*JSON\.stringify\(buildHostResourceUrlTemplates\(\)\)/,
  )
  assert.match(customerServiceSource, /product:[\s\S]*?goods\/detail\?id=\{externalId\}/)
  assert.match(customerServiceSource, /order:[\s\S]*?order\/detail\?id=\{externalId\}/)
})

test('H5 contract does not retain the unused postMessage action adapter', () => {
  assert.equal(existsSync(new URL('./customer-service-action.mjs', import.meta.url)), false)
  assert.equal(existsSync(new URL('./customer-service-action.test.mjs', import.meta.url)), false)
})

test('H5 design documents the implemented top-level product and order contract', () => {
  assert.match(designSource, /顶层/)
  assert.match(designSource, /商品和订单/)
  assert.match(designSource, /resourceUrlTemplates/)
  assert.doesNotMatch(designSource, /仅支持 H5 iframe 场景/)
  assert.doesNotMatch(designSource, /接收客服 Widget 发出的 `CONNECTOR_ACTION`/)
})
