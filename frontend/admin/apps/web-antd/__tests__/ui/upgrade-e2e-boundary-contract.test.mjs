/* eslint-disable test/no-import-node-test */
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

const viteSource = readFileSync(
  new URL('../../vite.config.mts', import.meta.url),
  'utf8',
);
const playwrightSource = readFileSync(
  new URL('../../playwright.config.ts', import.meta.url),
  'utf8',
);
const developmentEnv = readFileSync(
  new URL('../../.env.development', import.meta.url),
  'utf8',
);
const upgradeE2eUrl = new URL(
  '../e2e/upgrade-maintenance.spec.ts',
  import.meta.url,
);

test('upgrade E2E owns Vite and keeps the real Admin API prefix', () => {
  assert.match(playwrightSource, /VITE_GLOB_API_URL=\/admin\/api/);
  assert.match(playwrightSource, /reuseExistingServer:\s*false/);
});

test('Vite sends Admin to PHP and upgrade traffic to the temporary Go server', () => {
  assert.match(developmentEnv, /^VITE_GLOB_API_URL=\/admin\/api$/m);
  assert.match(viteSource, /MALLBASE_BACKEND_ORIGIN/);
  assert.match(viteSource, /MALLBASE_UPGRADE_ORIGIN/);
  assert.match(viteSource, /http:\/\/127\.0\.0\.1:18081/);
  assert.match(viteSource, /['"]\/admin\/api['"]/);
  assert.match(viteSource, /['"]\/upgrade['"]/);
  assert.match(
    viteSource,
    /['"]\/upgrade['"][\s\S]{0,180}changeOrigin:\s*false[\s\S]{0,180}target:\s*upgradeOrigin/,
  );
  assert.doesNotMatch(
    viteSource,
    /['"]\/upgrade['"][\s\S]{0,240}\brewrite\s*:/,
  );
});

test('real-backend E2E separates Admin records from the optional Go shell', () => {
  assert.equal(existsSync(upgradeE2eUrl), true);
  const e2eSource = readFileSync(upgradeE2eUrl, 'utf8');
  assert.match(e2eSource, /\/admin\/api\/system\/upgrade\/overview/);
  assert.match(e2eSource, /\/admin\/api\/system\/upgrade\/releases/);
  assert.match(e2eSource, /\/admin\/api\/system\/upgrade\/records/);
  assert.match(e2eSource, /expect\(agentRequests\)\.toEqual\(\[\]\)/);
  assert.doesNotMatch(e2eSource, /\/admin\/api\/system\/upgrade\/session/);
  assert.doesNotMatch(e2eSource, /\/upgrade\/health/);
  assert.doesNotMatch(e2eSource, /\/upgrade\/api\/maintenance/);
});
