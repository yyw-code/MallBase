import { requestClient } from '#/api/request';

export namespace RefundApi {
  /** 售后订单项快照 */
  export interface OrderItemSnapshot {
    id: number;
    goods_name: string;
    goods_image?: string;
    goods_image_full_url?: string;
    sku_spec?: string;
    unit_price: string;
    quantity: number;
  }

  /** 关联主订单摘要 */
  export interface OrderSummary {
    id: number;
    sn: string;
    status: number;
    status_text?: string;
    pay_amount?: string;
    receiver_name?: string;
    receiver_phone?: string;
    receiver_province?: string;
    receiver_city?: string;
    receiver_district?: string;
    receiver_address?: string;
    create_time?: string;
    paid_at?: string;
    shipped_at?: string;
    received_at?: string;
  }

  /** 买家信息 */
  export interface UserInfo {
    id: number;
    nickname?: string;
    phone?: string;
    avatar?: string;
    avatar_url?: string;
  }

  /** 审核人信息 */
  export interface ReviewerInfo {
    id: number;
    nickname?: string;
    username?: string;
  }

  /** 售后记录（列表项） */
  export interface RefundRecord {
    id: number;
    sn: string;
    order_id: number;
    order_item_id: number;
    user_id: number;
    type: number;
    type_text?: string;
    receive_status?: number;
    receive_status_text?: string;
    status: number;
    status_text?: string;
    quantity: number;
    refund_amount: string;
    reason: string;
    reason_text?: string;
    remark?: string;
    admin_remark?: string;
    reviewed_by?: null | number;
    reviewed_at?: null | string;
    refunded_at?: null | string;
    canceled_at?: null | string;
    return_receiver_name?: null | string;
    return_receiver_phone?: null | string;
    return_receiver_address?: null | string;
    return_company?: null | string;
    return_tracking_no?: null | string;
    return_shipped_at?: null | string;
    return_received_at?: null | string;
    intercept_status?: string;
    intercept_status_text?: string;
    intercept_note?: null | string;
    create_time: string;
    update_time?: string;
    order?: {
      sn: string;
      status: number;
      status_text?: string;
    } | null;
    order_item?: OrderItemSnapshot | null;
    user?: { id: number; nickname?: string; phone?: string } | null;
  }

  /** 售后详情（含关联完整信息） */
  export interface RefundDetail extends RefundRecord {
    order?: OrderSummary | null;
    order_item?: OrderItemSnapshot | null;
    user?: UserInfo | null;
    reviewer?: ReviewerInfo | null;
  }

  /** 列表筛选参数 */
  export interface ListParams {
    sn?: string;
    order_sn?: string;
    status?: null | number;
    type?: null | number;
    user_phone?: string;
    created_start?: string;
    created_end?: string;
    reviewed_start?: string;
    reviewed_end?: string;
    page?: number;
    limit?: number;
  }

  /** 审核参数 */
  export interface ReviewParams {
    admin_remark?: string;
  }

  /** 枚举选项项 */
  export interface EnumOption {
    value: number | string;
    label: string;
  }

  export interface StatsTab {
    key: string;
    label: string;
    count: number;
  }

  export interface StatsResponse {
    tabs: StatsTab[];
    total: number;
  }

  /** statusOptions 接口响应 */
  export interface StatusOptionsResponse {
    status: EnumOption[];
    type: EnumOption[];
    receive_status: EnumOption[];
    intercept_status: EnumOption[];
    reason: EnumOption[];
  }
}

/**
 * 获取售后列表（后台）
 */
export async function getRefundListApi(params?: RefundApi.ListParams) {
  return requestClient.get<{
    list: RefundApi.RefundRecord[];
    total: number;
  }>('/order/refund/list', { params });
}

export async function getRefundStatsApi(params?: RefundApi.ListParams) {
  return requestClient.get<RefundApi.StatsResponse>('/order/refund/stats', {
    params,
  });
}

export async function exportRefundCsvApi(params?: RefundApi.ListParams) {
  return requestClient.download<Blob>('/order/refund/export', { params });
}

/**
 * 获取售后详情（后台）
 */
export async function getRefundDetailApi(id: number) {
  return requestClient.get<RefundApi.RefundDetail>(
    `/order/refund/detail/${id}`,
  );
}

/**
 * 审核同意售后
 */
export async function approveRefundApi(
  id: number,
  data?: RefundApi.ReviewParams,
) {
  return requestClient.post(`/order/refund/approve/${id}`, data ?? {});
}

/**
 * 审核驳回售后
 */
export async function rejectRefundApi(
  id: number,
  data: RefundApi.ReviewParams,
) {
  return requestClient.post(`/order/refund/reject/${id}`, data);
}

export async function updateRefundInterceptApi(
  id: number,
  data: { intercept_note?: string; intercept_status: string },
) {
  return requestClient.post(`/order/refund/intercept/${id}`, data);
}

export async function confirmRefundReturnApi(
  id: number,
  data?: RefundApi.ReviewParams,
) {
  return requestClient.post(`/order/refund/confirmReturn/${id}`, data ?? {});
}

/**
 * 售后枚举选项（状态 + 类型 + 原因）
 */
export async function getRefundStatusOptionsApi() {
  return requestClient.get<RefundApi.StatusOptionsResponse>(
    '/order/refund/statusOptions',
  );
}

/**
 * 售后原因枚举
 */
export async function getRefundReasonOptionsApi() {
  return requestClient.get<RefundApi.EnumOption[]>(
    '/order/refund/reasonOptions',
  );
}

/**
 * 后台常用售后驳回原因
 */
export async function getRefundRejectReasonOptionsApi() {
  return requestClient.get<RefundApi.EnumOption[]>(
    '/order/refund/rejectReasonOptions',
  );
}
