import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

interface ApiResponse<T = any> {
  code: number;
  data: T;
  message?: string;
}

const backendBaseUrl =
  (process.env.BACKEND_API_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');

test.describe('Goods Edit Spec Toggle', () => {
  test('should keep multi-spec values after switching single and back', async ({ page }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过商品规格切换场景。');

    const headers = { Authorization: `Bearer ${token}` };
    const nonce = Date.now();
    let categoryId: number | undefined;
    let goodsId: number | undefined;

    try {
      const categoryCreateRes = await page.request.post(
        `${backendBaseUrl}/admin/api/goods/category/create`,
        {
          data: {
            description: 'playwright e2e temp',
            name: `E2E分类-${nonce}`,
            pid: 0,
            sort: 0,
            status: 1,
          },
          headers,
        },
      );
      expect(categoryCreateRes.ok()).toBeTruthy();
      const categoryCreateJson = (await categoryCreateRes.json()) as ApiResponse<{ id: number }>;
      test.skip(categoryCreateJson.code !== 200, '创建临时分类失败，跳过商品规格切换场景。');
      categoryId = Number(categoryCreateJson.data?.id);
      expect(categoryId).toBeGreaterThan(0);

      const goodsCreateRes = await page.request.post(
        `${backendBaseUrl}/admin/api/goods/list/create`,
        {
          data: {
            category_id: categoryId,
            description: 'playwright e2e goods',
            images: [],
            is_hot: 0,
            is_new: 0,
            is_on_sale: 1,
            is_recommend: 0,
            main_image: '',
            market_price: 109,
            name: `E2E商品-${nonce}`,
            price: 99,
            spec_type: 2,
            skus: [
              {
                image: '',
                market_price: 109,
                price: 99,
                sku_code: `SKU-${nonce}-A`,
                spec_values: '红色,L',
                stock: 20,
              },
              {
                image: '',
                market_price: 119,
                price: 109,
                sku_code: `SKU-${nonce}-B`,
                spec_values: '蓝色,L',
                stock: 15,
              },
            ],
            sort: 0,
            status: 1,
            stock: 20,
            subtitle: 'playwright e2e',
            unit: '件',
          },
          headers,
        },
      );
      expect(goodsCreateRes.ok()).toBeTruthy();
      const goodsCreateJson = (await goodsCreateRes.json()) as ApiResponse<{ id: number }>;
      test.skip(goodsCreateJson.code !== 200, '创建临时商品失败，跳过商品规格切换场景。');
      goodsId = Number(goodsCreateJson.data?.id);
      expect(goodsId).toBeGreaterThan(0);

      const goodsInfoRes = await page.request.get(
        `${backendBaseUrl}/admin/api/goods/list/info/${goodsId}`,
        { headers },
      );
      expect(goodsInfoRes.ok()).toBeTruthy();
      const goodsInfoJson = (await goodsInfoRes.json()) as ApiResponse<{
        skus?: Array<{ spec_values?: string }>;
      }>;
      expect(goodsInfoJson.code).toBe(200);
      expect(Array.isArray(goodsInfoJson.data?.skus)).toBeTruthy();
      expect(goodsInfoJson.data?.skus?.length || 0).toBeGreaterThan(1);
      expect(goodsInfoJson.data?.skus?.[0]?.spec_values || '').toContain(',');

      await page.goto(`/goods/edit?id=${goodsId}`);
      await expect(page.getByText('编辑商品')).toBeVisible();
      await page.getByRole('tab', { name: '规格库存' }).click();

      const specNameInput = page.getByRole('textbox', { name: '规格名称' }).first();
      const specValueInput = page.getByRole('textbox', { name: '规格值' }).first();
      await expect(specNameInput).toBeVisible();
      await expect(specValueInput).toBeVisible();

      const originalSpecName = await specNameInput.inputValue();
      const originalSpecValue = await specValueInput.inputValue();
      expect(originalSpecName).not.toBe('');
      expect(originalSpecValue).not.toBe('');

      await page.getByText('单规格', { exact: true }).click();
      await expect(page.getByRole('textbox', { name: '规格名称' })).toHaveCount(0);

      await page.getByText('多规格', { exact: true }).click();

      const restoredSpecNameInput = page.getByRole('textbox', { name: '规格名称' }).first();
      const restoredSpecValueInput = page.getByRole('textbox', { name: '规格值' }).first();

      await expect(restoredSpecNameInput).toBeVisible();
      await expect(restoredSpecValueInput).toBeVisible();
      await expect(restoredSpecNameInput).toHaveValue(originalSpecName);
      await expect(restoredSpecValueInput).toHaveValue(originalSpecValue);
    } finally {
      if (goodsId) {
        await page.request.delete(`${backendBaseUrl}/admin/api/goods/list/delete/${goodsId}`, {
          headers,
        });
      }

      if (categoryId) {
        await page.request.delete(`${backendBaseUrl}/admin/api/goods/category/delete/${categoryId}`, {
          headers,
        });
      }
    }
  });

  test('should keep single-spec mode after save with default sku', async ({ page }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过单规格默认 SKU 场景。');

    const headers = { Authorization: `Bearer ${token}` };
    const nonce = Date.now();
    let categoryId: number | undefined;
    let goodsId: number | undefined;

    try {
      const categoryCreateRes = await page.request.post(
        `${backendBaseUrl}/admin/api/goods/category/create`,
        {
          data: {
            description: 'playwright e2e temp',
            name: `E2E单规格分类-${nonce}`,
            pid: 0,
            sort: 0,
            status: 1,
          },
          headers,
        },
      );
      expect(categoryCreateRes.ok()).toBeTruthy();
      const categoryCreateJson = (await categoryCreateRes.json()) as ApiResponse<{ id: number }>;
      test.skip(categoryCreateJson.code !== 200, '创建临时分类失败，跳过单规格默认 SKU 场景。');
      categoryId = Number(categoryCreateJson.data?.id);
      expect(categoryId).toBeGreaterThan(0);

      const goodsCreateRes = await page.request.post(
        `${backendBaseUrl}/admin/api/goods/list/create`,
        {
          data: {
            category_id: categoryId,
            description: 'playwright e2e goods single',
            images: [],
            is_hot: 0,
            is_new: 0,
            is_on_sale: 1,
            is_recommend: 0,
            main_image: '',
            market_price: 109,
            name: `E2E单规格商品-${nonce}`,
            price: 99,
            spec_type: 1,
            sort: 0,
            status: 1,
            stock: 20,
            subtitle: 'playwright e2e single',
            unit: '件',
          },
          headers,
        },
      );
      expect(goodsCreateRes.ok()).toBeTruthy();
      const goodsCreateJson = (await goodsCreateRes.json()) as ApiResponse<{ id: number }>;
      test.skip(goodsCreateJson.code !== 200, '创建单规格商品失败，跳过单规格默认 SKU 场景。');
      goodsId = Number(goodsCreateJson.data?.id);
      expect(goodsId).toBeGreaterThan(0);

      const goodsInfoRes = await page.request.get(
        `${backendBaseUrl}/admin/api/goods/list/info/${goodsId}`,
        { headers },
      );
      expect(goodsInfoRes.ok()).toBeTruthy();
      const goodsInfoJson = (await goodsInfoRes.json()) as ApiResponse<{
        spec_type?: number;
        skus?: Array<{ spec_values?: string }>;
      }>;
      expect(goodsInfoJson.code).toBe(200);
      expect(goodsInfoJson.data?.spec_type).toBe(1);
      expect(goodsInfoJson.data?.skus?.length || 0).toBe(1);
      expect(goodsInfoJson.data?.skus?.[0]?.spec_values || '').toBe('');

      await page.goto(`/goods/edit?id=${goodsId}`);
      await expect(page.getByText('编辑商品')).toBeVisible();
      await page.getByRole('tab', { name: '规格库存' }).click();

      await expect(page.getByRole('radio', { name: '单规格' })).toBeChecked();
      await page.getByRole('button', { name: '保存修改' }).click();
      await expect(page).toHaveURL(/\/analytics/);

      const goodsInfoAfterSaveRes = await page.request.get(
        `${backendBaseUrl}/admin/api/goods/list/info/${goodsId}`,
        { headers },
      );
      expect(goodsInfoAfterSaveRes.ok()).toBeTruthy();
      const goodsInfoAfterSaveJson = (await goodsInfoAfterSaveRes.json()) as ApiResponse<{
        spec_type?: number;
        skus?: Array<{ spec_values?: string }>;
      }>;
      expect(goodsInfoAfterSaveJson.code).toBe(200);
      expect(goodsInfoAfterSaveJson.data?.spec_type).toBe(1);
      expect(goodsInfoAfterSaveJson.data?.skus?.length || 0).toBe(1);
      expect(goodsInfoAfterSaveJson.data?.skus?.[0]?.spec_values || '').toBe('');
    } finally {
      if (goodsId) {
        await page.request.delete(`${backendBaseUrl}/admin/api/goods/list/delete/${goodsId}`, {
          headers,
        });
      }

      if (categoryId) {
        await page.request.delete(`${backendBaseUrl}/admin/api/goods/category/delete/${categoryId}`, {
          headers,
        });
      }
    }
  });
});
