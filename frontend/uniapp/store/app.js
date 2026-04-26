import { defineStore } from 'pinia'
import { DEFAULT_THEME, applyTheme } from '@/config/theme'
import { get } from '@/api/request'

export const useAppStore = defineStore('app', {
  state: () => ({
    theme: { ...DEFAULT_THEME },
    siteConfig: null,
  }),
  actions: {
    initTheme() {
      const cached = uni.getStorageSync('mb_theme')
      if (cached) {
        this.theme = { ...DEFAULT_THEME, ...cached }
      }
      applyTheme(this.theme)
    },
    async fetchBasicConfig() {
      try {
        const data = await get('/client/api/setting/basic')
        this.siteConfig = data
        if (data.client_primary_color) {
          this.theme.colorPrimary = data.client_primary_color
          uni.setStorageSync('mb_theme', this.theme)
          applyTheme(this.theme)
        }
      } catch (e) { /* non-blocking */ }
    }
  }
})
