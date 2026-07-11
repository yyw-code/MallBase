import type { GoodsApi } from '#/api/goods';

import { requestClient } from '#/api/request';

export namespace PointsGoodsApi {
  export interface GoodsItem {
    id: number;
    goods_id: number;
    sku_id: number;
    points_price: number;
    exchange_stock: number;
    exchanged_count: number;
    limit_per_user: number;
    sort: number;
    status: number;
    remark?: string;
    goods_name?: string;
    goods_image?: string;
    goods_image_full_url?: string;
    goods_price?: string;
    goods_stock?: number;
    goods_status?: number;
    goods_is_on_sale?: number;
    sku_spec?: string;
    sku_price?: string;
    sku_stock?: number;
    sku_status?: number;
    available_stock?: number;
    create_time: string;
    update_time: string;
  }

  export interface ListParams {
    keyword?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  export interface SaveParams {
    goods_id: number;
    sku_id: number;
    points_price: number;
    exchange_stock?: number;
    limit_per_user?: number;
    sort?: number;
    status?: number;
    remark?: string;
  }

  export type GoodsSelectItem = GoodsApi.GoodsItem;
  export type SkuSelectItem = GoodsApi.SkuItem;
}

export async function getPointsGoodsListApi(
  params?: PointsGoodsApi.ListParams,
) {
  return requestClient.get<{
    list: PointsGoodsApi.GoodsItem[];
    total: number;
  }>('/points/goods/list', { params });
}

export async function getPointsGoodsInfoApi(id: number) {
  return requestClient.get<PointsGoodsApi.GoodsItem>(`/points/goods/info/${id}`);
}

export async function createPointsGoodsApi(data: PointsGoodsApi.SaveParams) {
  return requestClient.post<{ id: number }>('/points/goods/create', data);
}

export async function updatePointsGoodsApi(
  id: number,
  data: PointsGoodsApi.SaveParams,
) {
  return requestClient.put(`/points/goods/update/${id}`, data);
}

export async function deletePointsGoodsApi(id: number) {
  return requestClient.delete(`/points/goods/delete/${id}`);
}

export async function updatePointsGoodsStatusApi(id: number, status: number) {
  return requestClient.put(`/points/goods/updateStatus/${id}`, { status });
}
