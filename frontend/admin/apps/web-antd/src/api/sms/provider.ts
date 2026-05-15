import { requestClient } from '#/api/request';

export namespace SmsProviderApi {
  export interface ProviderItem {
    id: number;
    name: string;
    driver: string;
    access_key_id: string;
    access_key_secret?: string;
    access_key_secret_set?: boolean;
    region: string;
    is_default: number;
    status: number;
    remark?: string;
    sort: number;
    create_time?: string;
    update_time?: string;
  }

  export interface ListParams {
    keyword?: string;
    driver?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  export interface SaveParams {
    name: string;
    driver: string;
    access_key_id: string;
    access_key_secret?: string;
    region: string;
    is_default: number;
    status: number;
    remark?: string;
    sort: number;
  }

  export interface TestResult {
    ok: boolean;
    message: string;
  }
}

export async function getSmsProviderListApi(params?: SmsProviderApi.ListParams) {
  return requestClient.get<{
    list: SmsProviderApi.ProviderItem[];
    total: number;
  }>('/sms/provider/list', { params });
}

export async function getSmsProviderInfoApi(id: number) {
  return requestClient.get<SmsProviderApi.ProviderItem>(
    `/sms/provider/info/${id}`,
  );
}

export async function createSmsProviderApi(data: SmsProviderApi.SaveParams) {
  return requestClient.post<{ id: number }>('/sms/provider/create', data);
}

export async function updateSmsProviderApi(
  id: number,
  data: SmsProviderApi.SaveParams,
) {
  return requestClient.put(`/sms/provider/update/${id}`, data);
}

export async function deleteSmsProviderApi(id: number) {
  return requestClient.delete(`/sms/provider/delete/${id}`);
}

export async function testSmsProviderApi(id: number) {
  return requestClient.post<SmsProviderApi.TestResult>(
    `/sms/provider/test/${id}`,
  );
}
