import { getUniAppBaseInfo } from '@/utils/system-info'

export const DEFAULT_LIGHT_THEME = {
  colorPrimary: '#0d50d5',
  colorPrimaryLight: '#386bef',
  colorBg: '#ffffff',
  colorBgSecondary: '#faf8ff',
  colorBgSurface: '#f3f3fe',
  colorText: '#191b23',
  colorTextTitle: '#191b23',
  colorTextSecondary: '#434654',
  colorTextTertiary: '#737686',
  colorBorder: '#e0e4e8',
  colorDivider: '#f0f2f5',
  colorPrice: '#ff5a1f',
  colorError: '#ba1a1a',
  colorSuccess: '#34c759',
  colorWarning: '#f0ad4e',
}

export const DEFAULT_DARK_THEME = {
  colorPrimary: '#386bef',
  colorPrimaryLight: '#6f97ff',
  colorBg: '#10131a',
  colorBgSecondary: '#151923',
  colorBgSurface: '#1b202a',
  colorText: '#f2f5fa',
  colorTextTitle: '#ffffff',
  colorTextSecondary: '#c9d1df',
  colorTextTertiary: '#9aa4b5',
  colorBorder: '#303746',
  colorDivider: '#262c38',
  colorPrice: '#ff7a45',
  colorError: '#ff6b6b',
  colorSuccess: '#4ade80',
  colorWarning: '#fbbf24',
}

export const DEFAULT_THEME = DEFAULT_LIGHT_THEME

export const THEME_STORAGE_KEY = 'mb_theme_mode'
export const CUSTOM_THEME_STORAGE_KEY = 'mb_custom_theme_tokens'

const SUPPORTED_THEME_MODES = ['system', 'light', 'dark', 'custom']
const CUSTOM_THEME_TYPE = 'custom'
const DARK_READABLE_TEXT = '#14161c'
const LIGHT_READABLE_TEXT = '#ffffff'
const MIN_TEXT_CONTRAST = 4.5

function camelToKebab(str) {
  return str.replace(/([A-Z])/g, '-$1').toLowerCase()
}

function normalizeTokenValue(value) {
  if (!value) return {}
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value)
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
        ? parsed
        : {}
    } catch (_) {
      return {}
    }
  }
  return typeof value === 'object' && !Array.isArray(value) ? value : {}
}

function normalizeThemeId(value) {
  if (value === undefined || value === null || value === '') return null
  const id = Number(value)
  return Number.isFinite(id) && id > 0 ? id : null
}

function normalizeThemeType(type) {
  return ['light', 'dark', CUSTOM_THEME_TYPE].includes(type)
    ? type
    : CUSTOM_THEME_TYPE
}

