import { requestClient } from '#/api/request';

export namespace GoodsApi {
  /** 商品信息 */
  export interface GoodsItem {
    id: number;
    category_id: number;
    brand_id?: number;
    name: string;
    subtitle?: string;
    main_image?: string;
    main_image_full_url?: string;
    main_video?: string;
    main_video_full_url?: string;
    description?: string;
    price: number;
    market_price?: number;
    stock: number;
    sales: number;
    unit?: string;
    is_on_sale: number;
    is_recommend: number;
    is_new: number;
    is_hot: number;
    sort: number;
    status: number;
    create_time: string;
    update_time: string;
    category_name?: string;
    brand_name?: string;
    tags?: GoodsTagItem[];
    images?: ImageItem[];
    skus?: SkuItem[];
  }

  /** 商品标签简要信息 */
  export interface GoodsTagItem {
    id: number;
    name: string;
    color?: string;
  }

  /** 商品图片信息 */
  export interface ImageItem {
    id: number;
    goods_id: number;
    url: string;
    full_url?: string;
    sort: number;
  }

  /** 商品SKU信息 */
  export interface SkuItem {
    id: number;
    goods_id: number;
    spec_values: string;
    price: number;
    market_price?: number;
    cost_price?: number;
    stock: number;
    sku_code?: string;
    image?: string;
    image_full_url?: string;
    weight?: number;
    volume?: number;
    status: number;
  }

  /** 列表参数 */
  export interface ListParams {
    keyword?: string;
    category_id?: number;
    brand_id?: number;
    is_on_sale?: number;
    status?: number;
    page?: number;
    limit?: number;
  }

  /** 创建参数 */
  export interface CreateParams {
    category_id: number;
    name: string;
    brand_id?: number;
    subtitle?: string;
    main_image?: string;
    main_video?: string;
    description?: string;
    price?: number;
    market_price?: number;
    stock?: number;
    unit?: string;
    is_on_sale?: number;
    is_recommend?: number;
    is_new?: number;
    is_hot?: number;
    sort?: number;
    status?: number;
    images?: { url: string; sort: number }[];
    skus?: SkuCreateParams[];
    tag_ids?: number[];
  }

  /** SKU创建参数 */
  export interface SkuCreateParams {
    spec_values: string;
    price: number;
    market_price?: number;
    cost_price?: number;
    stock: number;
    sku_code?: string;
    image?: string;
    weight?: number;
    volume?: number;
    status?: number;
  }

  /** 更新参数 */
  export interface UpdateParams {
    category_id?: number;
    name?: string;
    brand_id?: number;
    subtitle?: string;
    main_image?: string;
    main_video?: string;
    description?: string;
    price?: number;
    market_price?: number;
    stock?: number;
    unit?: string;
    is_on_sale?: number;
    is_recommend?: number;
    is_new?: number;
    is_hot?: number;
    sort?: number;
    status?: number;
    images?: { url: string; sort: number }[];
    skus?: SkuCreateParams[];
    tag_ids?: number[];
  }
}

/**
 * 获取商品列表
 */
export async function getGoodsListApi(params?: GoodsApi.ListParams) {
  return requestClient.get<{
    list: GoodsApi.GoodsItem[];
    total: number;
  }>('/goods/list/list', { params });
}

/**
 * 获取商品详情
 */
export async function getGoodsInfoApi(id: number) {
  return requestClient.get<GoodsApi.GoodsItem>(`/goods/list/info/${id}`);
}

/**
 * 创建商品
 */
export async function createGoodsApi(data: GoodsApi.CreateParams) {
  return requestClient.post<{ id: number }>('/goods/list/create', data);
}

/**
 * 更新商品
 */
export async function updateGoodsApi(
  id: number,
  data: GoodsApi.UpdateParams,
) {
  return requestClient.put(`/goods/list/update/${id}`, data);
}

/**
 * 删除商品
 */
export async function deleteGoodsApi(id: number) {
  return requestClient.delete(`/goods/list/delete/${id}`);
}

/**
 * 更新商品状态
 */
export async function updateGoodsStatusApi(id: number, status: number) {
  return requestClient.put(`/goods/list/updateStatus/${id}`, { status });
}

/**
 * 更新商品上架/下架状态
 */
export async function updateGoodsOnSaleApi(
  id: number,
  is_on_sale: number,
) {
  return requestClient.put(`/goods/list/updateOnSale/${id}`, { is_on_sale });
}
