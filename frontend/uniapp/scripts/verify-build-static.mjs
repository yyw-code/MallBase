import { access, readFile } from 'node:fs/promises'
import { resolve } from 'node:path'

const outputDir = resolve(
  process.cwd(),
  process.argv[2] || process.env.UNI_OUTPUT_DIR || 'dist/build/mp-weixin',
)
const appConfigPath = resolve(outputDir, 'app.json')
const appConfig = JSON.parse(await readFile(appConfigPath, 'utf8'))
const tabbarItems = Array.isArray(appConfig?.tabBar?.list)
  ? appConfig.tabBar.list
  : []
const assetPaths = tabbarItems.flatMap((item) =>
  [item.iconPath, item.selectedIconPath].filter(Boolean),
)

if (assetPaths.length === 0) {
  throw new Error('构建产物未包含可验证的 Tabbar 静态文件配置')
}

const missing = []
for (const assetPath of assetPaths) {
  try {
    await access(resolve(outputDir, assetPath))
  } catch {
    missing.push(assetPath)
  }
}

if (missing.length > 0) {
  throw new Error(`构建产物缺少 Tabbar 静态文件：${missing.join(', ')}`)
}

console.log(`已验证 ${assetPaths.length} 个 Tabbar 静态文件`)
