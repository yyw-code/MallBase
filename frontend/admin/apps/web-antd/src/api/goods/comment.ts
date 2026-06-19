import { requestClient } from '#/api/request';

export namespace GoodsCommentApi {
  /** 商品评论信息 */
  export interface CommentItem {
    id: number;
    goods_id: number;
    user_id: number;
    order_id?: number;
    order_item_id?: number;
    sku_id?: number;
    sku_spec?: string;
    sku_spec_text?: string;
    content: string;
    images?: string[];
    images_full_urls?: string[];
    append_content?: string;
    append_images?: string[];
    append_images_full_urls?: string[];
    append_time?: string;
    rating: number;
    is_anonymous: number;
    reply_content?: string;
    reply_time?: string;
    status: number;
    create_time: string;
    update_time: string;
    user_nickname?: string;
    goods_name?: string;
  }

  /** 列表参数 */
  export interface ListParams {
    goods_id?: number;
    rating?: number;
    status?: number;
    page?: number;
    limit?: number;
  }

  /** 回复参数 */
  export interface ReplyParams {
    reply_content: string;
  }
}

/**
 * 获取商品评论列表
 */
export async function getGoodsCommentListApi(
  params?: GoodsCommentApi.ListParams,
) {
  return requestClient.get<{
    list: GoodsCommentApi.CommentItem[];
    total: number;
  }>('/goods/comment/list', { params });
}

/**
 * 获取商品评论详情
 */
export async function getGoodsCommentInfoApi(id: number) {
  return requestClient.get<GoodsCommentApi.CommentItem>(
    `/goods/comment/info/${id}`,
  );
}

/**
 * 回复商品评论
 */
export async function replyGoodsCommentApi(
  id: number,
  reply_content: string,
) {
  return requestClient.post(`/goods/comment/${id}/reply`, { reply_content });
}

/**
 * 更新商品评论状态
 */
export async function updateGoodsCommentStatusApi(
  id: number,
  status: number,
) {
  return requestClient.put(`/goods/comment/updateStatus/${id}`, { status });
}

/**
 * 删除商品评论
 */
export async function deleteGoodsCommentApi(id: number) {
  return requestClient.delete(`/goods/comment/delete/${id}`);
}
