import { requestClient } from '#/api/request';

export namespace ClientPageApi {
  export type PageCategory =
    | 'aftersale'
    | 'basic'
    | 'goods'
    | 'marketing'
    | 'order'
    | 'other'
    | 'user';
  export type PageSource = 'auto' | 'manual' | 'system';
  export type PageType = 'page' | 'subpackage' | 'tab';

  export interface PageItem {
    id: number;
    name: string;
    path: string;
    page_type: PageType;
    category: PageCategory;
    category_label?: string;
    package_root?: null | string;
    need_login: number;
    source: PageSource;
    remark?: null | string;
    sort: number;
    status: number;
    create_time?: string;
    update_time?: string;
  }

  export interface PagePickerItem {
    id: number;
    name: string;
    path: string;
    page_type: PageType;
    page_type_label: string;
    category: PageCategory;
    category_label: string;
    package_root?: null | string;
    need_login: number;
    source: PageSource;
    remark?: null | string;
  }

  export interface PagePickerGroup {
    count: number;
    items: PagePickerItem[];
    key: PageCategory;
    label: string;
  }

  export interface ListParams {
    keyword?: string;
    page?: number;
    category?: PageCategory;
    page_type?: PageType;
    limit?: number;
    source?: PageSource;
    status?: number;
  }

  export interface PickerParams {
    keyword?: string;
  }

  export interface SaveParams {
    name: string;
    path: string;
    page_type: PageType;
    category?: PageCategory;
    package_root?: null | string;
    need_login?: number;
    source?: PageSource;
    remark?: null | string;
    sort?: number;
    status?: number;
  }

  export interface ImportParams {
    file?: File;
    pages_json?: string;
  }

  export interface ImportResult {
    created: number;
    skipped: number;
    updated: number;
  }
}

export async function getClientPageListApi(params?: ClientPageApi.ListParams) {
  return requestClient.get<{
    list: ClientPageApi.PageItem[];
    total: number;
  }>('/client/page/list', { params });
}

export async function getClientPageInfoApi(id: number) {
  return requestClient.get<ClientPageApi.PageItem>(`/client/page/info/${id}`);
}

export async function getClientPagePickerApi(
  params?: ClientPageApi.PickerParams,
) {
  return requestClient.get<{
    groups: ClientPageApi.PagePickerGroup[];
    total: number;
  }>('/client/page/picker', { params });
}

export async function createClientPageApi(data: ClientPageApi.SaveParams) {
  return requestClient.post<{ id: number }>('/client/page/create', data);
}

export async function updateClientPageApi(
  id: number,
  data: ClientPageApi.SaveParams,
) {
  return requestClient.put(`/client/page/update/${id}`, data);
}

export async function deleteClientPageApi(id: number) {
  return requestClient.delete(`/client/page/delete/${id}`);
}

export async function importClientPageApi(data?: ClientPageApi.ImportParams) {
  if (data?.file) {
    const formData = new FormData();
    formData.append('file', data.file);

    return requestClient.post<ClientPageApi.ImportResult>(
      '/client/page/import',
      formData,
      {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      },
    );
  }

  return requestClient.post<ClientPageApi.ImportResult>('/client/page/import', {
    pages_json: data?.pages_json ?? '',
  });
}
