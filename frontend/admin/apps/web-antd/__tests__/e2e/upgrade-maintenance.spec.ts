import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

test.describe('Upgrade UI boundary', () => {
  test('Admin shows versions and history without contacting a resident Agent', async ({
    page,
  }) => {
    await page.goto('/auth/login?e2e=1');
    const login = await authLogin(page);
    test.skip(
      !login.accessToken,
      '登录未返回 access token，无法验证升级入口权限',
    );

    const agentRequests: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes('/upgrade/')) {
        agentRequests.push(request.url());
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
    await expect(
      main.getByRole('button', { name: '回滚最近备份' }),
    ).toBeVisible();
    expect(agentRequests).toEqual([]);

    const headers = { Authorization: `Bearer ${login.accessToken}` };
    const overview = await page.request.get(
      '/admin/api/system/upgrade/overview',
      { headers },
    );
    expect(overview.ok()).toBeTruthy();
    const overviewBody = await overview.json();
    expect(overviewBody.code).toBe(200);
    expect(overviewBody.data.current.version).toEqual(expect.any(String));

    const catalog = await page.request.get(
      '/admin/api/system/upgrade/releases',
      { headers },
    );
    expect([200, 503]).toContain(catalog.status());

    const records = await page.request.get(
      '/admin/api/system/upgrade/records?page=1&limit=20',
      { headers },
    );
    expect(records.ok()).toBeTruthy();
    const recordsBody = await records.json();
    expect(recordsBody.data).toEqual(
      expect.objectContaining({
        list: expect.any(Array),
        total: expect.any(Number),
      }),
    );
  });
});
