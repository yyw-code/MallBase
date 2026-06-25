import { defineStore } from 'pinia'
import { get } from '@/api/request'

function updateH5DocumentMeta(config) {
  // #ifdef H5
  if (!config) return

  if (config.client_site_name || config.site_name) {
    document.title = config.client_site_name || config.site_name
  }

  const favicon = config.site_favicon || '/static/admin/favicon.png'
  let link = document.querySelector('link[rel*="icon"]')
  if (!link) {
    link = document.createElement('link')
    link.rel = 'icon'
    document.head.appendChild(link)
  }
  link.href = favicon
  // #endif
}

export const useAppStore = defineStore('app', {
  state: () => ({
    siteConfig: null,
  }),
  actions: {
    async fetchBasicConfig() {
      try {
        const data = await get('/client/api/setting/basic')
        this.siteConfig = data
        updateH5DocumentMeta(data)
      } catch (e) { /* non-blocking */ }
    }
  }
})
