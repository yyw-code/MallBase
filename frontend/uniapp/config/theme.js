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
  colorPrimary: '#7da2ff',
  colorPrimaryLight: '#9bb8ff',
  colorBg: '#14161c',
  colorBgSecondary: '#0f1117',
  colorBgSurface: '#1d2028',
  colorText: '#f4f6fb',
  colorTextTitle: '#ffffff',
  colorTextSecondary: '#c7ccd8',
  colorTextTertiary: '#8f96a6',
  colorBorder: '#323744',
  colorDivider: '#272b35',
  colorPrice: '#ff7a45',
  colorError: '#ff8a8a',
  colorSuccess: '#5bd685',
  colorWarning: '#f4be63',
}

export const DEFAULT_THEME = DEFAULT_LIGHT_THEME

export const THEME_STORAGE_KEY = 'mb_theme_mode'
export const CUSTOM_THEME_STORAGE_KEY = 'mb_custom_theme_tokens'

function camelToKebab(str) {
  return str.replace(/([A-Z])/g, '-$1').toLowerCase()
}

export function getSystemThemeMode() {
  try {
    const info = uni.getSystemInfoSync()
    return info.theme === 'dark' ? 'dark' : 'light'
  } catch (_) {
    return 'light'
  }
}

export function normalizeThemePolicy(policy = {}) {
  return {
    allow_user_select: Number(policy.allow_user_select ?? policy.allowUserSelect ?? 1),
    default_mode: normalizeThemeMode(policy.default_mode || policy.defaultMode || 'system'),
  }
}

export function normalizeThemeMode(mode) {
  if (['system', 'light', 'dark', 'custom'].includes(mode)) return mode
  return 'system'
}

export function resolveThemeMode(mode) {
  const normalized = normalizeThemeMode(mode)
  return normalized === 'system' ? getSystemThemeMode() : normalized
}

export function normalizeThemes(themes = {}) {
  if (Array.isArray(themes)) {
    const mapped = {}
    themes.forEach((item) => {
      if (!item || !item.type) return
      mapped[item.type] = item.tokens || {}
    })
    themes = mapped
  }

  return {
    light: { ...DEFAULT_LIGHT_THEME, ...(themes.light || {}) },
    dark: { ...DEFAULT_DARK_THEME, ...(themes.dark || {}) },
    custom: { ...DEFAULT_LIGHT_THEME, ...(themes.custom || {}) },
  }
}

export function getThemeTokens(themes, mode, customTokens = {}) {
  const normalizedThemes = normalizeThemes(themes)
  if (mode === 'custom') {
    return { ...normalizedThemes.custom, ...customTokens }
  }
  return normalizedThemes[resolveThemeMode(mode)] || normalizedThemes.light
}

export function themeTokensToStyle(themeObj = {}) {
  return Object.entries(themeObj)
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
  if (typeof uni.onThemeChange !== 'function') return () => {}
  const handler = (res) => callback(res?.theme === 'dark' ? 'dark' : 'light')
  uni.onThemeChange(handler)
  return () => {
    if (typeof uni.offThemeChange === 'function') {
      uni.offThemeChange(handler)
    }
  }
}
