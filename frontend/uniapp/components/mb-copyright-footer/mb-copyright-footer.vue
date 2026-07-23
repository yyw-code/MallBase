<template>
  <view v-if="visible" class="mb-copyright-footer">
    <text v-if="copyrightLine" class="mb-copyright-footer__line">{{ copyrightLine }}</text>
    <text
      v-if="copyrightIcp"
      class="mb-copyright-footer__line mb-copyright-footer__link"
      @tap="openLink(icpUrl)"
    >
      {{ copyrightIcp }}
    </text>
    <text
      v-if="copyrightPsb"
      class="mb-copyright-footer__line mb-copyright-footer__link"
      @tap="openLink(psbUrl)"
    >
      {{ copyrightPsb }}
    </text>
  </view>
</template>

<script setup>
import { computed } from 'vue'
import { useAppStore } from '@/store/app'

const appStore = useAppStore()

const siteConfig = computed(() => appStore.siteConfig || {})
const configReady = computed(() => Boolean(appStore.siteConfig))

const copyrightEnabled = computed(() => {
  const enabled = siteConfig.value.copyright_enabled
  if (enabled === undefined || enabled === null || enabled === '') return true
  return Number(enabled) === 1 || enabled === true || enabled === '1' || enabled === 'true'
})

const companyName = computed(() => (
  siteConfig.value.copyright_company ||
  siteConfig.value.client_site_name ||
  siteConfig.value.site_name ||
  ''
))

const copyrightDate = computed(() => (
  siteConfig.value.copyright_date || new Date().getFullYear()
))

const copyrightLine = computed(() => {
  if (!companyName.value) return ''
  const company = String(companyName.value)
  const suffix = /版权所有|all rights reserved/i.test(company) ? '' : ' 版权所有'
  return `© ${copyrightDate.value} ${company}${suffix}`
})

const copyrightIcp = computed(() => siteConfig.value.copyright_icp || '')
const copyrightPsb = computed(() => siteConfig.value.copyright_psb || '')
const icpUrl = computed(() => siteConfig.value.copyright_icp_url || 'https://beian.miit.gov.cn')
const psbUrl = computed(() => siteConfig.value.copyright_psb_url || 'https://beian.mps.gov.cn/#/query/webSearch')

const visible = computed(() => (
  configReady.value &&
  copyrightEnabled.value &&
  Boolean(copyrightLine.value || copyrightIcp.value || copyrightPsb.value)
))

function openLink(url) {
  if (!url) return

  // #ifdef H5
  window.open(url, '_blank')
  // #endif

  // #ifndef H5
  uni.setClipboardData({
    data: url,
    success() {
      uni.showToast({ title: '链接已复制', icon: 'none' })
    },
  })
  // #endif
}
</script>

<style lang="scss" scoped>
.mb-copyright-footer {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8rpx;
  padding: 32rpx 24rpx;
  text-align: center;
}

.mb-copyright-footer__line {
  max-width: 100%;
  color: var(--color-text-tertiary-on-page, var(--color-text-tertiary, #737686));
  font-size: $mb-font-xs;
  line-height: 1.6;
  word-break: break-all;
}

.mb-copyright-footer__link {
  color: var(--color-text-secondary-on-page, var(--color-text-secondary, #434654));
}
</style>
