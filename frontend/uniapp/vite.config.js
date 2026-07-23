import { cpSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig } from 'vite'
import uni from '@dcloudio/vite-plugin-uni'

function copyProjectStatic() {
  let root = ''
  let outDir = ''

  return {
    name: 'mallbase-copy-project-static',
    apply: 'build',
    configResolved(config) {
      root = config.root
      outDir = config.build.outDir
    },
    closeBundle() {
      const source = resolve(root, 'static')
      const target = resolve(root, outDir, 'static')
      if (!existsSync(source)) return

      cpSync(source, target, { recursive: true, force: true })
    },
  }
}

export default defineConfig({
  base: '/client/',
  plugins: [uni(), copyProjectStatic()],
})
