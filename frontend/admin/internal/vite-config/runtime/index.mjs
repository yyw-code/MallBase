import { existsSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const runtimeDir = dirname(fileURLToPath(import.meta.url));
const packageRoot = resolve(runtimeDir, '..');
const sourceEntry = resolve(packageRoot, 'src/index.ts');

function ancestorDirs(startDir) {
  const dirs = [];
  let current = startDir;
  while (current && !dirs.includes(current)) {
    dirs.push(current);
    const next = dirname(current);
    if (next === current) break;
    current = next;
  }
  return dirs;
}

function findJitiEntry() {
  const searchRoots = [
    ...ancestorDirs(packageRoot),
    ...ancestorDirs(process.cwd()),
  ];
  const checkedRoots = new Set();

  for (const root of searchRoots) {
    if (checkedRoots.has(root)) continue;
    checkedRoots.add(root);

    const directEntry = resolve(root, 'node_modules/jiti/lib/jiti.mjs');
    if (existsSync(directEntry)) return directEntry;

    const pnpmRoot = resolve(root, 'node_modules/.pnpm');
    if (!existsSync(pnpmRoot)) continue;

    for (const name of readdirSync(pnpmRoot)) {
      if (!name.startsWith('jiti@')) continue;
      const entry = resolve(pnpmRoot, name, 'node_modules/jiti/lib/jiti.mjs');
      if (existsSync(entry)) return entry;
    }
  }

  throw new Error('Cannot locate jiti for @vben/vite-config runtime.');
}

const { createJiti } = await import(pathToFileURL(findJitiEntry()).href);
const jiti = createJiti(import.meta.url, {
  alias: {
    '@vben/vite-config': packageRoot,
  },
  interopDefault: true,
});

/** @type {import("../src/index.js")} */
const sourceModule = await jiti.import(sourceEntry);

export const loadAndConvertEnv = sourceModule.loadAndConvertEnv;
export const defineConfig = sourceModule.defineConfig;
export const defineApplicationConfig = sourceModule.defineApplicationConfig;
export const defineLibraryConfig = sourceModule.defineLibraryConfig;
export const defaultImportmapOptions = sourceModule.defaultImportmapOptions;
export const getDefaultPwaOptions = sourceModule.getDefaultPwaOptions;
export const loadApplicationPlugins = sourceModule.loadApplicationPlugins;
export const loadLibraryPlugins = sourceModule.loadLibraryPlugins;
export const viteArchiverPlugin = sourceModule.viteArchiverPlugin;
export const viteCompressPlugin = sourceModule.viteCompressPlugin;
export const viteDtsPlugin = sourceModule.viteDtsPlugin;
export const viteHtmlPlugin = sourceModule.viteHtmlPlugin;
export const viteVisualizerPlugin = sourceModule.viteVisualizerPlugin;
export const viteVxeTableImportsPlugin = sourceModule.viteVxeTableImportsPlugin;
