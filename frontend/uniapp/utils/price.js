export function formatPrice(price) {
  const num = parseFloat(price)
  if (isNaN(num)) return '0.00'
  return num.toFixed(2)
}

export function formatPriceDisplay(price) {
  const num = parseFloat(price)
  if (isNaN(num)) return '¥0'
  if (num === Math.floor(num)) return `¥${num}`
  return `¥${num.toFixed(2)}`
}
