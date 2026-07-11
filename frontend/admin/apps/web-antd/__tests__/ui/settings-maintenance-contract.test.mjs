/* eslint-disable test/no-import-node-test */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const readSource = (relativePath) =>
  readFileSync(fileURLToPath(new URL(relativePath, import.meta.url)), 'utf8');

test('page-section permissions do not create a nested settings menu', () => {
  const routeSource = readSource(
    '../../../../../../backend/route/api/admin/setting.php',
  );

  assert.doesNotMatch(routeSource, /'_group_code'\s*=>\s*'SettingSection'/);
  assert.match(
    routeSource,
    /Route::group\('setting\/section'[\s\S]*?'_parent'\s*=>\s*'SettingGroup'/,
  );
});

test('setting item drawer keeps form settings in base and isolates page section', () => {
  const source = readSource('../../src/views/settings/item/item-modal.vue');
  const basePane = source.match(
    /<a-tab-pane key="base"[\s\S]*?<\/a-tab-pane>/,
  )?.[0];
  const sectionPane = source.match(
    /<a-tab-pane key="section" tab="页内分组">[\s\S]*?<\/a-tab-pane>/,
  )?.[0];

  assert.ok(basePane, 'base tab should exist');
  assert.match(basePane, /<a-form-item label="输入组件">/);
  assert.match(basePane, /<a-form-item label="显示条件">/);
  assert.match(basePane, /<a-form-item label="验证规则">/);
  assert.doesNotMatch(
    basePane,
    /<a-form-item\s+v-if="!isEdit"\s+label="(?:输入组件|显示条件|验证规则)"/,
  );

  assert.ok(sectionPane, 'page-section tab should exist');
  assert.match(sectionPane, /label="页内分组"/);
  assert.doesNotMatch(sectionPane, /输入组件|显示条件|验证规则|表单配置/);
});

test('password setting uses a masked default-value input', () => {
  const source = readSource('../../src/views/settings/item/item-modal.vue');

  assert.match(
    source,
    /<a-input-password[\s\S]*?v-if="formData\.type === 'password'"[\s\S]*?v-model:value="formData\.value"/,
  );
  assert.match(source, /editData\?\.has_value[\s\S]*?已设置，留空表示不修改/);
  assert.match(
    source,
    /<a-input[\s\S]*?v-else[\s\S]*?v-model:value="formData\.value"/,
  );
});