function hexToRgb(value) {
  const color = String(value || '').trim()
  const shortHex = color.match(/^#([\da-f])([\da-f])([\da-f])$/i)
  const fullHex = color.match(/^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i)
  const match = fullHex || shortHex
  if (!match) return null
  return {
    b: Number.parseInt(fullHex ? match[3] : `${match[3]}${match[3]}`, 16),
    g: Number.parseInt(fullHex ? match[2] : `${match[2]}${match[2]}`, 16),
    r: Number.parseInt(fullHex ? match[1] : `${match[1]}${match[1]}`, 16),
  }
}

function rgba(value, alpha, fallback) {
  const rgb = hexToRgb(value)
  return rgb ? `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})` : fallback
}

function channelToLinear(value) {
  const channel = value / 255
  return channel <= 0.03928
    ? channel / 12.92
    : Math.pow((channel + 0.055) / 1.055, 2.4)
}

function relativeLuminance(value) {
  const rgb = hexToRgb(value)
  if (!rgb) return null
  return (
    0.2126 * channelToLinear(rgb.r) +
    0.7152 * channelToLinear(rgb.g) +
    0.0722 * channelToLinear(rgb.b)
  )
}

function contrastRatio(foreground, background) {
  const foregroundLuminance = relativeLuminance(foreground)
  const backgroundLuminance = relativeLuminance(background)
  if (foregroundLuminance === null || backgroundLuminance === null) return 0
  const lighter = Math.max(foregroundLuminance, backgroundLuminance)
  const darker = Math.min(foregroundLuminance, backgroundLuminance)
  return (lighter + 0.05) / (darker + 0.05)
}

function isLightColor(value) {
  const luminance = relativeLuminance(value)
  return luminance !== null && luminance > 0.62
}

function getReadableTextColor(background) {
  const darkContrast = contrastRatio(DARK_READABLE_TEXT, background)
  const lightContrast = contrastRatio(LIGHT_READABLE_TEXT, background)
  if (darkContrast <= 0 && lightContrast <= 0) return DARK_READABLE_TEXT
  return darkContrast >= lightContrast ? DARK_READABLE_TEXT : LIGHT_READABLE_TEXT
}

function ensureReadableTextColor(
  color,
  background,
  fallback = getReadableTextColor(background),
  minContrast = MIN_TEXT_CONTRAST,
) {
  if (color && contrastRatio(color, background) >= minContrast) return color
  if (fallback && contrastRatio(fallback, background) >= minContrast) return fallback
  return getReadableTextColor(background)
}

function deriveReadableTextTokens(source, background, keys = {}) {
  const text = ensureReadableTextColor(
    source[keys.text] || source.colorText,
    background,
  )
  const title = ensureReadableTextColor(
    source[keys.title] || source.colorTextTitle || source.colorText,
    background,
    text,
  )
  const secondary = ensureReadableTextColor(
    source[keys.secondary] || source.colorTextSecondary || source.colorText,
    background,
    text,
  )
  const tertiary = ensureReadableTextColor(
    source[keys.tertiary] ||
      source.colorTextTertiary ||
      source.colorTextSecondary ||
      source.colorText,
    background,
    secondary,
  )

  return {
    secondary,
    tertiary,
    text,
    title,
  }
}

export function withDerivedThemeTokens(tokens = {}) {
  const source = {
    ...DEFAULT_LIGHT_THEME,
    ...normalizeTokenValue(tokens),
  }
  const primary = source.colorPrimary || DEFAULT_LIGHT_THEME.colorPrimary
  const bg = source.colorBg || DEFAULT_LIGHT_THEME.colorBg
  const pageBg = source.colorPageBg || source.colorBgSecondary || bg
  const surface = source.colorBgSurface || bg
  const price = source.colorPrice || DEFAULT_LIGHT_THEME.colorPrice
  const textOnBg = deriveReadableTextTokens(source, bg, {
    secondary: 'colorTextSecondaryOnBg',
    tertiary: 'colorTextTertiaryOnBg',
    text: 'colorTextOnBg',
    title: 'colorTextTitleOnBg',
  })
  const textOnSurface = deriveReadableTextTokens(source, surface, {
    secondary: 'colorTextSecondaryOnSurface',
    tertiary: 'colorTextTertiaryOnSurface',
    text: 'colorTextOnSurface',
    title: 'colorTextTitleOnSurface',
  })
  const textOnPage = deriveReadableTextTokens(source, pageBg, {
    secondary: 'colorTextSecondaryOnPage',
    tertiary: 'colorTextTertiaryOnPage',
    text: 'colorTextOnPage',
    title: 'colorTextTitleOnPage',
  })
  const textOnPrimary = ensureReadableTextColor(
    source.colorTextOnPrimary || source.colorTextInverse,
    primary,
    isLightColor(primary) ? DARK_READABLE_TEXT : LIGHT_READABLE_TEXT,
  )

  return {
    ...source,
    colorErrorOnBg: ensureReadableTextColor(
      source.colorErrorOnBg || source.colorError,
      bg,
      textOnBg.text,
    ),
    colorErrorOnPage: ensureReadableTextColor(
      source.colorErrorOnPage || source.colorError,
      pageBg,
      textOnPage.text,
    ),
    colorErrorOnSurface: ensureReadableTextColor(
      source.colorErrorOnSurface || source.colorError,
      surface,
      textOnSurface.text,
    ),
    colorPageBg: pageBg,
    colorPrimaryOnBg: ensureReadableTextColor(
      source.colorPrimaryOnBg || source.colorPrimary,
      bg,
      textOnBg.text,
    ),
    colorPrimaryOnPage: ensureReadableTextColor(
      source.colorPrimaryOnPage || source.colorPrimary,
      pageBg,
      textOnPage.text,
    ),
    colorPrimaryOnSurface: ensureReadableTextColor(
      source.colorPrimaryOnSurface || source.colorPrimary,
      surface,
      textOnSurface.text,
    ),
    colorPrimaryLight: source.colorPrimaryLight || primary,
    colorPrimarySoft:
      source.colorPrimarySoft || rgba(primary, 0.1, surface),
    colorPrimarySofter:
      source.colorPrimarySofter || rgba(primary, 0.05, surface),
    colorPrimaryBorder:
      source.colorPrimaryBorder || rgba(primary, 0.24, source.colorBorder),
    colorPriceSoft: source.colorPriceSoft || rgba(price, 0.1, surface),
    colorSuccessSoft:
      source.colorSuccessSoft || rgba(source.colorSuccess, 0.1, surface),
    colorText: textOnBg.text,
    colorTextInverse: textOnPrimary,
    colorTextOnBg: textOnBg.text,
    colorTextOnPage: textOnPage.text,
    colorTextOnPrimary: textOnPrimary,
    colorTextOnSurface: textOnSurface.text,
    colorTextSecondary: textOnBg.secondary,
    colorTextSecondaryOnBg: textOnBg.secondary,
    colorTextSecondaryOnPage: textOnPage.secondary,
    colorTextSecondaryOnSurface: textOnSurface.secondary,
    colorTextTertiary: textOnBg.tertiary,
    colorTextTertiaryOnBg: textOnBg.tertiary,
    colorTextTertiaryOnPage: textOnPage.tertiary,
    colorTextTertiaryOnSurface: textOnSurface.tertiary,
    colorTextTitle: textOnBg.title,
    colorTextTitleOnBg: textOnBg.title,
    colorTextTitleOnPage: textOnPage.title,
    colorTextTitleOnSurface: textOnSurface.title,
    colorWarningSoft:
      source.colorWarningSoft || rgba(source.colorWarning, 0.12, surface),
    colorErrorSoft: source.colorErrorSoft || rgba(source.colorError, 0.1, surface),
  }
}

export function canDetectSystemTheme() {
  const info = getUniAppBaseInfo()
  if (['light', 'dark'].includes(info?.theme)) return true

  // #ifdef H5
  if (
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function'
  ) {
    return typeof window.matchMedia('(prefers-color-scheme: dark)').matches === 'boolean'
  }
  // #endif

  return false
}

export function getSystemThemeMode() {
  const info = getUniAppBaseInfo()
  if (info?.theme === 'dark') return 'dark'
  if (info?.theme === 'light') return 'light'

  // #ifdef H5
  if (
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-color-scheme: dark)').matches
  ) {
    return 'dark'
  }
  // #endif

  return 'light'
}

