import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import process from 'node:process';

import { describe, expect, it } from 'vitest';

describe('web-antd access store persistence', () => {
  it('does not persist refresh token or lock screen password', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'apps/web-antd/src/modules/access.ts'),
      'utf8',
    );
    const persistPick = source.match(/pick:\s*\[([\s\S]*?)\]/)?.[1] ?? '';

    expect(persistPick).toContain('accessToken');
    expect(persistPick).not.toContain('refreshToken');
    expect(persistPick).not.toContain('lockScreenPassword');
  });
});
