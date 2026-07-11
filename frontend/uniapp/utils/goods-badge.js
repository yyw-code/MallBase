export const DEFAULT_GOODS_BADGE_CONFIG = {
  new: { text: '新品' },
  hot: { text: '热卖' },
  recommend: { text: '推荐' },
  style: {
    backgroundColor: '',
    borderRadius: 999,
    fontSize: 20,
    height: 36,
    paddingX: 14,
    textColor: '',
  },
}

function isEnabledFlag(value) {
  return value === true || value === 1 || value === '1' || value === 'true'
}

function parseJsonObject(value, fallback) {
  if (value && typeof value === 'object' && !Array.isArray(value)) return value
  if (typeof value !== 'string' || value.trim() === '') return fallback
  try {
    const parsed = JSON.parse(value)
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
      ? parsed
      : fallback
  } catch {
    return fallback
  }
}

function normalizeNumber(value, fallback, min, max) {
  const numberValue = Number(value)
  if (!Number.isFinite(numberValue)) return fallback
  return Math.min(Math.max(numberValue, min), max)
}

function normalizeOptionalHexColor(value) {
  const color = String(value || '').trim()
  if (color === '') return ''
  return /^#[\da-f]{6}$/i.test(color) ? color : ''
}

export function normalizeGoodsBadgeConfig(value) {
  const source = parseJsonObject(value, DEFAULT_GOODS_BADGE_CONFIG)
  const defaults = DEFAULT_GOODS_BADGE_CONFIG
  return {
    new: { text: String(source.new?.text || defaults.new.text).trim() },
    hot: { text: String(source.hot?.text || defaults.hot.text).trim() },
    recommend: {
      text: String(source.recommend?.text || defaults.recommend.text).trim(),
    },
    style: {
      backgroundColor: normalizeOptionalHexColor(source.style?.backgroundColor),
      borderRadius: normalizeNumber(
        source.style?.borderRadius,
        defaults.style.borderRadius,
        0,
        999,
      ),
      fontSize: normalizeNumber(
        source.style?.fontSize,
        defaults.style.fontSize,
        16,
        36,
      ),
      height: normalizeNumber(source.style?.height, defaults.style.height, 24, 60),
      paddingX: normalizeNumber(
        source.style?.paddingX,
        defaults.style.paddingX,
        6,
        40,
      ),
      textColor: normalizeOptionalHexColor(source.style?.textColor),
    },
  }
}

export function getGoodsBadgeText(goods, config) {
  if (!goods) return ''
  if (isEnabledFlag(goods.is_new) || isEnabledFlag(goods.is_new_arrival)) {
    return config.new.text
  }
  if (isEnabledFlag(goods.is_hot)) return config.hot.text
  if (isEnabledFlag(goods.is_recommend) || isEnabledFlag(goods.is_recommended)) {
    return config.recommend.text
  }
  return ''
}

export function getGoodsBadgeBoxStyle(config) {
  const style = config.style
  const badgeStyle = {
    borderRadius: `${style.borderRadius}rpx`,
    height: `${style.height}rpx`,
    padding: `0 ${style.paddingX}rpx`,
  }
  if (style.backgroundColor) {
    badgeStyle.backgroundColor = style.backgroundColor
  }
  return badgeStyle
}

export function getGoodsBadgeTextStyle(config) {
  const style = config.style
  const textStyle = {
    fontSize: `${style.fontSize}rpx`,
  }
  if (style.textColor) {
    textStyle.color = style.textColor
  }
  return textStyle
}
