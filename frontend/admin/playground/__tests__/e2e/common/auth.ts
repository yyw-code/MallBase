import type { Page } from '@playwright/test';

import { expect } from '@playwright/test';

export async function authLogin(page: Page) {
  // 确保登录表单正常
  const usernameInput = await page.locator(`input[name='username']`);
  await expect(usernameInput).toBeVisible();

  const passwordInput = await page.locator(`input[name='password']`);
  await expect(passwordInput).toBeVisible();

  // E2E 环境已关闭滑动验证，直接点击登录
  const loginRequest = page.waitForResponse(
    (response) =>
      response.url().includes('/api/auth/login') &&
      response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'login' }).click();

  const response = await loginRequest;
  expect(response.ok()).toBeTruthy();
}
