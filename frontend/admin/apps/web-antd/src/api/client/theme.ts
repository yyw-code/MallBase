import { requestClient } from '#/api/request';

export namespace ClientThemeApi {
  export type ThemeMode = 'custom' | 'dark' | 'light' | 'system';
  export type ThemeType = 'custom' | 'dark' | 'light';

  export interface ThemeItem {
    id: number;
    name: string;
    type: ThemeType;
    tokens: Record<string, string>;
    status: number;
    sort: number;
    is_system?: number;
    create_time?: string;
    update_time?: string;
  }

  export interface ListParams {
    keyword?: string;
    page?: number;
    limit?: number;
    status?: number;
    type?: ThemeType;
  }

  export interface SaveParams {
    name: string;
    type: ThemeType;
    tokens: Record<string, string>;
    status?: number;
    sort?: number;
  }

  export interface ThemePolicy {
    allow_user_select: number;
    default_mode: ThemeMode;
    default_theme_id?: null | number;
  }

  export interface ThemeSetting {
    admin_theme_id?: null | number;
    admin_theme_mode: ThemeMode;
    user_select_enabled: number;
  }
}

export async function getClientThemeListApi(
  params?: ClientThemeApi.ListParams,
) {
  return requestClient.get<{
    list: ClientThemeApi.ThemeItem[];
    total: number;
  }>('/client/theme/list', { params });
}

export async function getClientThemeInfoApi(id: number) {
  return requestClient.get<ClientThemeApi.ThemeItem>(
    `/client/theme/info/${id}`,
  );
}

export async function createClientThemeApi(data: ClientThemeApi.SaveParams) {
  return requestClient.post<{ id: number }>('/client/theme/create', data);
}

export async function updateClientThemeApi(
  id: number,
  data: ClientThemeApi.SaveParams,
) {
  return requestClient.put(`/client/theme/update/${id}`, data);
}

export async function copyClientThemeApi(id: number) {
  return requestClient.post<{ id: number }>(`/client/theme/copy/${id}`);
}

export async function publishClientThemeApi(id: number) {
  return requestClient.put(`/client/theme/publish/${id}`);
}

export async function deleteClientThemeApi(id: number) {
  return requestClient.delete(`/client/theme/delete/${id}`);
}

export async function getClientThemePolicyApi() {
  return requestClient.get<ClientThemeApi.ThemePolicy>('/client/theme/policy');
}

export async function updateClientThemePolicyApi(
  data: ClientThemeApi.ThemePolicy,
) {
  return requestClient.put('/client/theme/policy', data);
}

export async function getClientThemeSettingApi() {
  return requestClient.get<ClientThemeApi.ThemeSetting>(
    '/client/theme/setting',
  );
}

export async function updateClientThemeSettingApi(
  data: ClientThemeApi.ThemeSetting,
) {
  return requestClient.put('/client/theme/setting', data);
}
