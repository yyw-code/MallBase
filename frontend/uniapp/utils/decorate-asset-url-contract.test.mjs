import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const decorateUrl = new URL('./decorate.js', import.meta.url)
const rendererUrl = new URL(
  '../components/mb-decorate-renderer/mb-decorate-renderer.vue',
  import.meta.url,
)
const profileUrl = new URL('../pages/profile/index.vue', import.meta.url)

const decorateSource = await readFile(decorateUrl, 'utf8')
const rendererSource = await readFile(rendererUrl, 'utf8')
const profileSource = await readFile(profileUrl, 'utf8')

function loadAssetNormalizer(config, windowValue) {
  const start = decorateSource.indexOf('function resolveAssetOrigin')
  const end = decorateSource.indexOf('\nexport function isTabbarPage', start)
  assert.notEqual(start, -1, 'decorate.js should define resolveAssetOrigin')
  assert.notEqual(end, -1, 'decorate.js should keep normalizeAssetPath before isTabbarPage')

  const source = decorateSource
    .slice(start, end)
    .replace('export function normalizeAssetPath', 'function normalizeAssetPath')
  const factory = new Function(
    'config',
    'window',
    `${source}\nreturn { normalizeAssetPath };`,
  )

  return factory(config, windowValue).normalizeAssetPath
}

test('H5 resolves shared static paths from the API origin root', () => {
  const normalizeAssetPath = loadAssetNormalizer(
    { baseUrl: '' },
    { location: { origin: 'https://preview.example.com' } },
  )

  assert.equal(
    normalizeAssetPath('static/decorate/profile-order-pay.svg'),
    'https://preview.example.com/static/decorate/profile-order-pay.svg',
  )
  assert.equal(
    normalizeAssetPath('/static/decorate/profile-order-pay.svg'),
    'https://preview.example.com/static/decorate/profile-order-pay.svg',
  )
  assert.equal(
    normalizeAssetPath('/static/images/tabbar/home.png'),
    '/static/images/tabbar/home.png',
  )
})

test('mini program and App resolve paths from an absolute configured API base', () => {
  const normalizeAssetPath = loadAssetNormalizer(
    { baseUrl: 'https://api.example.com/client/api' },
    undefined,
  )

  assert.equal(
    normalizeAssetPath('static/decorate/floating/cart.png'),
    'https://api.example.com/static/decorate/floating/cart.png',
  )
  const normalizeWithoutApiBase = loadAssetNormalizer(
    { baseUrl: '' },
    undefined,
  )
  assert.equal(
    normalizeWithoutApiBase('static/decorate/floating/cart.png'),
    '',
  )
  assert.equal(
    normalizeWithoutApiBase('/static/images/tabbar/home.png'),
    '/static/images/tabbar/home.png',
  )
})

test('hydrated full URLs win and semantic icons stay invalid as image paths', () => {
  const normalizeAssetPath = loadAssetNormalizer(
    { baseUrl: '' },
    { location: { origin: 'https://preview.example.com' } },
  )

  assert.equal(
    normalizeAssetPath({
      full_url: 'https://cdn.example.com/profile-order-pay.svg',
      url: 'static/decorate/profile-order-pay.svg',
    }),
    'https://cdn.example.com/profile-order-pay.svg',
  )
  assert.equal(normalizeAssetPath('lucide:home'), '')
  assert.equal(normalizeAssetPath(''), '')
})

test('renderer and profile reuse the shared asset normalizer', () => {
  assert.match(rendererSource, /normalizeAssetPath/)
  assert.match(profileSource, /normalizeAssetPath/)
  assert.doesNotMatch(rendererSource, /function normalizeImageUrl\(/)
  assert.doesNotMatch(profileSource, /function normalizeProfileImageUrl\(/)
})
