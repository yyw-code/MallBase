export const DEFAULT_THEME = {
  colorPrimary: '#0d50d5',
  colorPrimaryLight: '#386bef',
  colorBg: '#ffffff',
  colorBgSecondary: '#f7f9fb',
  colorText: '#1b1b1b',
  colorTextTitle: '#131b2e',
  colorTextSecondary: '#5e5e5e',
  colorTextTertiary: '#848484',
  colorBorder: '#e0e3e5',
  colorError: '#ba1a1a',
  colorSuccess: '#34c759',
}

function camelToKebab(str) {
  return str.replace(/([A-Z])/g, '-$1').toLowerCase()
}

export function applyTheme(themeObj) {
  const vars = Object.entries(themeObj).map(([k, v]) => `--${camelToKebab(k)}: ${v}`).join('; ')
  // #ifdef H5
  document.documentElement.style.cssText += vars
  // #endif
}
