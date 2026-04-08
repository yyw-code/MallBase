import { requestClient } from '#/api/request';

export namespace GoodsTagApi {
  /** 商品标签信息 */
  export interface TagItem {
    id: number;
    name: string;
    color?: string;
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
    color?: string;
    sort?: number;
    status?: number;
  }

  /** 更新参数 */
  export interface UpdateParams {
    name?: string;
    color?: string;
    sort?: number;
    status?: number;
  }
}

/**
 * 获取商品标签列表
 */
export async function getGoodsTagListApi(params?: GoodsTagApi.ListParams) {
  return requestClient.get<{
    list: GoodsTagApi.TagItem[];
    total: number;
  }>('/goods/tag/list', { params });
}

/**
 * 获取商品标签详情
 */
export async function getGoodsTagInfoApi(id: number) {
  return requestClient.get<GoodsTagApi.TagItem>(`/goods/tag/info/${id}`);
}

/**
 * 获取所有商品标签（不分页）
 */
export async function getAllGoodsTagsApi() {
  return requestClient.get<GoodsTagApi.TagItem[]>('/goods/tag/all');
}

/**
 * 创建商品标签
 */
export async function createGoodsTagApi(data: GoodsTagApi.CreateParams) {
  return requestClient.post<{ id: number }>('/goods/tag/create', data);
}

/**
 * 更新商品标签
 */
export async function updateGoodsTagApi(
  id: number,
  data: GoodsTagApi.UpdateParams,
) {
  return requestClient.put(`/goods/tag/update/${id}`, data);
}

/**
 * 删除商品标签
 */
export async function deleteGoodsTagApi(id: number) {
  return requestClient.delete(`/goods/tag/delete/${id}`);
}

/**
 * 更新商品标签状态
 */
export async function updateGoodsTagStatusApi(id: number, status: number) {
  return requestClient.put(`/goods/tag/updateStatus/${id}`, { status });
}
