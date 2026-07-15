import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

test.describe('Upgrade UI boundary', () => {
  test('Admin reads history and issues only an entry ticket', async ({
    page,
  }) => {
    await page.goto('/auth/login?e2e=1');
    const login = await authLogin(page);
    test.skip(
      !login.accessToken,
      '登录未返回 access token，无法验证升级入口权限',
    );

    const catalogSessionRequests: string[] = [];
    page.on('request', (request) => {
      if (
        request.method() === 'POST' &&
        request.url().includes('/admin/api/system/upgrade/session')
      ) {
        catalogSessionRequests.push(request.url());
      }
    });
    await page.goto('/system/upgrade');
    const main = page.locator('#__vben_main_content');
    await expect(main.getByText('系统升级', { exact: true })).toBeVisible();
    await expect(main.getByText('版本概览', { exact: true })).toBeVisible();
    await expect(
      main.getByText('平台可升级版本', { exact: true }),
    ).toBeVisible();
    await expect(main.getByText('升级记录', { exact: true })).toBeVisible();
    await expect(main.getByText('当前环境未启用安全升级')).toHaveCount(0);
    expect(catalogSessionRequests).toEqual([]);

    const headers = { Authorization: `Bearer ${login.accessToken}` };
    const overview = await page.request.get(
      '/admin/api/system/upgrade/overview',
      { headers },
    );
    expect(overview.ok()).toBeTruthy();
    const overviewBody = await overview.json();
    expect(overviewBody.code).toBe(200);
    expect(overviewBody.data).toEqual(
      expect.objectContaining({
        current: expect.objectContaining({
          version: expect.any(String),
        }),
      }),
    );

    const catalog = await page.request.get(
      '/admin/api/system/upgrade/releases',
      { headers },
    );
    expect([200, 503]).toContain(catalog.status());
    const catalogBody = await catalog.json();
    if (catalog.ok()) {
      expect(catalogBody.data).toEqual(
        expect.objectContaining({
          current_version: overviewBody.data.current.version,
          releases: expect.any(Array),
        }),
      );
    } else {
      expect(catalogBody.data.reason).toBe('UPGRADE_CATALOG_UNAVAILABLE');
    }

    const health = await page.request.get('/upgrade/health');
    if (!health.ok()) {
      await expect(
        main.getByText('版本目录仍可查看，启动后方可执行升级。', {
          exact: true,
        }),
      ).toBeVisible();
      await expect(
        main.getByRole('button', { name: '前往升级' }),
      ).toBeDisabled();
    }

    const records = await page.request.get(
      '/admin/api/system/upgrade/records?page=1&limit=20',
      { headers },
    );
    expect(records.ok()).toBeTruthy();
    expect(records.headers()['content-type']).toContain('application/json');
    const recordsBody = await records.json();
    expect(recordsBody.code).toBe(200);
    expect(recordsBody.data).toEqual(
      expect.objectContaining({
        list: expect.any(Array),
        total: expect.any(Number),
      }),
    );

    const entry = await page.request.post('/admin/api/system/upgrade/session', {
      data: {},
      headers,
    });
    expect(entry.ok()).toBeTruthy();
    const entryBody = await entry.json();
    expect(entryBody.data.upgrade_url).toMatch(/^\/upgrade\/\?ticket=/);
    expect(JSON.stringify(entryBody)).not.toContain('recovery_credential');

    const targetedEntry = await page.request.post(
      '/admin/api/system/upgrade/session',
      {
        data: { target_version: '1.2.0' },
        headers,
      },
    );
    expect(targetedEntry.ok()).toBeTruthy();
    const targetedEntryBody = await targetedEntry.json();
    expect(targetedEntryBody.data.upgrade_url).toMatch(/^\/upgrade\/\?ticket=/);
    expect(JSON.stringify(targetedEntryBody)).not.toContain('target_version');
  });

  test('Go owns the independent shell and requires an upgrade session', async ({
    page,
  }) => {
    const health = await page.request.get('/upgrade/health');
    test.skip(!health.ok(), 'Go 升级程序未手动启动');

    const shell = await page.request.get('/upgrade/');
    expect(shell.status()).toBe(401);
    expect(shell.headers()['content-type']).toContain('application/json');
  });
});
