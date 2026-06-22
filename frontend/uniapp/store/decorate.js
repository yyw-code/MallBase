import { defineStore } from 'pinia'
import { getDecorateConfig, getDecorateThemes } from '@/api/decorate/decorate'
import { DEFAULT_DECORATE_CONFIG } from '@/config/decorate'
import {
  CUSTOM_THEME_STORAGE_KEY,
  THEME_STORAGE_KEY,
  applyTheme,
  getThemeTokens,
  normalizeThemeMode,
  normalizeThemePolicy,
  normalizeThemes,
  resolveThemeMode,
  setupSystemThemeListener,
  themeTokensToStyle,
} from '@/config/theme'
import { mergeDecorateConfig } from '@/utils/decorate'

const CONFIG_STORAGE_KEY = 'mb_decorate_config'

let stopThemeListener = null

export const useDecorateStore = defineStore('decorate', {
  state: () => ({
    config: mergeDecorateConfig(DEFAULT_DECORATE_CONFIG),
    loaded: false,
    loading: false,
    themePolicy: normalizeThemePolicy(DEFAULT_DECORATE_CONFIG.theme.policy),
    themes: normalizeThemes(DEFAULT_DECORATE_CONFIG.theme.themes),
    themeMode: 'system',
    resolvedThemeMode: 'light',
    customThemeTokens: {},
    themeTokens: {},
    themeStyle: '',
  }),

  getters: {
    homeModules: (state) => state.config.home.modules || [],
    homePageStyle: (state) =>
      state.config.home.pageStyle || { paddingX: 28, paddingY: 0 },
    profileModules: (state) => state.config.profile.modules || [],
    tabbarMode: (state) => state.config.tabbar.mode || 'native',
    tabbarItems: (state) => state.config.tabbar.schema?.items || [],
    allowUserThemeSelect: (state) => Number(state.themePolicy.allow_user_select) === 1,
  },

  actions: {
    init() {
      this.restoreCache()
      this.applyThemeState()
      this.watchSystemTheme()
      this.fetchConfig()
    },

    restoreCache() {
      const cachedConfig = uni.getStorageSync(CONFIG_STORAGE_KEY)
      if (cachedConfig) {
        this.setConfig(cachedConfig, false)
      }

      const savedMode = uni.getStorageSync(THEME_STORAGE_KEY)
      this.themeMode = normalizeThemeMode(savedMode || this.themePolicy.default_mode)

      const customTokens = uni.getStorageSync(CUSTOM_THEME_STORAGE_KEY)
      this.customThemeTokens = customTokens && typeof customTokens === 'object' ? customTokens : {}
    },

    async fetchConfig() {
      if (this.loading) return
      this.loading = true
      try {
        const data = await getDecorateConfig()
        this.setConfig(data, true)
        this.loaded = true
      } catch (_) {
        this.config = mergeDecorateConfig(this.config || DEFAULT_DECORATE_CONFIG)
      } finally {
        this.loading = false
      }
    },

    async fetchThemes() {
      try {
        const data = await getDecorateThemes()
        if (!data) return
        this.themePolicy = normalizeThemePolicy(data.policy || this.themePolicy)
        this.themes = normalizeThemes(data.themes || this.themes)
        this.applyThemeState()
      } catch (_) {
        this.applyThemeState()
      }
    },

    setConfig(config, cache = true) {
      const merged = mergeDecorateConfig(config)
      this.config = merged
      this.themePolicy = normalizeThemePolicy(merged.theme?.policy)
      this.themes = normalizeThemes(merged.theme?.themes)

      const savedMode = uni.getStorageSync(THEME_STORAGE_KEY)
      this.themeMode = normalizeThemeMode(savedMode || this.themePolicy.default_mode)
      this.applyThemeState()

      if (cache) {
        uni.setStorageSync(CONFIG_STORAGE_KEY, merged)
      }
    },

    setThemeMode(mode, customTokens) {
      if (!this.allowUserThemeSelect) return
      this.themeMode = normalizeThemeMode(mode)
      if (customTokens && typeof customTokens === 'object') {
        this.customThemeTokens = { ...customTokens }
        uni.setStorageSync(CUSTOM_THEME_STORAGE_KEY, this.customThemeTokens)
      }
      uni.setStorageSync(THEME_STORAGE_KEY, this.themeMode)
      this.applyThemeState()
    },

    applyThemeState() {
      this.resolvedThemeMode = resolveThemeMode(this.themeMode)
      this.themeTokens = getThemeTokens(this.themes, this.themeMode, this.customThemeTokens)
      this.themeStyle = themeTokensToStyle(this.themeTokens)
      applyTheme(this.themeTokens)
    },

    watchSystemTheme() {
      if (stopThemeListener) return
      stopThemeListener = setupSystemThemeListener(() => {
        if (this.themeMode === 'system') {
          this.applyThemeState()
        }
      })
    },
  },
})
