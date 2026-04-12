import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

test.beforeEach(async ({ page }) => {
  await page.goto('/auth/login?e2e=1');
});

test.describe('WebAntd Auth Login', () => {
  test('should login with backend credentials', async ({ page }) => {
    await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);
  });
});
