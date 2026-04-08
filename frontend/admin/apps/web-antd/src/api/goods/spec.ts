import { requestClient } from '#/api/request';

export namespace GoodsSpecApi {
  /** 商品规格信息 */
  export interface SpecItem {
    id: number;
    name: string;
    description?: string;
    sort: number;
    status: number;
    create_time: string;
    update_time: string;
    spec_values?: SpecValueItem[];
  }

  /** 规格值信息 */
  export interface SpecValueItem {
    id: number;
    spec_id: number;
    value: string;
    sort: number;
  }

  /** 列表参数 */
  export interface ListParams {
    name?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  /** 创建参数 */
  export interface CreateParams {
    name: string;
    description?: string;
    sort?: number;
    status?: number;
  }

  /** 更新参数 */
  export interface UpdateParams {
    name?: string;
    description?: string;
    sort?: number;
    status?: number;
  }
}

/**
 * 获取商品规格列表
 */
export async function getGoodsSpecListApi(params?: GoodsSpecApi.ListParams) {
  return requestClient.get<{
    list: GoodsSpecApi.SpecItem[];
    total: number;
  }>('/goods/spec/list', { params });
}

/**
 * 获取商品规格详情
 */
export async function getGoodsSpecInfoApi(id: number) {
  return requestClient.get<GoodsSpecApi.SpecItem>(`/goods/spec/info/${id}`);
}

/**
 * 获取所有商品规格（不分页）
 */
export async function getAllGoodsSpecsApi() {
  return requestClient.get<GoodsSpecApi.SpecItem[]>('/goods/spec/all');
}

/**
 * 创建商品规格
 */
export async function createGoodsSpecApi(data: GoodsSpecApi.CreateParams) {
  return requestClient.post<{ id: number }>('/goods/spec/create', data);
}

/**
 * 更新商品规格
 */
export async function updateGoodsSpecApi(
  id: number,
  data: GoodsSpecApi.UpdateParams,
) {
  return requestClient.put(`/goods/spec/update/${id}`, data);
}

/**
 * 删除商品规格
 */
export async function deleteGoodsSpecApi(id: number) {
  return requestClient.delete(`/goods/spec/delete/${id}`);
}

/**
 * 更新商品规格状态
 */
export async function updateGoodsSpecStatusApi(id: number, status: number) {
  return requestClient.put(`/goods/spec/updateStatus/${id}`, { status });
}

/**
 * 创建规格值
 */
export async function createSpecValueApi(
  specId: number,
  value: string,
  sort?: number,
) {
  return requestClient.post<{ id: number }>('/goods/spec/createValue', {
    spec_id: specId,
    value,
    sort,
  });
}

/**
 * 批量创建规格值
 */
export async function batchCreateSpecValuesApi(
  specId: number,
  values: string[],
) {
  return requestClient.post<{ ids: number[] }>(
    '/goods/spec/batchCreateValues',
    { spec_id: specId, values },
  );
}

/**
 * 删除规格值
 */
export async function deleteSpecValueApi(id: number) {
  return requestClient.delete(`/goods/spec/deleteSpecValue/${id}`);
}
