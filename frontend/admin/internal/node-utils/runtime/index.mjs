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

  throw new Error('Cannot locate jiti for @vben/node-utils runtime.');
}

const { createJiti } = await import(pathToFileURL(findJitiEntry()).href);
const jiti = createJiti(import.meta.url, {
  alias: {
    '@vben/node-utils': packageRoot,
  },
  interopDefault: true,
});

/** @type {import("../src/index.js")} */
const sourceModule = await jiti.import(sourceEntry);

export const getStagedFiles = sourceModule.getStagedFiles;
export const gitAdd = sourceModule.gitAdd;
export const generatorContentHash = sourceModule.generatorContentHash;
export const toPosixPath = sourceModule.toPosixPath;
export const prettierFormat = sourceModule.prettierFormat;
export const colors = sourceModule.colors;
export const consola = sourceModule.consola;
export const fs = sourceModule.fs;
export const readPackageJSON = sourceModule.readPackageJSON;
export const rimraf = sourceModule.rimraf;
export const UNICODE = sourceModule.UNICODE;
export const dateUtil = sourceModule.dateUtil;
export const outputJSON = sourceModule.outputJSON;
export const ensureFile = sourceModule.ensureFile;
export const readJSON = sourceModule.readJSON;
export const add = sourceModule.add;
export const commit = sourceModule.commit;
export const deepenCloneBy = sourceModule.deepenCloneBy;
export const getAllTags = sourceModule.getAllTags;
export const getChangedChangesetFilesSinceRef =
  sourceModule.getChangedChangesetFilesSinceRef;
export const getChangedFilesSince = sourceModule.getChangedFilesSince;
export const getChangedPackagesSinceRef =
  sourceModule.getChangedPackagesSinceRef;
export const getCommitsThatAddFiles = sourceModule.getCommitsThatAddFiles;
export const getCurrentCommitId = sourceModule.getCurrentCommitId;
export const getDivergedCommit = sourceModule.getDivergedCommit;
export const isRepoShallow = sourceModule.isRepoShallow;
export const remoteTagExists = sourceModule.remoteTagExists;
export const tag = sourceModule.tag;
export const tagExists = sourceModule.tagExists;
export const findMonorepoRoot = sourceModule.findMonorepoRoot;
export const getPackage = sourceModule.getPackage;
export const getPackages = sourceModule.getPackages;
export const getPackagesSync = sourceModule.getPackagesSync;
export const spinner = sourceModule.spinner;
export const execa = sourceModule.execa;
export const execaSync = sourceModule.execaSync;
export const execaCommand = sourceModule.execaCommand;
export const execaCommandSync = sourceModule.execaCommandSync;
export const execaNode = sourceModule.execaNode;
export const $ = sourceModule.$;
export const deepScriptOptions = sourceModule.deepScriptOptions;
export const setScriptSync = sourceModule.setScriptSync;
export const parseCommandString = sourceModule.parseCommandString;
export const ExecaError = sourceModule.ExecaError;
export const ExecaSyncError = sourceModule.ExecaSyncError;
export const sendMessage = sourceModule.sendMessage;
export const getOneMessage = sourceModule.getOneMessage;
export const getEachMessage = sourceModule.getEachMessage;
export const getCancelSignal = sourceModule.getCancelSignal;
