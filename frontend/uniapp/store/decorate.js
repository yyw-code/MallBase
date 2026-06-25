import { defineStore } from 'pinia'
import { getDecorateConfig, getDecorateThemes } from '@/api/decorate/decorate'
import { getMyThemePreference, saveMyThemePreference } from '@/api/user/user'
import { DEFAULT_DECORATE_CONFIG } from '@/config/decorate'
import {
  CUSTOM_THEME_STORAGE_KEY,
  THEME_STORAGE_KEY,
  applyTheme,
  canDetectSystemTheme,
  getThemeTokens,
  normalizeThemeMode,
  normalizeThemeSetting,
  normalizeThemeSelection,
  normalizeThemes,
  resolveThemeMode,
  setupSystemThemeListener,
  themeTokensToStyle,
} from '@/config/theme'
import { mergeDecorateConfig } from '@/utils/decorate'

const CONFIG_STORAGE_KEY = 'mb_decorate_config'
const TOKEN_STORAGE_KEY = 'mb_access_token'

let stopThemeListener = null

function normalizeCustomTokens(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {}
}

function hasAuthToken() {
  return !!uni.getStorageSync(TOKEN_STORAGE_KEY)
}

function normalizePreference(value) {
  if (!value || typeof value !== 'object') return null
  const selection = normalizeThemeSelection({
    mode: value.theme_mode || value.mode,
    theme_id: value.theme_id ?? value.themeId,
  })
  return {
    theme_mode: selection.mode,
    theme_id: selection.theme_id,
  }
}

function writeThemeSelectionCache(selection) {
  uni.setStorageSync(THEME_STORAGE_KEY, {
    mode: selection.mode,
    theme_id: selection.theme_id || null,
  })
}

