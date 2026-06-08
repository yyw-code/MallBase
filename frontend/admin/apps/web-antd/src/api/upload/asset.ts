import { requestClient } from '#/api/request';

export namespace UploadAssetApi {
  export interface AssetItem {
    id: number;
    category_id: number;
    category_name?: string;
    type: 'file' | 'image' | 'video';
    name: string;
    original_name?: string;
    mime?: string;
    ext?: string;
    size: number;
    module?: string;
    uploader_type?: string;
    uploader_id?: number;
    visibility?: string;
    status: number;
    driver?: string;
    path?: string;
    full_url?: string;
    usage_count?: number;
    create_time?: string;
  }

  export interface CategoryItem {
    id: number;
    pid: number;
    name: string;
    code: string;
    sort: number;
    is_system: number;
    status: number;
    children?: CategoryItem[];
  }

  export interface MigrationItem {
    id: number;
    name: string;
    source_driver: string;
    target_driver: string;
    status: number;
    total: number;
    success_count: number;
    fail_count: number;
    last_error?: string;
    create_time?: string;
    update_time?: string;
  }

  export interface ListResult<T> {
    total: number;
    list: T[];
  }
}

export function getUploadAssetListApi(params?: Record<string, any>) {
  return requestClient.get<UploadAssetApi.ListResult<UploadAssetApi.AssetItem>>(
    '/upload/asset/list',
    { params },
  );
}

export function selectUploadAssetsApi(params?: Record<string, any>) {
  return requestClient.get<UploadAssetApi.ListResult<UploadAssetApi.AssetItem>>(
    '/upload/asset/select',
    { params },
  );
}

export function updateUploadAssetApi(id: number, data: Record<string, any>) {
  return requestClient.put(`/upload/asset/update/${id}`, data);
}

export function moveUploadAssetApi(id: number, categoryId: number) {
  return requestClient.put(`/upload/asset/move/${id}`, { category_id: categoryId });
}

export function deleteUploadAssetApi(id: number) {
  return requestClient.delete(`/upload/asset/delete/${id}`);
}

export function restoreUploadAssetApi(id: number) {
  return requestClient.put(`/upload/asset/restore/${id}`);
}

export function purgeUploadAssetApi(id: number) {
  return requestClient.delete(`/upload/asset/purge/${id}`);
}

export function getUploadAssetUsageApi(id: number) {
  return requestClient.get(`/upload/asset/usage/${id}`);
}

export function getUploadAssetCategoryListApi(params?: Record<string, any>) {
  return requestClient.get<UploadAssetApi.ListResult<UploadAssetApi.CategoryItem>>(
    '/upload/asset/category/list',
    { params },
  );
}

export function getUploadAssetCategoryTreeApi(params?: Record<string, any>) {
  return requestClient.get<UploadAssetApi.CategoryItem[]>('/upload/asset/category/tree', {
    params,
  });
}

export function createUploadAssetCategoryApi(data: Record<string, any>) {
  return requestClient.post('/upload/asset/category/create', data);
}

export function updateUploadAssetCategoryApi(id: number, data: Record<string, any>) {
  return requestClient.put(`/upload/asset/category/update/${id}`, data);
}

export function deleteUploadAssetCategoryApi(id: number) {
  return requestClient.delete(`/upload/asset/category/delete/${id}`);
}

export function getUploadAssetMigrationListApi(params?: Record<string, any>) {
  return requestClient.get<UploadAssetApi.ListResult<UploadAssetApi.MigrationItem>>(
    '/upload/asset/migration/list',
    { params },
  );
}

export function createUploadAssetMigrationApi(data: Record<string, any>) {
  return requestClient.post('/upload/asset/migration/create', data);
}

export function retryUploadAssetMigrationApi(id: number) {
  return requestClient.post(`/upload/asset/migration/retry/${id}`);
}

export function cleanupUploadAssetMigrationApi(keepDays = 30) {
  return requestClient.delete('/upload/asset/migration/cleanup', {
    params: { keep_days: keepDays },
  });
}