export function normalizeThemeSetting(setting = {}) {
  const adminThemeMode = normalizeThemeMode(
    setting.admin_theme_mode ||
    setting.adminThemeMode ||
    setting.default_mode ||
    setting.defaultMode ||
    'system',
  )
  return {
    admin_theme_id:
      adminThemeMode === CUSTOM_THEME_TYPE
        ? normalizeThemeId(
            setting.admin_theme_id ??
            setting.adminThemeId ??
            setting.default_theme_id ??
            setting.defaultThemeId,
          )
        : null,
    admin_theme_mode: adminThemeMode,
    user_select_enabled: Number(
      setting.user_select_enabled ??
      setting.userSelectEnabled ??
      setting.allow_user_select ??
      setting.allowUserSelect ??
      1,
    ),
  }
}

export function normalizeThemePolicy(policy = {}) {
  const setting = normalizeThemeSetting(policy)
  return {
    allow_user_select: setting.user_select_enabled,
    default_mode: setting.admin_theme_mode,
    default_theme_id: setting.admin_theme_id,
  }
}

export function normalizeThemeMode(mode) {
  if (SUPPORTED_THEME_MODES.includes(mode)) return mode
  return 'system'
}

export function normalizeThemeSelection(value, fallback = { mode: 'system', theme_id: null }) {
  const base =
    fallback && typeof fallback === 'object'
      ? fallback
      : { mode: normalizeThemeMode(fallback), theme_id: null }

  if (typeof value === 'string') {
    return {
      mode: normalizeThemeMode(value || base.mode),
      theme_id: null,
    }
  }

  if (value && typeof value === 'object') {
    return {
      mode: normalizeThemeMode(value.mode || value.theme_mode || value.themeMode || base.mode),
      theme_id: normalizeThemeId(value.theme_id ?? value.themeId ?? value.id ?? base.theme_id),
    }
  }

  return {
    mode: normalizeThemeMode(base.mode),
    theme_id: normalizeThemeId(base.theme_id ?? base.themeId),
  }
}

export function resolveThemeMode(mode) {
  const normalized = normalizeThemeMode(mode)
  return normalized === 'system' ? getSystemThemeMode() : normalized
}

function normalizeThemeItem(item, fallbackTokens) {
  const type = normalizeThemeType(item?.type)
  const rawTokens =
    item && Object.prototype.hasOwnProperty.call(item, 'tokens')
      ? item.tokens
      : item?.id || item?.name || item?.type
        ? {}
        : item
  const tokens = {
    ...fallbackTokens,
    ...normalizeTokenValue(rawTokens),
  }

  return {
    ...item,
    id: normalizeThemeId(item?.id),
    name: item?.name || (type === CUSTOM_THEME_TYPE ? '自定义主题' : ''),
    tokens: withDerivedThemeTokens(tokens),
    type,
  }
}