export const useDecorateStore = defineStore('decorate', {
  state: () => ({
    config: mergeDecorateConfig(DEFAULT_DECORATE_CONFIG),
    loaded: false,
    loading: false,
    themeSetting: normalizeThemeSetting(DEFAULT_DECORATE_CONFIG.theme.setting),
    themes: normalizeThemes(DEFAULT_DECORATE_CONFIG.theme.themes),
    themeMode: 'system',
    themeId: null,
    resolvedThemeMode: 'light',
    systemThemeSupported: canDetectSystemTheme(),
    customThemeTokens: {},
    themePreference: null,
    themePreferenceFailed: false,
    themePreferenceLoaded: false,
    themePreferenceLoading: false,
    themeTokens: {},
    themeStyle: '',
  }),

  getters: {
    homeModules: (state) => state.config.home.modules || [],
    homePageStyle: (state) =>
      state.config.home.pageStyle || { paddingX: 28, paddingY: 0 },
    profilePageStyle: (state) =>
      state.config.profile.pageStyle || { paddingTop: 10, paddingX: 28 },
    profileModules: (state) => state.config.profile.modules || [],
    tabbarMode: (state) => state.config.tabbar.mode || 'native',
    tabbarItems: (state) => state.config.tabbar.schema?.items || [],
    allowUserThemeSelect: (state) => Number(state.themeSetting.user_select_enabled) === 1,
    customThemes: (state) => state.themes.customList || [],
    availableThemeOptions: (state) => {
      const options = []
      if (state.systemThemeSupported) {
        options.push({ label: '跟随系统', mode: 'system', theme_id: null })
      }
      options.push(
        { label: '浅色', mode: 'light', theme_id: null },
        { label: '深色', mode: 'dark', theme_id: null },
      )
      const customList = state.themes.customList || []
      customList.forEach((theme) => {
        options.push({
          label: theme.name || '自定义主题',
          mode: 'custom',
          theme_id: theme.id,
        })
      })
      return options
    },
    themeLabel: (state) => {
      const mode = normalizeThemeMode(state.themeMode)
      if (mode === 'custom') {
        const theme = (state.themes.customList || []).find(
          (item) => Number(item.id) === Number(state.themeId),
        )
        return theme?.name || '自定义主题'
      }
      if (mode === 'dark') return '深色'
      if (mode === 'light') return '浅色'
      return state.systemThemeSupported ? '跟随系统' : '浅色'
    },
  },

  actions: {
    init() {
      this.restoreCache()
      this.syncThemeSelection()
      this.watchSystemTheme()
      this.fetchConfig().finally(() => {
        this.fetchMyThemePreference()
      })
    },

    restoreCache() {
      const cachedConfig = uni.getStorageSync(CONFIG_STORAGE_KEY)
      if (cachedConfig) {
        this.setConfig(cachedConfig, false)
      }

      this.customThemeTokens = normalizeCustomTokens(
        uni.getStorageSync(CUSTOM_THEME_STORAGE_KEY),
      )
      this.syncThemeSelection()
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
        this.syncThemeSelection()
      } finally {
        this.loading = false
      }
    },

    async fetchThemes(options = {}) {
      try {
        const data = await getDecorateThemes(
          options?.force ? { _t: Date.now() } : undefined,
        )
        if (!data) return
        this.themeSetting = normalizeThemeSetting(
          data.setting || data.policy || this.themeSetting,
        )
        this.themes = normalizeThemes(data.themes || this.themes)
        this.syncThemeSelection()
      } catch (_) {
        this.syncThemeSelection()
      }
    },

    setConfig(config, cache = true) {
      const merged = mergeDecorateConfig(config)
      this.config = merged
      this.themeSetting = normalizeThemeSetting(
        merged.theme?.setting || merged.theme?.policy,
      )
      this.themes = normalizeThemes(merged.theme?.themes)
      this.syncThemeSelection()

      if (cache) {
        uni.setStorageSync(CONFIG_STORAGE_KEY, merged)
      }
    },

    getAdminThemeSelection() {
      return this.normalizeAvailableThemeSelection({
        mode: this.themeSetting.admin_theme_mode,
        theme_id: this.themeSetting.admin_theme_id,
      })
    },

    normalizeAvailableThemeSelection(selection, fallback) {
      const fallbackSelection = normalizeThemeSelection(
        fallback || { mode: 'light', theme_id: null },
      )
      const normalized = normalizeThemeSelection(selection, fallbackSelection)

      if (normalized.mode === 'system' && !this.systemThemeSupported) {
        if (fallbackSelection.mode !== 'system') {
          return this.normalizeAvailableThemeSelection(fallbackSelection, {
            mode: 'light',
            theme_id: null,
          })
        }
        return { mode: 'light', theme_id: null }
      }

      if (normalized.mode === 'custom') {
        const customList = this.themes.customList || []
        if (customList.length === 0) {
          if (fallbackSelection.mode !== 'custom') {
            return this.normalizeAvailableThemeSelection(fallbackSelection, {
              mode: 'light',
              theme_id: null,
            })
          }
          return { mode: 'light', theme_id: null }
        }
        const current =
          customList.find((item) => Number(item.id) === Number(normalized.theme_id)) ||
          customList[0]
        return { mode: 'custom', theme_id: current?.id || null }
      }

      return { mode: normalizeThemeMode(normalized.mode), theme_id: null }
    },

    syncThemeSelection() {
      this.systemThemeSupported = canDetectSystemTheme()
      const adminSelection = this.getAdminThemeSelection()
      const savedSelection = normalizeThemeSelection(
        uni.getStorageSync(THEME_STORAGE_KEY),
        adminSelection,
      )
      let nextSelection = adminSelection

      if (this.allowUserThemeSelect) {
        if (!hasAuthToken()) {
          nextSelection = this.normalizeAvailableThemeSelection(savedSelection, adminSelection)
        } else if (this.themePreferenceLoaded) {
          nextSelection = this.themePreference
            ? this.normalizeAvailableThemeSelection(
                {
                  mode: this.themePreference.theme_mode,
                  theme_id: this.themePreference.theme_id,
                },
                adminSelection,
              )
            : adminSelection
        } else if (this.themePreferenceFailed) {
          nextSelection = this.normalizeAvailableThemeSelection(savedSelection, adminSelection)
        }
      }

      this.themeMode = nextSelection.mode
      this.themeId = nextSelection.theme_id || null
      this.applyThemeState()
    },

    async fetchMyThemePreference(options = {}) {
      if (!hasAuthToken()) {
        this.themePreference = null
        this.themePreferenceLoaded = false
        this.themePreferenceFailed = false
        this.syncThemeSelection()
        return null
      }
      if (this.themePreferenceLoading && !options?.force) return null

      this.themePreferenceLoading = true
      try {
        const data = await getMyThemePreference()
        if (data?.setting) {
          this.themeSetting = normalizeThemeSetting(data.setting)
        }
        this.themePreference = normalizePreference(data?.preference)
        this.themePreferenceLoaded = true
        this.themePreferenceFailed = false
        this.syncThemeSelection()
        return data
      } catch (_) {
        this.themePreferenceFailed = true
        this.syncThemeSelection()
        return null
      } finally {
        this.themePreferenceLoading = false
      }
    },

    async setThemeMode(mode, options = {}) {
      if (!this.allowUserThemeSelect) return false

      const customTokens =
        options && typeof options === 'object' && options.tokens
          ? options.tokens
          : null
      const nextSelection = this.normalizeAvailableThemeSelection({
        mode,
        theme_id: options?.theme_id ?? options?.themeId,
      })

      this.themeMode = nextSelection.mode
      this.themeId = nextSelection.theme_id || null
      if (customTokens && typeof customTokens === 'object') {
        this.customThemeTokens = { ...customTokens }
        uni.setStorageSync(CUSTOM_THEME_STORAGE_KEY, this.customThemeTokens)
      }

      if (hasAuthToken()) {
        try {
          const data = await saveMyThemePreference({
            theme_id: this.themeId,
            theme_mode: this.themeMode,
          })
          if (data?.setting) {
            this.themeSetting = normalizeThemeSetting(data.setting)
          }
          this.themePreference = normalizePreference(data?.preference)
          this.themePreferenceLoaded = true
          this.themePreferenceFailed = false
        } catch (_) {
          this.syncThemeSelection()
          return false
        }
      }

      writeThemeSelectionCache(nextSelection)
      this.applyThemeState()
      return true
    },

    applyThemeState() {
      this.resolvedThemeMode = resolveThemeMode(this.themeMode)
      this.themeTokens = getThemeTokens(
        this.themes,
        { mode: this.themeMode, theme_id: this.themeId },
        this.customThemeTokens,
      )
      this.themeStyle = themeTokensToStyle(this.themeTokens)
      applyTheme(this.themeTokens)
    },

    watchSystemTheme() {
      if (stopThemeListener) return
      stopThemeListener = setupSystemThemeListener(() => {
        this.systemThemeSupported = canDetectSystemTheme()
        if (this.themeMode === 'system') {
          this.applyThemeState()
        }
      })
    },
  },
})
