import { requestClient } from '#/api/request';

export namespace GoodsCategoryApi {
  /** 商品分类信息 */
  export interface CategoryItem {
    id: number;
    pid: number;
    name: string;
    icon?: string;
    image?: string;
    description?: string;
    sort: number;
    status: number;
    create_time: string;
    update_time: string;
  }

  /** 列表参数 */
  export interface ListParams {
    name?: string;
    pid?: number;
    status?: number;
    page?: number;
    limit?: number;
  }

  /** 创建参数 */
  export interface CreateParams {
    pid: number;
    name: string;
    icon?: string;
    image?: string;
    description?: string;
    sort?: number;
    status?: number;
  }

  /** 更新参数 */
  export interface UpdateParams {
    pid?: number;
    name?: string;
    icon?: string;
    image?: string;
    description?: string;
    sort?: number;
    status?: number;
  }
}

/**
 * 获取商品分类列表
 */
export async function getGoodsCategoryListApi(
  params?: GoodsCategoryApi.ListParams,
) {
  return requestClient.get<{
    list: GoodsCategoryApi.CategoryItem[];
    total: number;
  }>('/goods/category/list', { params });
}

/**
 * 获取商品分类详情
 */
export async function getGoodsCategoryInfoApi(id: number) {
  return requestClient.get<GoodsCategoryApi.CategoryItem>(
    `/goods/category/info/${id}`,
  );
}

/**
 * 获取所有商品分类（不分页）
 */
export async function getAllGoodsCategoriesApi() {
  return requestClient.get<GoodsCategoryApi.CategoryItem[]>(
    '/goods/category/all',
  );
}

/**
 * 创建商品分类
 */
export async function createGoodsCategoryApi(
  data: GoodsCategoryApi.CreateParams,
) {
  return requestClient.post<{ id: number }>('/goods/category/create', data);
}

/**
 * 更新商品分类
 */
export async function updateGoodsCategoryApi(
  id: number,
  data: GoodsCategoryApi.UpdateParams,
) {
  return requestClient.put(`/goods/category/update/${id}`, data);
}

/**
 * 删除商品分类
 */
export async function deleteGoodsCategoryApi(id: number) {
  return requestClient.delete(`/goods/category/delete/${id}`);
}

/**
 * 更新商品分类状态
 */
export async function updateGoodsCategoryStatusApi(
  id: number,
  status: number,
) {
  return requestClient.put(`/goods/category/updateStatus/${id}`, { status });
}
