import { requestClient } from '#/api/request';

export namespace LogisticsApi {
  export interface PlatformConfig {
    business_id?: string;
    key?: string;
    request_type?: '8002';
    [key: string]: any;
  }

  export interface PlatformItem {
    id: number;
    code: string;
    name: string;
    driver: string;
    status: number;
    is_default: number;
    cache_minutes: number;
    config: PlatformConfig;
    key_set?: boolean;
    sort: number;
    create_time?: string;
    update_time?: string;
  }

  export interface PlatformListParams {
    keyword?: string;
    driver?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  export interface PlatformSaveParams {
    id?: number;
    code: string;
    name: string;
    driver: string;
    status: number;
    is_default: number;
    cache_minutes: number;
    config: PlatformConfig;
    sort: number;
  }

  export interface CompanyItem {
    id: number;
    platform: string;
    code: string;
    name: string;
    remark?: string;
    status: number;
    sort: number;
    raw_snapshot?: Record<string, any>;
    last_sync_at?: string;
    create_time?: string;
    update_time?: string;
  }

  export interface CompanyOption {
    id: number;
    platform: string;
    label: string;
    value: number;
    code: string;
    name: string;
  }

  export interface CompanyListParams {
    platform?: string;
    keyword?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  export interface CompanySaveParams {
    id?: number;
    platform: string;
    code: string;
    name: string;
    remark?: string;
    status: number;
    sort: number;
  }
}

export async function getLogisticsPlatformListApi(
  params?: LogisticsApi.PlatformListParams,
) {
  return requestClient.get<{
    list: LogisticsApi.PlatformItem[];
    total: number;
  }>('/logistics/platform/list', { params });
}

export async function saveLogisticsPlatformApi(
  data: LogisticsApi.PlatformSaveParams,
) {
  return requestClient.post<{ id: number }>('/logistics/platform/save', data);
}

export async function clearLogisticsPlatformCacheApi(ids: number[]) {
  return requestClient.post<{ count: number }>(
    '/logistics/platform/clear-cache',
    {
      ids,
    },
  );
}

export async function getLogisticsCompanyListApi(
  params?: LogisticsApi.CompanyListParams,
) {
  return requestClient.get<{
    list: LogisticsApi.CompanyItem[];
    total: number;
  }>('/logistics/company/list', { params });
}

export async function getLogisticsCompanyOptionsApi(platform?: string) {
  return requestClient.get<LogisticsApi.CompanyOption[]>(
    '/logistics/company/options',
    { params: { platform } },
  );
}

export async function updateLogisticsCompanyStatusApi(
  id: number,
  status: number,
) {
  return requestClient.put(`/logistics/company/status/${id}`, { status });
}

export async function saveLogisticsCompanyApi(
  data: LogisticsApi.CompanySaveParams,
) {
  return requestClient.post<{ id: number }>('/logistics/company/save', data);
}

export async function deleteLogisticsCompanyApi(id: number) {
  return requestClient.delete(`/logistics/company/delete/${id}`);
}
