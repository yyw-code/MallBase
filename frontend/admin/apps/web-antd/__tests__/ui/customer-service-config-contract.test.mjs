/* eslint-disable test/no-import-node-test */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
  new URL('../../src/views/client/config/index.vue', import.meta.url),
  'utf8',
);

test('customer-service payload only submits persisted setting fields', () => {
  assert.doesNotMatch(source, /customer_service_client_enabled\s*:/);
});
