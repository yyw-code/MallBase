export function decimalToCents(value) {
  const raw = String(value ?? '').trim()
  if (!raw) return 0

  const normalized = raw.replace(/,/g, '')
  const match = normalized.match(/^(-?)(\d+)(?:\.(\d+))?$/)
  if (!match) return 0

  const sign = match[1] === '-' ? -1 : 1
  const yuan = match[2]
  const decimals = match[3] || ''
  const centText = decimals.padEnd(2, '0').slice(0, 2)
  const cents = Number(yuan) * 100 + Number(centText || 0)

  return sign * cents
}

export function centsToPrice(cents) {
  const amount = Number.isFinite(Number(cents)) ? Math.trunc(Number(cents)) : 0
  const sign = amount < 0 ? '-' : ''
  const abs = Math.abs(amount)
  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
}

export function normalizePrice(price) {
  return centsToPrice(decimalToCents(price))
}

export function multiplyPrice(price, quantity = 1) {
  const qty = Math.max(0, Math.trunc(Number(quantity) || 0))
  return centsToPrice(decimalToCents(price) * qty)
}

export function sumPrices(prices) {
  const cents = (Array.isArray(prices) ? prices : []).reduce(
    (sum, price) => sum + decimalToCents(price),
    0,
  )
  return centsToPrice(cents)
}

export function splitPrice(price) {
  const normalized = normalizePrice(price)
  const sign = normalized.startsWith('-') ? '-' : ''
  const unsigned = sign ? normalized.slice(1) : normalized
  const [integer = '0', decimal = '00'] = unsigned.split('.')
  const displayInteger = Number(integer).toLocaleString('zh-CN')
  return {
    integer: `${sign}${displayInteger}`,
    decimal: decimal.padEnd(2, '0').slice(0, 2),
  }
}

export function isZeroPrice(price) {
  return decimalToCents(price) === 0
}

export function isPositivePrice(price) {
  return decimalToCents(price) > 0
}

export function formatPrice(price) {
  return normalizePrice(price)
}

export function formatPriceDisplay(price) {
  const normalized = normalizePrice(price)
  if (normalized.endsWith('.00')) return `¥${normalized.slice(0, -3)}`
  return `¥${normalized}`
}
