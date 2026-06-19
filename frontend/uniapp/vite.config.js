import { cpSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig } from 'vite'
import uni from '@dcloudio/vite-plugin-uni'

function copyH5Static() {
  let root = ''
  let outDir = ''

  return {
    name: 'mallbase-copy-h5-static',
    apply: 'build',
    configResolved(config) {
      root = config.root
      outDir = config.build.outDir
    },
    closeBundle() {
      if (process.env.UNI_PLATFORM !== 'h5') return

      const source = resolve(root, 'static')
      const target = resolve(root, outDir, 'static')
      if (!existsSync(source)) return

      cpSync(source, target, { recursive: true, force: true })
    },
  }
}

export default defineConfig({
  base: '/client/',
  plugins: [uni(), copyH5Static()],
  css: {
    preprocessorOptions: {
      scss: {
        additionalData: `@import "@/uni.scss";`,
      },
    },
  },
})
