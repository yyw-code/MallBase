import { requestClient } from '#/api/request';

export namespace GoodsBrandApi {
  /** 商品品牌信息 */
  export interface BrandItem {
    id: number;
    name: string;
    logo?: string;
    logo_full_url?: string;
    description?: string;
    sort: number;
    status: number;
    create_time: string;
    update_time: string;
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
    logo?: string;
    description?: string;
    sort?: number;
    status?: number;
  }

  /** 更新参数 */
  export interface UpdateParams {
    name?: string;
    logo?: string;
    description?: string;
    sort?: number;
    status?: number;
  }
}

/**
 * 获取商品品牌列表
 */
export async function getGoodsBrandListApi(
  params?: GoodsBrandApi.ListParams,
) {
  return requestClient.get<{
    list: GoodsBrandApi.BrandItem[];
    total: number;
  }>('/goods/brand/list', { params });
}

/**
 * 获取商品品牌详情
 */
export async function getGoodsBrandInfoApi(id: number) {
  return requestClient.get<GoodsBrandApi.BrandItem>(`/goods/brand/info/${id}`);
}

/**
 * 获取所有商品品牌（不分页）
 */
export async function getAllGoodsBrandsApi() {
  return requestClient.get<GoodsBrandApi.BrandItem[]>('/goods/brand/all');
}

/**
 * 创建商品品牌
 */
export async function createGoodsBrandApi(data: GoodsBrandApi.CreateParams) {
  return requestClient.post<{ id: number }>('/goods/brand/create', data);
}

/**
 * 更新商品品牌
 */
export async function updateGoodsBrandApi(
  id: number,
  data: GoodsBrandApi.UpdateParams,
) {
  return requestClient.put(`/goods/brand/update/${id}`, data);
}

/**
 * 删除商品品牌
 */
export async function deleteGoodsBrandApi(id: number) {
  return requestClient.delete(`/goods/brand/delete/${id}`);
}

/**
 * 更新商品品牌状态
 */
export async function updateGoodsBrandStatusApi(id: number, status: number) {
  return requestClient.put(`/goods/brand/updateStatus/${id}`, { status });
}
