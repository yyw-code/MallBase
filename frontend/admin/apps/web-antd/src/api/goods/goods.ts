import { requestClient } from '#/api/request';

export namespace GoodsApi {
  export type MediaValue = number | string;
  export type GoodsPointsRewardMode =
    | 'global'
    | 'disabled'
    | 'ratio'
    | 'fixed'
    | 'sku'
    | 'inherit';
  export type SkuPointsRewardMode =
    | 'inherit'
    | 'disabled'
    | 'ratio'
    | 'fixed';
  export type MemberBenefitMode =
    | 'global'
    | 'disabled'
    | 'level_discount'
    | 'sku_price';

  export interface SpecMetaValueItem {
    value: string;
    pic?: MediaValue;
    pic_full_url?: string;
  }

  export interface SpecMetaItem {
    name: string;
    add_pic: 0 | 1;
    values: SpecMetaValueItem[];
  }

  /** 商品信息 */
  export interface GoodsItem {
    id: number;
    spec_type: 1 | 2;
    category_id: number;
    brand_id?: number;
    freight_template_id?: number;
    name: string;
    subtitle?: string;
    main_image?: MediaValue;
    main_image_full_url?: string;
    main_video?: MediaValue;
    main_video_full_url?: string;
    description?: string;
    sku_detail_enabled?: 0 | 1;
    price: number;
    market_price?: number;
    stock: number;
    sales: number;
    unit?: string;
    is_on_sale: number;
    is_recommend: number;
    is_new: number;
    is_hot: number;
    points_reward_mode?: GoodsPointsRewardMode;
    points_reward_ratio?: number;
    points_reward_fixed?: number;
    member_benefit_mode?: MemberBenefitMode;
    sort: number;
    status: number;
    create_time: string;
    update_time: string;
    category_name?: string;
    brand_name?: string;
    tags?: GoodsTagItem[];
    images?: ImageItem[];
    skus?: SkuItem[];
    spec_meta?: SpecMetaItem[];
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
    url: MediaValue;
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
    image?: MediaValue;
    image_full_url?: string;
    description?: string;
    weight?: number;
    volume?: number;
    points_reward_mode?: SkuPointsRewardMode;
    points_reward_ratio?: number;
    points_reward_fixed?: number;
    member_price?: number | string | null;
    status: number;
  }

  /** 列表参数 */
  export type ListView =
    | 'all'
    | 'disabled'
    | 'off_sale'
    | 'on_sale'
    | 'recycle';

  export interface ListParams {
    keyword?: string;
    category_id?: number;
    brand_id?: number;
    is_on_sale?: number;
    status?: number;
    stock_warning?: 0 | 1;
    view?: ListView;
    page?: number;
    limit?: number;
  }

  export interface StatsTab {
    key: ListView;
    label: string;
    count: number;
  }

  export interface StatsResponse {
    tabs: StatsTab[];
    total: number;
  }

  /** 创建参数 */
  export interface CreateParams {
    spec_type?: 1 | 2;
    category_id: number;
    name: string;
    brand_id?: number;
    freight_template_id?: number;
    subtitle?: string;
    main_image?: MediaValue;
    main_video?: MediaValue;
    spec_meta?: SpecMetaItem[];
    description?: string;
    sku_detail_enabled?: 0 | 1;
    price?: number;
    market_price?: number;
    stock?: number;
    unit?: string;
    is_on_sale?: number;
    is_recommend?: number;
    is_new?: number;
    is_hot?: number;
    points_reward_mode?: GoodsPointsRewardMode;
    points_reward_ratio?: number;
    points_reward_fixed?: number;
    member_benefit_mode?: MemberBenefitMode;
    sort?: number;
    status?: number;
    images?: MediaValue[];
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
    image?: MediaValue;
    description?: string;
    weight?: number;
    volume?: number;
    points_reward_mode?: SkuPointsRewardMode;
    points_reward_ratio?: number;
    points_reward_fixed?: number;
    member_price?: number | string | null;
    status?: number;
  }

  /** 更新参数 */
  export interface UpdateParams {
    spec_type?: 1 | 2;
    category_id?: number;
    name?: string;
    brand_id?: number;
    freight_template_id?: number;
    subtitle?: string;
    main_image?: MediaValue;
    main_video?: MediaValue;
    spec_meta?: SpecMetaItem[];
    description?: string;
    sku_detail_enabled?: 0 | 1;
    price?: number;
    market_price?: number;
    stock?: number;
    unit?: string;
    is_on_sale?: number;
    is_recommend?: number;
    is_new?: number;
    is_hot?: number;
    points_reward_mode?: GoodsPointsRewardMode;
    points_reward_ratio?: number;
    points_reward_fixed?: number;
    member_benefit_mode?: MemberBenefitMode;
    sort?: number;
    status?: number;
    images?: MediaValue[];
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

export async function getGoodsStatsApi(params?: GoodsApi.ListParams) {
  return requestClient.get<GoodsApi.StatsResponse>('/goods/list/stats', {
    params,
  });
}

export async function exportGoodsCsvApi(params?: GoodsApi.ListParams) {
  return requestClient.download<Blob>('/goods/list/export', { params });
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
export async function updateGoodsApi(id: number, data: GoodsApi.UpdateParams) {
  return requestClient.put(`/goods/list/update/${id}`, data);
}

/**
 * 删除商品
 */
export async function deleteGoodsApi(id: number) {
  return requestClient.delete(`/goods/list/delete/${id}`);
}

export async function restoreGoodsApi(id: number) {
  return requestClient.put(`/goods/list/restore/${id}`);
}

export async function purgeGoodsApi(id: number) {
  return requestClient.delete(`/goods/list/purge/${id}`);
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
export async function updateGoodsOnSaleApi(id: number, is_on_sale: number) {
  return requestClient.put(`/goods/list/updateOnSale/${id}`, { is_on_sale });
}
