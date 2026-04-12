import type { Page } from '@playwright/test';

import { expect } from '@playwright/test';

export async function authLogin(page: Page) {
  const username = process.env.E2E_ADMIN_USERNAME ?? 'admin';
  const password = process.env.E2E_ADMIN_PASSWORD ?? '123123';

  await expect(page.locator(`input[name='username']`)).toBeVisible();
  await expect(page.locator(`input[name='password']`)).toBeVisible();

  await page.locator(`input[name='username']`).fill(username);
  await page.locator(`input[name='password']`).fill(password);

  const loginRequest = page.waitForRequest(
    (request) =>
      request.url().includes('/auth/admin/login') &&
      request.method() === 'POST',
  );

  await page.getByRole('button', { name: /login|登录/i }).click();

  const request = await loginRequest;
  const response = await request.response();
  expect(response, '登录请求未拿到响应，通常是 CORS 或后端服务不可达').toBeTruthy();
  if (!response) {
    return;
  }

  expect(response.ok()).toBeTruthy();

  const body = await response.json();
  expect(body?.code).toBe(200);
}
