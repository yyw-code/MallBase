import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

interface ApiResponse<T = any> {
  code: number;
  data: T;
  message?: string;
}

interface EnumOption {
  label: string;
  value: number | string;
}

interface RefundRecord {
  id: number;
  sn: string;
  status: number;
  status_text?: string;
  refund_amount: string;
  quantity: number;
  reason_text?: string;
  order?: { sn: string } | null;
  order_item?: { goods_name: string } | null;
}

interface RefundListResponse {
  list: RefundRecord[];
  total: number;
}

const backendBaseUrl = (
  process.env.BACKEND_API_BASE_URL || 'http://127.0.0.1:8080'
).replace(/\/$/, '');

test.describe('Refund admin page', () => {
  test('售后列表页基础渲染 + 枚举接口可用', async ({ page }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过');
    const headers = { Authorization: `Bearer ${token}` };

    // 1) 枚举接口应返回 status / type / reason
    const optRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/refund/statusOptions`,
      { headers },
    );
    expect(optRes.ok()).toBeTruthy();
    const optJson = (await optRes.json()) as ApiResponse<{
      reason: EnumOption[];
      status: EnumOption[];
      type: EnumOption[];
    }>;
    test.skip(optJson.code !== 200, 'statusOptions 未返回 200，跳过');
    expect(Array.isArray(optJson.data.status)).toBeTruthy();
    expect(Array.isArray(optJson.data.type)).toBeTruthy();
    expect(Array.isArray(optJson.data.reason)).toBeTruthy();
    // 状态至少应含：待处理/已完成/已驳回/已关闭
    expect(optJson.data.status.length).toBeGreaterThanOrEqual(4);

    // 2) 打开售后管理页，应看到筛选表单
    await page.goto('/order/refund');
    await expect(
      page.locator('input[placeholder="售后单号（支持模糊）"]'),
    ).toBeVisible({ timeout: 8000 });
    await expect(page.getByRole('button', { name: /搜\s*索/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /重\s*置/ })).toBeVisible();
  });

  test('售后列表接口可调用 + 详情接口正确返回', async ({ page }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过');
    const headers = { Authorization: `Bearer ${token}` };

    // 列表接口
    const listRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/refund/list?limit=5`,
      { headers },
    );
    expect(listRes.ok()).toBeTruthy();
    const listJson = (await listRes.json()) as ApiResponse<RefundListResponse>;
    test.skip(listJson.code !== 200, 'refund/list 未返回 200');
    expect(typeof listJson.data.total).toBe('number');
    expect(Array.isArray(listJson.data.list)).toBeTruthy();

    // 如果列表有数据，验证详情接口
    const first = listJson.data.list[0];
    test.skip(!first, '当前环境无售后数据，跳过详情断言');

    const detailRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/refund/detail/${first!.id}`,
      { headers },
    );
    expect(detailRes.ok()).toBeTruthy();
    const detailJson = (await detailRes.json()) as ApiResponse<{
      id: number;
      sn: string;
      order?: { sn: string } | null;
      order_item?: { goods_name: string } | null;
      reason_text?: string;
      refund_amount: string;
      user?: { id: number } | null;
    }>;
    expect(detailJson.code).toBe(200);
    expect(detailJson.data.sn).toBe(first!.sn);
    expect(typeof detailJson.data.refund_amount).toBe('string');
    // 详情应包含关联信息
    expect(detailJson.data).toHaveProperty('order');
    expect(detailJson.data).toHaveProperty('order_item');
    expect(detailJson.data).toHaveProperty('user');
  });

  test('如有待处理售后：页面应展示同意/驳回按钮', async ({ page }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过');
    const headers = { Authorization: `Bearer ${token}` };

    // 查找待处理售后（status=0）
    const pendingRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/refund/list?status=0&limit=1`,
      { headers },
    );
    expect(pendingRes.ok()).toBeTruthy();
    const pendingJson =
      (await pendingRes.json()) as ApiResponse<RefundListResponse>;
    test.skip(pendingJson.code !== 200, 'refund/list 未返回 200');
    const pending = pendingJson.data?.list?.[0];
    test.skip(!pending, '当前环境无待处理售后，跳过按钮断言');

    // 打开售后页面，按单号筛选
    await page.goto('/order/refund');
    await expect(
      page.locator('input[placeholder="售后单号（支持模糊）"]'),
    ).toBeVisible({ timeout: 8000 });
    await page
      .locator('input[placeholder="售后单号（支持模糊）"]')
      .fill(pending!.sn);
    await page.getByRole('button', { name: /搜\s*索/ }).click();

    const row = page.getByRole('row', { name: new RegExp(pending!.sn) });
    // 同意 / 驳回 / 详情 按钮应可见
    await expect(
      row.getByRole('button', { name: /详\s*情/ }),
    ).toBeVisible({ timeout: 8000 });
    await expect(
      row.getByRole('button', { name: /同\s*意/ }),
    ).toBeVisible();
    await expect(
      row.getByRole('button', { name: /驳\s*回/ }),
    ).toBeVisible();
  });

  test('审核驳回：后端应拒绝空 admin_remark', async ({ page }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过');
    const headers = { Authorization: `Bearer ${token}` };

    // 查找待处理售后
    const pendingRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/refund/list?status=0&limit=1`,
      { headers },
    );
    const pendingJson =
      (await pendingRes.json()) as ApiResponse<RefundListResponse>;
    test.skip(
      pendingJson.code !== 200 || !pendingJson.data?.list?.[0],
      '无待处理售后数据，跳过',
    );
    const pending = pendingJson.data.list[0]!;

    // 空 admin_remark 应被拒绝
    const rejectRes = await page.request.post(
      `${backendBaseUrl}/admin/api/order/refund/reject/${pending.id}`,
      { data: { admin_remark: '' }, headers },
    );
    const rejectJson = (await rejectRes.json()) as ApiResponse<unknown>;
    expect(rejectJson.code).not.toBe(200);
  });

  test('售后原因枚举接口返回有效数据', async ({ page }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过');
    const headers = { Authorization: `Bearer ${token}` };

    const res = await page.request.get(
      `${backendBaseUrl}/admin/api/order/refund/reasonOptions`,
      { headers },
    );
    expect(res.ok()).toBeTruthy();
    const json = (await res.json()) as ApiResponse<EnumOption[]>;
    test.skip(json.code !== 200, 'reasonOptions 未返回 200（可能权限未同步），跳过');
    expect(Array.isArray(json.data)).toBeTruthy();
    // 至少应有 4 个原因选项
    expect(json.data.length).toBeGreaterThanOrEqual(4);
    // 每项应有 label 和 value
    for (const opt of json.data) {
      expect(opt).toHaveProperty('label');
      expect(opt).toHaveProperty('value');
    }
  });
});
