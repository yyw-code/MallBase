/* eslint-disable test/no-import-node-test */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
  new URL('../../src/views/client/config/index.vue', import.meta.url),
  'utf8',
);
const schemaSource = readFileSync(
  new URL(
    '../../../../../../backend/install/data/schema/03_mb_setting.sql',
    import.meta.url,
  ),
  'utf8',
);

test('customer-service payload only submits persisted setting fields', () => {
  assert.doesNotMatch(source, /customer_service_client_enabled\s*:/);
});

test('customer-service form accepts current context credentials without generating them', () => {
  assert.match(source, /customer_service_context_key_id:\s*''/);
  assert.match(source, /v-model:value="form\.customer_service_context_key_id"/);
  assert.match(source, /Customer Service[^。]*一次性/);
  assert.doesNotMatch(
    source,
    /fillRandomSecret\(\s*'customer_service_context_secret'/,
  );
  assert.match(
    source,
    /v-model:value="form\.customer_service_context_ttl"[\s\S]{0,160}:max="300"/,
  );
  assert.match(
    source,
    /fillRandomSecret\(\s*'customer_service_connector_secret'/,
  );
});

test('customer-service schema stores the strict key id and ttl contract', () => {
  assert.match(schemaSource, /'customer_service_context_key_id'/);
  assert.match(schemaSource, /\^ctx_\[A-Za-z0-9_-\]\{20,64\}\$/);
  assert.doesNotMatch(
    schemaSource,
    /'customer_service_context_key_id'[^\n]*"type":"required"/,
  );
  assert.match(
    schemaSource,
    /'customer_service_context_ttl'[^\n]*"type":"max","value":300/,
  );
  assert.doesNotMatch(
    schemaSource,
    /'customer_service_context_ttl'[^\n]*"type":"max","value":3600/,
  );
  assert.match(schemaSource, /Customer Service[^\n]*一次性/);
});
