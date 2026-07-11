/* eslint-disable test/no-import-node-test */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const readSource = (relativePath) =>
  readFileSync(fileURLToPath(new URL(relativePath, import.meta.url)), 'utf8');

test('workspace todo row exposes a single interactive target', () => {
  const source = readSource('../../src/views/dashboard/workspace/index.vue');
  const todoBlock = source.match(
    /<button\s+v-for="item in todos"[\s\S]*?<\/button>/,
  )?.[0];

  assert.ok(todoBlock, 'todo row template should exist');
  assert.doesNotMatch(todoBlock, /<a-button/);
});

test('analytics avoids empty-state flash and narrow grid overflow', () => {
  const source = readSource('../../src/views/dashboard/analytics/index.vue');

  assert.match(source, /const loading = ref\(true\)/);
  assert.match(source, /min\(100%,260px\)/);
  assert.match(
    source,
    /v-if="hasHealth \|\| hasOrderChannels \|\| hasSalesStructure"/,
  );
});

test('goods editor removes desktop minimum widths on narrow screens', () => {
  const source = readSource('../../src/views/goods/goods/goods-edit.vue');

  assert.match(source, /@media \(max-width: 768px\)/);
  assert.match(
    source,
    /\.page-content-min,[\s\S]*?\.edit-tabs[\s\S]*?min-width: 0/,
  );
  assert.match(
    source,
    /\.flag-row[\s\S]*?grid-template-columns: repeat\(2, minmax\(0, 1fr\)\)/,
  );
});