function normalizeThemeObject(themes) {
  const result = {
    custom: null,
    customList: [],
    customMap: {},
    dark: withDerivedThemeTokens(DEFAULT_DARK_THEME),
    light: withDerivedThemeTokens(DEFAULT_LIGHT_THEME),
  }

  if (themes?.light) {
    result.light = withDerivedThemeTokens({
      ...DEFAULT_LIGHT_THEME,
      ...normalizeTokenValue(themes.light?.tokens || themes.light),
    })
  }

  if (themes?.dark) {
    result.dark = withDerivedThemeTokens({
      ...DEFAULT_DARK_THEME,
      ...normalizeTokenValue(themes.dark?.tokens || themes.dark),
    })
  }

  const customSource = themes?.customList || themes?.customThemes || themes?.custom
  if (Array.isArray(customSource)) {
    result.customList = customSource.map((item) =>
      normalizeThemeItem(item, DEFAULT_LIGHT_THEME),
    )
  } else if (customSource) {
    result.customList = [normalizeThemeItem(customSource, DEFAULT_LIGHT_THEME)]
  }

  result.customList.forEach((item) => {
    if (item.id) result.customMap[item.id] = item
  })
  result.custom = result.customList[0]?.tokens || null
  return result
}

export function normalizeThemes(themes = {}) {
  if (!Array.isArray(themes)) {
    return normalizeThemeObject(themes)
  }

  const result = normalizeThemeObject({})
  const customList = []

  themes.forEach((item) => {
    if (!item || !item.type) return
    const type = normalizeThemeType(item.type)
    const fallbackTokens = type === 'dark' ? DEFAULT_DARK_THEME : DEFAULT_LIGHT_THEME
    const normalized = normalizeThemeItem(item, fallbackTokens)
    if (type === CUSTOM_THEME_TYPE) {
      customList.push(normalized)
      return
    }
    result[type] = normalized.tokens
  })

  result.customList = customList
  result.customMap = {}
  customList.forEach((item) => {
    if (item.id) result.customMap[item.id] = item
  })
  result.custom = customList[0]?.tokens || null
  return result
}

export function getCustomThemeById(themes, themeId) {
  const normalizedThemes = normalizeThemes(themes)
  const id = normalizeThemeId(themeId)
  if (id && normalizedThemes.customMap[id]) return normalizedThemes.customMap[id]
  return normalizedThemes.customList[0] || null
}

export function getThemeTokens(themes, selection, customTokens = {}) {
  const normalizedThemes = normalizeThemes(themes)
  const normalizedSelection = normalizeThemeSelection(selection)
  if (normalizedSelection.mode === CUSTOM_THEME_TYPE) {
    const theme = getCustomThemeById(normalizedThemes, normalizedSelection.theme_id)
    return withDerivedThemeTokens({
      ...DEFAULT_LIGHT_THEME,
      ...(theme?.tokens || normalizedThemes.custom || {}),
      ...normalizeTokenValue(customTokens),
    })
  }
  return withDerivedThemeTokens(
    normalizedThemes[resolveThemeMode(normalizedSelection.mode)] ||
    normalizedThemes.light,
  )
}

export function themeTokensToStyle(themeObj = {}) {
  return Object.entries(withDerivedThemeTokens(themeObj))
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => `--${camelToKebab(k)}: ${v}`)
    .join('; ')
}

export function applyTheme(themeObj = {}) {
  const vars = themeTokensToStyle(themeObj)
  // #ifdef H5
  vars.split(';').forEach((item) => {
    const [rawName, ...rawValue] = item.split(':')
    const name = rawName?.trim()
    const value = rawValue.join(':').trim()
    if (name && value) document.documentElement.style.setProperty(name, value)
  })
  // #endif
  return vars
}

export function setupSystemThemeListener(callback) {
  const cleanups = []

  if (typeof uni.onThemeChange === 'function') {
    const handler = (res) => callback(res?.theme === 'dark' ? 'dark' : 'light')
    uni.onThemeChange(handler)
    cleanups.push(() => {
      if (typeof uni.offThemeChange === 'function') {
        uni.offThemeChange(handler)
      }
    })
  }

  // #ifdef H5
  if (
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function'
  ) {
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    const handler = (event) => callback(event.matches ? 'dark' : 'light')
    if (typeof media.addEventListener === 'function') {
      media.addEventListener('change', handler)
      cleanups.push(() => media.removeEventListener('change', handler))
    } else if (typeof media.addListener === 'function') {
      media.addListener(handler)
      cleanups.push(() => media.removeListener(handler))
    }
  }
  // #endif

  return () => cleanups.forEach((cleanup) => cleanup())
}
