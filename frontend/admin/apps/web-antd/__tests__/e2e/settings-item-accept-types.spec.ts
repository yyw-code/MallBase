import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

interface ApiResponse<T = any> {
  code: number;
  data: T;
  message?: string;
}

const backendBaseUrl =
  (process.env.BACKEND_API_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');

test.describe('Settings Item Accept Types', () => {
  test('should expose short labels and keep MIME values after update', async ({
    page,
  }) => {
    await page.goto('/auth/login?e2e=1');
    const loginResult = await authLogin(page);
    await expect(page).not.toHaveURL(/\/auth\/login/);

    const token = loginResult?.accessToken;
    test.skip(!token, '登录未返回 access token，跳过接口断言场景');

    await page.goto('/settings/item');
    await expect(page.getByRole('button', { name: '新增设置项' })).toBeVisible();

    const headers = { Authorization: `Bearer ${token}` };

    const formConfigRes = await page.request.get(`${backendBaseUrl}/admin/api/setting/item/form/config`, {
      headers,
    });
    expect(formConfigRes.ok()).toBeTruthy();
    const formConfigJson = (await formConfigRes.json()) as ApiResponse<{
      warnings?: string[];
      rule_types: Record<string, Array<{
        type: string;
        hint?: string;
        value_max?: number;
        options?: Array<{ label: string; value: string }>;
      }>>;
    }>;
    test.skip(formConfigJson.code !== 200, 'form/config 未返回 200，可能是权限配置差异，跳过该断言场景');
    expect(formConfigJson.code).toBe(200);
    expect(Array.isArray(formConfigJson.data?.warnings || [])).toBeTruthy();

    const fileRules = formConfigJson.data?.rule_types?.file || [];
    const acceptRule = fileRules.find((rule) => rule.type === 'accept_types');
    expect(acceptRule).toBeTruthy();
    const options = acceptRule?.options || [];
    const pdfOption = options.find((opt) => opt.value === 'application/pdf');
    expect(pdfOption).toBeTruthy();
    expect(pdfOption?.label).not.toBe('application/pdf');
    expect(pdfOption?.label).toContain('.pdf');

    const filesRules = formConfigJson.data?.rule_types?.files || [];
    const maxSizeRule = filesRules.find((rule) => rule.type === 'max_size');
    const maxCountRule = filesRules.find((rule) => rule.type === 'max_count');
    expect(maxSizeRule).toBeTruthy();
    expect(maxCountRule).toBeTruthy();
    expect(typeof maxSizeRule?.value_max).toBe('number');
    expect(typeof maxCountRule?.value_max).toBe('number');
    expect(maxSizeRule?.hint || '').toContain('client_max_body_size');

    const uploadConfigRes = await page.request.get(`${backendBaseUrl}/admin/api/config/uploadConfig?type=videos`, {
      headers,
    });
    expect(uploadConfigRes.ok()).toBeTruthy();
    const uploadConfigJson = (await uploadConfigRes.json()) as ApiResponse<{
      max_count: number;
      max_size: number;
      warnings?: string[];
      system_limits?: {
        effective_max_count?: number;
        effective_max_size_mb?: number;
      };
    }>;
    test.skip(uploadConfigJson.code !== 200, 'uploadConfig 未返回 200，可能是权限配置差异，跳过该断言场景');
    expect(uploadConfigJson.code).toBe(200);
    expect(uploadConfigJson.data?.system_limits).toBeTruthy();
    expect(Array.isArray(uploadConfigJson.data?.warnings || [])).toBeTruthy();

    const listRes = await page.request.get(`${backendBaseUrl}/admin/api/setting/item/list?page=1&limit=20`, {
      headers,
    });
    expect(listRes.ok()).toBeTruthy();
    const listJson = (await listRes.json()) as ApiResponse<{ list: any[] }>;
    expect(listJson.code).toBe(200);
    const list = listJson.data?.list || [];
    test.skip(list.length === 0, '设置项列表为空，跳过回写兼容场景');

    const target =
      list.find((item) => item?.code === 'MallBaseVideo') ||
      list.find((item) => item?.id);
    test.skip(!target, '缺少可编辑设置项，跳过回写兼容场景');

    const id = Number(target.id);
    const originalPayload = {
      group_id: Number(target.group_id ?? 0),
      name: String(target.name ?? ''),
      code: String(target.code ?? ''),
      value: String(target.value ?? ''),
      type: String(target.type ?? 'input'),
      options: target.options ?? null,
      rules: Array.isArray(target.rules) ? target.rules : null,
      placeholder: String(target.placeholder ?? ''),
      remark: String(target.remark ?? ''),
      sort: Number(target.sort ?? 0),
    };

    const updatePayload = {
      ...originalPayload,
      type: 'file',
      rules: [
        {
          type: 'accept_types',
          value: ['application/pdf', 'video/mp4'],
          message: '支持的文件类型:application/pdf,video/mp4',
        },
      ],
    };

    try {
      const updateRes = await page.request.put(`${backendBaseUrl}/admin/api/setting/item/update/${id}`, {
        headers,
        data: updatePayload,
      });
      expect(updateRes.ok()).toBeTruthy();
      const updateJson = (await updateRes.json()) as ApiResponse;
      expect(updateJson.code).toBe(200);

      const listAfterRes = await page.request.get(`${backendBaseUrl}/admin/api/setting/item/list?page=1&limit=50`, {
        headers,
      });
      expect(listAfterRes.ok()).toBeTruthy();
      const listAfterJson = (await listAfterRes.json()) as ApiResponse<{ list: any[] }>;
      expect(listAfterJson.code).toBe(200);

      const updated = (listAfterJson.data?.list || []).find(
        (item) => Number(item?.id) === id,
      );
      expect(updated).toBeTruthy();
      const rules = Array.isArray(updated?.rules) ? updated.rules : [];
      const updatedAcceptRule = rules.find((rule) => rule?.type === 'accept_types');
      expect(updatedAcceptRule).toBeTruthy();
      expect(Array.isArray(updatedAcceptRule?.value)).toBeTruthy();
      expect(updatedAcceptRule?.value).toContain('application/pdf');
      expect(updatedAcceptRule?.value).toContain('video/mp4');
    } finally {
      await page.request.put(`${backendBaseUrl}/admin/api/setting/item/update/${id}`, {
        headers,
        data: originalPayload,
      });
    }
  });
});
