const { existsSync, readdirSync } = require('node:fs');
const { dirname, resolve } = require('node:path');

const runtimeDir = __dirname;
const packageRoot = resolve(runtimeDir, '..');
const sourceEntry = resolve(packageRoot, 'src/postcss.config.ts');

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

    const directEntry = resolve(root, 'node_modules/jiti/lib/jiti.cjs');
    if (existsSync(directEntry)) return directEntry;

    const pnpmRoot = resolve(root, 'node_modules/.pnpm');
    if (!existsSync(pnpmRoot)) continue;

    for (const name of readdirSync(pnpmRoot)) {
      if (!name.startsWith('jiti@')) continue;
      const entry = resolve(pnpmRoot, name, 'node_modules/jiti/lib/jiti.cjs');
      if (existsSync(entry)) return entry;
    }
  }

  throw new Error('Cannot locate jiti for @vben/tailwind-config runtime.');
}

const { createJiti } = require(findJitiEntry());
const jiti = createJiti(__filename, {
  alias: {
    '@vben/tailwind-config': packageRoot,
  },
  interopDefault: true,
});

module.exports = jiti(sourceEntry);
