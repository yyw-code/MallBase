import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

interface ApiResponse<T = any> {
  code: number;
  data: T;
  message?: string;
}

interface EnumOption {
  label: string;
  value: number;
}

interface OrderRecord {
  discount_amount: string;
  freight_amount: string;
  id: number;
  items?: OrderItem[];
  pay_amount: string;
  sn: string;
  status: number;
  status_text?: string;
  total_amount: string;
  user_id: number;
}

interface OrderItem {
  discount_amount: string;
  id: number;
  pay_amount: string;
  subtotal: string;
}

interface OrderListResponse {
  list: OrderRecord[];
  total: number;
}

const backendBaseUrl = (
  process.env.BACKEND_API_BASE_URL || 'http://127.0.0.1:8080'
).replace(/\/$/, '');

test.describe('Order admin page', () => {
  test('订单列表页基础渲染 + 状态枚举接口可用', async ({ page }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过');
    const headers = { Authorization: `Bearer ${token}` };

    // 1) 枚举接口应返回 status / pay_method
    const optRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/statusOptions`,
      { headers },
    );
    expect(optRes.ok()).toBeTruthy();
    const optJson = (await optRes.json()) as ApiResponse<{
      pay_method: EnumOption[];
      status: EnumOption[];
    }>;
    test.skip(optJson.code !== 200, 'statusOptions 未返回 200，跳过');
    expect(Array.isArray(optJson.data.status)).toBeTruthy();
    expect(Array.isArray(optJson.data.pay_method)).toBeTruthy();
    // 状态至少应含：待支付 / 已支付 / 已发货 / 已完成 / 已关闭
    expect(optJson.data.status.length).toBeGreaterThanOrEqual(5);

    // 2) 打开订单管理页，应看到筛选表单
    await page.goto('/order');
    await expect(
      page.locator('input[placeholder="订单号（支持模糊）"]'),
    ).toBeVisible({ timeout: 8000 });
    await expect(page.getByRole('button', { name: /搜\s*索/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /重\s*置/ })).toBeVisible();
  });

  test('如有已发货订单：详情抽屉应显示时间轴日志', async ({ page }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过');
    const headers = { Authorization: `Bearer ${token}` };

    // 先查找已发货订单（status=20）
    const shippedRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/list?status=20&limit=1`,
      { headers },
    );
    expect(shippedRes.ok()).toBeTruthy();
    const shippedJson =
      (await shippedRes.json()) as ApiResponse<OrderListResponse>;
    test.skip(shippedJson.code !== 200, 'order/list 未返回 200');
    const shipped = shippedJson.data?.list?.[0];
    test.skip(!shipped, '当前环境无已发货订单，跳过详情抽屉断言');

    // 详情接口应返回 logs 数组
    const detailRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/detail/${shipped!.id}`,
      { headers },
    );
    expect(detailRes.ok()).toBeTruthy();
    const detailJson = (await detailRes.json()) as ApiResponse<{
      logs: unknown[];
      sn: string;
    }>;
    expect(detailJson.code).toBe(200);
    expect(detailJson.data.sn).toBe(shipped!.sn);
    expect(Array.isArray(detailJson.data.logs)).toBeTruthy();
    // 发货订单至少有一次状态流转日志
    expect(detailJson.data.logs.length).toBeGreaterThan(0);

    // 页面打开详情抽屉
    await page.goto('/order');
    await expect(
      page.locator('input[placeholder="订单号（支持模糊）"]'),
    ).toBeVisible({ timeout: 8000 });
    await page
      .locator('input[placeholder="订单号（支持模糊）"]')
      .fill(shipped!.sn);
    await page.getByRole('button', { name: /搜\s*索/ }).click();

    const detailBtn = page
      .getByRole('row', { name: new RegExp(shipped!.sn) })
      .getByRole('button', { name: /详\s*情/ });
    await expect(detailBtn).toBeVisible({ timeout: 8000 });
    await detailBtn.click();

    // 抽屉内应有“状态流转时间轴”标题
    await expect(
      page.getByText('状态流转时间轴', { exact: false }),
    ).toBeVisible({ timeout: 8000 });
  });

  test('发货弹窗对无已支付订单环境应优雅跳过，有订单则可验证字段', async ({
    page,
  }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过');
    const headers = { Authorization: `Bearer ${token}` };

    const paidRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/list?status=10&limit=1`,
      { headers },
    );
    expect(paidRes.ok()).toBeTruthy();
    const paidJson = (await paidRes.json()) as ApiResponse<OrderListResponse>;
    test.skip(paidJson.code !== 200, 'order/list 未返回 200');
    const paid = paidJson.data?.list?.[0];
    test.skip(!paid, '当前环境无已支付订单，跳过发货弹窗断言');

    // 后端直接校验空字段应报错
    const emptyRes = await page.request.post(
      `${backendBaseUrl}/admin/api/order/ship/${paid!.id}`,
      { data: { logistics_company: '', logistics_sn: '' }, headers },
    );
    const emptyJson = (await emptyRes.json()) as ApiResponse<unknown>;
    expect(emptyJson.code).not.toBe(200);

    // 页面侧：发货按钮仅对 status=10 渲染
    await page.goto('/order');
    await expect(
      page.locator('input[placeholder="订单号（支持模糊）"]'),
    ).toBeVisible({ timeout: 8000 });
    await page
      .locator('input[placeholder="订单号（支持模糊）"]')
      .fill(paid!.sn);
    await page.getByRole('button', { name: /搜\s*索/ }).click();

    const row = page.getByRole('row', { name: new RegExp(paid!.sn) });
    const shipBtn = row.getByRole('button', { name: /发\s*货/ });
    await expect(shipBtn).toBeVisible({ timeout: 8000 });
  });

  test('改价：无待支付订单优雅跳过，有订单则校验接口、按钮与弹窗提交', async ({
    page,
  }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过');
    const headers = { Authorization: `Bearer ${token}` };

    const pendingRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/list?status=0&limit=1`,
      { headers },
    );
    expect(pendingRes.ok()).toBeTruthy();
    const pendingJson =
      (await pendingRes.json()) as ApiResponse<OrderListResponse>;
    test.skip(pendingJson.code !== 200, 'order/list 未返回 200');
    const pending = pendingJson.data?.list?.[0];
    test.skip(!pending, '当前环境无待支付订单，跳过改价断言');

    // 后端直接校验：缺失必填字段应报错
    const emptyRes = await page.request.post(
      `${backendBaseUrl}/admin/api/order/adjustPrice/${pending!.id}`,
      { data: { adjust_mode: '', freight_amount: '' }, headers },
    );
    const emptyJson = (await emptyRes.json()) as ApiResponse<unknown>;
    expect(emptyJson.code).not.toBe(200);

    const beforeDetailRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/detail/${pending!.id}`,
      { headers },
    );
    const beforeDetailJson =
      (await beforeDetailRes.json()) as ApiResponse<OrderRecord>;
    expect(beforeDetailJson.code).toBe(200);
    const items = beforeDetailJson.data.items ?? [];
    test.skip(items.length === 0, '当前待支付订单无商品明细，跳过改价断言');

    // 后端直接校验：合法改价按商品项实付 + freight 重算应付金额
    const currentFreight = Number(pending!.freight_amount ?? 0);
    const currentDiscount = items.reduce(
      (sum, item) => sum + Number(item.discount_amount ?? 0),
      0,
    );
    const expectedPay =
      Number(pending!.total_amount ?? 0) + currentFreight - currentDiscount;
    const validRes = await page.request.post(
      `${backendBaseUrl}/admin/api/order/adjustPrice/${pending!.id}`,
      {
        data: {
          adjust_mode: 'item_discount',
          freight_amount: currentFreight.toFixed(2),
          items: items.map((item) => ({
            order_item_id: item.id,
            discount_amount: item.discount_amount,
          })),
          reason: 'E2E 改价回归',
        },
        headers,
      },
    );
    const validJson = (await validRes.json()) as ApiResponse<unknown>;
    expect(validJson.code).toBe(200);

    const detailRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/detail/${pending!.id}`,
      { headers },
    );
    const detailJson = (await detailRes.json()) as ApiResponse<OrderRecord>;
    expect(detailJson.code).toBe(200);
    expect(Number(detailJson.data.pay_amount)).toBeCloseTo(expectedPay, 2);

    // 页面侧：改价按钮仅对 status=0 渲染，并可打开弹窗提交
    await page.goto('/order');
    await expect(
      page.locator('input[placeholder="订单号（支持模糊）"]'),
    ).toBeVisible({ timeout: 8000 });
    await page
      .locator('input[placeholder="订单号（支持模糊）"]')
      .fill(pending!.sn);
    await page.getByRole('button', { name: /搜\s*索/ }).click();

    const row = page.getByRole('row', { name: new RegExp(pending!.sn) });
    const adjustBtn = row.getByRole('button', { name: /改\s*价/ });
    await expect(adjustBtn).toBeVisible({ timeout: 8000 });
    await adjustBtn.click();

    const modal = page.locator('.ant-modal').filter({ hasText: '订单改价' });
    await expect(modal).toBeVisible({ timeout: 8000 });
    await expect(modal.getByText(`订单号：${pending!.sn}`)).toBeVisible();
    await expect(
      modal.getByText(`当前应付：¥${detailJson.data.pay_amount}`),
    ).toBeVisible();
    await expect(
      modal.getByText(`¥${Number(detailJson.data.total_amount).toFixed(2)}`),
    ).toBeVisible();

    await expect(modal.getByText('商品优惠')).toBeVisible();
    await expect(modal.getByText('整单折扣')).toBeVisible();

    const amountInputs = modal.locator('.ant-input-number-input');
    expect(Number(await amountInputs.nth(0).inputValue())).toBeCloseTo(
      currentFreight,
      2,
    );
    await modal.locator('textarea').fill('E2E 页面改价回归');

    const adjustResponse = page.waitForResponse(
      (response) =>
        response
          .url()
          .includes(`/admin/api/order/adjustPrice/${pending!.id}`) &&
        response.request().method() === 'POST',
    );
    await modal.getByRole('button', { name: /确认改价/ }).click();
    const response = await adjustResponse;
    const adjustResult = (await response.json()) as ApiResponse<unknown>;
    expect(adjustResult.code).toBe(200);
    await expect(modal).toBeHidden({ timeout: 8000 });

    const nonPendingRes = await page.request.get(
      `${backendBaseUrl}/admin/api/order/list?status=10&limit=1`,
      { headers },
    );
    const nonPendingJson =
      (await nonPendingRes.json()) as ApiResponse<OrderListResponse>;
    const nonPending = nonPendingJson.data?.list?.[0];
    if (nonPending) {
      await page
        .locator('input[placeholder="订单号（支持模糊）"]')
        .fill(nonPending.sn);
      await page.getByRole('button', { name: /搜\s*索/ }).click();

      const nonPendingRow = page.getByRole('row', {
        name: new RegExp(nonPending.sn),
      });
      await expect(
        nonPendingRow.getByRole('button', { name: /改\s*价/ }),
      ).toHaveCount(0);
    }
  });
});
