import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

interface ApiResponse<T = any> {
  code: number;
  data: T;
  message?: string;
}

interface RegionItem {
  id: number;
  name: string;
  level: number;
}

const backendBaseUrl = (
  process.env.BACKEND_API_BASE_URL || 'http://127.0.0.1:8080'
).replace(/\/$/, '');

const CJK_REGEX = /[\u4E00-\u9FA5]/;
const PURE_DIGIT_REGEX = /^\d{3,}$/;

test.describe('Freight template region display', () => {
  test('edit 弹窗的 "适用地区" tag 应回显中文名称，不应出现裸数字 ID', async ({
    page,
  }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过接口断言场景');

    const headers = { Authorization: `Bearer ${token}` };

    // 1) 拿到若干真实 region ID：省 -> 市 -> 区 -> 街道，用于构造规则
    const provincesRes = await page.request.get(
      `${backendBaseUrl}/admin/api/region/children?parent_id=0`,
      { headers },
    );
    expect(provincesRes.ok()).toBeTruthy();
    const provincesJson = (await provincesRes.json()) as ApiResponse<
      RegionItem[]
    >;
    test.skip(
      provincesJson.code !== 200,
      'region/children 未返回 200，跳过该场景',
    );
    const provinces = provincesJson.data || [];
    test.skip(provinces.length === 0, 'region 表为空，无法构造测试数据');

    let cityId = 0;
    let cityName = '';
    const streetIds: number[] = [];
    const streetNameSamples: string[] = [];
    let provinceName = '';

    for (const province of provinces) {
      const citiesRes = await page.request.get(
        `${backendBaseUrl}/admin/api/region/children?parent_id=${province.id}`,
        { headers },
      );
      if (!citiesRes.ok()) continue;
      const cities = ((await citiesRes.json()) as ApiResponse<RegionItem[]>)
        .data || [];
      if (cities.length === 0) continue;

      for (const city of cities) {
        const districtsRes = await page.request.get(
          `${backendBaseUrl}/admin/api/region/children?parent_id=${city.id}`,
          { headers },
        );
        if (!districtsRes.ok()) continue;
        const districts = ((await districtsRes.json()) as ApiResponse<
          RegionItem[]
        >).data || [];
        if (districts.length === 0) continue;

        const collected: number[] = [];
        const collectedNames: string[] = [];
        for (const district of districts) {
          const streetsRes = await page.request.get(
            `${backendBaseUrl}/admin/api/region/children?parent_id=${district.id}`,
            { headers },
          );
          if (!streetsRes.ok()) continue;
          const streets = ((await streetsRes.json()) as ApiResponse<
            RegionItem[]
          >).data || [];
          if (streets.length === 0) continue;
          for (const street of streets) {
            collected.push(street.id);
            collectedNames.push(street.name);
            if (collected.length >= 6) break;
          }
          if (collected.length >= 6) break;
        }

        if (collected.length >= 3) {
          cityId = city.id;
          cityName = city.name;
          streetIds.push(...collected);
          streetNameSamples.push(...collectedNames);
          provinceName = province.name;
          break;
        }
      }

      if (streetIds.length > 0) break;
    }

    test.skip(
      streetIds.length < 3,
      '无法从真实 region 表中收集到 >=3 个街道 ID，跳过',
    );

    // 2) 创建一个测试模板，包含一条城市级规则 + 一条多街道规则
    const templateName = `e2e-freight-display-${Date.now()}`;
    const createPayload = {
      name: templateName,
      charge_type: 'piece' as const,
      default_first_amount: 1,
      default_first_fee: 10,
      default_continue_amount: 1,
      default_continue_fee: 5,
      status: 1,
      remark: 'e2e test template (auto-cleaned)',
      rules: [
        {
          region_ids: [cityId],
          first_amount: 1,
          first_fee: 8,
          continue_amount: 1,
          continue_fee: 3,
          sort: 0,
        },
        {
          region_ids: streetIds,
          first_amount: 1,
          first_fee: 12,
          continue_amount: 1,
          continue_fee: 6,
          sort: 1,
        },
      ],
    };

    const createRes = await page.request.post(
      `${backendBaseUrl}/admin/api/setting/freight-template/create`,
      { headers, data: createPayload },
    );
    expect(createRes.ok()).toBeTruthy();
    const createJson = (await createRes.json()) as ApiResponse<{ id: number }>;
    test.skip(
      createJson.code !== 200,
      '创建模板失败（可能权限配置差异），跳过该场景',
    );
    const templateId = createJson.data?.id;
    expect(templateId).toBeTruthy();

    try {
      // 3) 打开列表页，搜索刚创建的模板并点编辑
      await page.goto('/settings/freight-template');
      await expect(page.getByRole('button', { name: '新增模板' })).toBeVisible();

      await page
        .getByPlaceholder('请输入模板名称')
        .fill(templateName);
      // Ant Design 会在两个中文字符按钮之间自动插入空格 ("搜 索"/"重 置"/"刷 新")，
      // 用正则忽略该空格；参考 a-button autoInsertSpaceInButton 默认行为
      await page.getByRole('button', { name: /搜\s*索/ }).click();

      const editBtn = page
        .getByRole('row', { name: new RegExp(templateName) })
        .getByRole('button', { name: '编辑' });
      await expect(editBtn).toBeVisible();
      await editBtn.click();

      // 4) 等待弹窗 + tag 渲染
      await expect(page.getByRole('dialog')).toBeVisible();
      const tagLocator = page.locator('[data-testid="region-picker-tag"]');
      await expect(tagLocator.first()).toBeVisible();

      // 应渲染出：1（城市级）+ N（街道级，≥3）
      const expectedTagCount = 1 + streetIds.length;
      await expect
        .poll(async () => tagLocator.count(), { timeout: 8000 })
        .toBeGreaterThanOrEqual(expectedTagCount);

      const tagTexts = await tagLocator.allInnerTexts();
      expect(tagTexts.length).toBeGreaterThanOrEqual(expectedTagCount);

      // 每个 tag 都必须包含中文、不允许是纯数字（这正是本 bug 的表象）
      for (const raw of tagTexts) {
        const text = raw.trim();
        expect(
          CJK_REGEX.test(text),
          `tag 文本应包含中文：got "${text}"`,
        ).toBeTruthy();
        expect(
          PURE_DIGIT_REGEX.test(text),
          `tag 文本不应为纯数字 ID：got "${text}"`,
        ).toBeFalsy();
      }

      // 城市级 tag 应当包含城市名；街道级 tag 应当至少包含一个街道名
      const joined = tagTexts.join('\n');
      expect(joined).toContain(cityName);
      const matchedStreet = streetNameSamples.some((n) => joined.includes(n));
      expect(
        matchedStreet,
        `预期至少命中一个街道名：${streetNameSamples.join('、')}`,
      ).toBeTruthy();
      // provinceName 目前仅作为语义调试信息使用，保持引用避免死变量告警
      expect(provinceName.length).toBeGreaterThan(0);
    } finally {
      // 清理：删掉测试模板
      if (templateId) {
        await page.request.delete(
          `${backendBaseUrl}/admin/api/setting/freight-template/delete/${templateId}`,
          { headers },
        );
      }
    }
  });

  test('点击"全选省份"应一键填入全部省级地区并渲染中文 tag', async ({
    page,
  }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    // 以真实 mb_region 为准，动态获取省级数量，避免硬编码阈值随数据漂移
    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过该场景');
    const headers = { Authorization: `Bearer ${token}` };
    const provincesRes = await page.request.get(
      `${backendBaseUrl}/admin/api/region/children?parent_id=0`,
      { headers },
    );
    expect(provincesRes.ok()).toBeTruthy();
    const provincesJson = (await provincesRes.json()) as ApiResponse<
      RegionItem[]
    >;
    test.skip(
      provincesJson.code !== 200,
      'region/children 未返回 200，跳过该场景',
    );
    const expectedProvinceCount = (provincesJson.data || []).length;
    test.skip(expectedProvinceCount === 0, 'region 表为空，无法构造测试数据');

    // 打开新增模板弹窗：ant-design 会在两个中文字符按钮之间插入空格（"新 增 模 板" 4 字则不插入），
    // 这里保守用正则匹配，容忍任何空格规则
    await page.goto('/settings/freight-template');
    await page
      .getByRole('button', { name: /新\s*增\s*模\s*板/ })
      .click();
    await expect(page.getByRole('dialog')).toBeVisible();

    const selectAllBtn = page
      .locator('[data-testid="region-picker-select-all"]')
      .first();
    await expect(selectAllBtn).toBeVisible();
    await selectAllBtn.click();

    const tagLocator = page.locator('[data-testid="region-picker-tag"]');
    // 动态断言：渲染的 tag 数量应与真实 mb_region 中启用的省级数量一致
    await expect
      .poll(async () => tagLocator.count(), { timeout: 8000 })
      .toBe(expectedProvinceCount);

    const tagTexts = await tagLocator.allInnerTexts();
    for (const raw of tagTexts) {
      const text = raw.trim();
      expect(
        CJK_REGEX.test(text),
        `tag 文本应包含中文：got "${text}"`,
      ).toBeTruthy();
      expect(
        PURE_DIGIT_REGEX.test(text),
        `tag 文本不应为纯数字 ID：got "${text}"`,
      ).toBeFalsy();
    }

    // 典型省份名应至少命中一个
    const joined = tagTexts.join('\n');
    expect(joined).toMatch(/北京|上海|广东|天津|浙江/);
  });
});
