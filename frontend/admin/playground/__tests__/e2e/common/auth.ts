import type { Page } from '@playwright/test';

import { expect } from '@playwright/test';

export async function authLogin(page: Page) {
  // 确保登录表单正常
  const usernameInput = await page.locator(`input[name='username']`);
  await expect(usernameInput).toBeVisible();

  const passwordInput = await page.locator(`input[name='password']`);
  await expect(passwordInput).toBeVisible();

  const sliderCaptcha = await page.locator(`div[name='captcha']`);
  const sliderCaptchaAction = await page.locator(`div[name='captcha-action']`);
  await expect(sliderCaptcha).toBeVisible();
  await expect(sliderCaptchaAction).toBeVisible();

  // 拖动验证码滑块
  // 获取拖动按钮的位置
  const sliderCaptchaBox = await sliderCaptcha.boundingBox();
  if (!sliderCaptchaBox) throw new Error('滑块未找到');

  const actionBoundingBox = await sliderCaptchaAction.boundingBox();
  if (!actionBoundingBox) throw new Error('要拖动的按钮未找到');

  // 计算起始位置和目标位置（拖到容器右侧边缘附近，避免过拖导致回弹）
  const startX = actionBoundingBox.x + actionBoundingBox.width / 2;
  const startY = actionBoundingBox.y + actionBoundingBox.height / 2;
  const targetX = sliderCaptchaBox.x + sliderCaptchaBox.width - actionBoundingBox.width / 2 - 2;
  const targetY = startY;

  // 滑块偶发回弹，增加一次重试降低 flaky
  let moved = false;
  for (let i = 0; i < 2; i++) {
    await page.mouse.move(startX, startY);
    await page.mouse.down();
    await page.mouse.move(targetX, targetY, { steps: 25 });
    await page.mouse.up();
    await page.waitForTimeout(200);

    const newActionBoundingBox = await sliderCaptchaAction.boundingBox();
    moved = (newActionBoundingBox?.x ?? actionBoundingBox.x) > actionBoundingBox.x;
    if (moved) break;
  }

  expect(moved).toBeTruthy();

  // 到这里已经校验成功，点击进行登录
  await page.waitForTimeout(300);
  await page.getByRole('button', { name: 'login' }).click();
}
