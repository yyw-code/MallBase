import { requestClient } from '#/api/request';

export namespace OrderApi {
  /** 订单项快照（下单时固化） */
  export interface OrderItem {
    id: number;
    order_id: number;
    goods_id: number;
    goods_name: string;
    goods_image?: string;
    goods_image_full_url?: string;
    sku_id: number;
    sku_spec?: string;
    unit_price: string;
    quantity: number;
    subtotal: string;
    discount_amount: string;
    pay_amount: string;
    shipped_quantity: number;
    refunded_quantity: number;
    returned_quantity: number;
    create_time?: string;
    update_time?: string;
  }

  /** 订单日志（审计时间轴） */
  export interface OrderLogItem {
    id: number;
    order_id: number;
    from_status: null | number;
    to_status: number;
    operator_type: number;
    operator_id: null | number;
    remark?: string;
    ip?: string;
    create_time: string;
  }

  /** 买家摘要 */
  export interface BuyerInfo {
    id: number;
    nickname?: string;
    mobile?: string;
    email?: string;
    avatar?: null | number | string;
    avatar_full_url?: string;
    status?: null | number;
  }

  export interface DistributionCommissionItem {
    amount: string;
    attribution_scene?: string;
    attribution_target_id?: number;
    attribution_target_type?: string;
    base_amount: string;
    buyer_user_id: number;
    create_time: string;
    distributor_user?: BuyerInfo | null;
    distributor_user_id: number;
    goods_id: number;
    id: number;
    order_id: number;
    order_item_id: number;
    order_sn: string;
    rate: string;
    recovered_amount: string;
    relation_id: number;
    relation_level: number;
    release_time?: string;
    rule_id: number;
    rule_type: string;
    settled_at?: string;
    sku_id: number;
    status: number;
    status_text: string;
  }

  export interface DistributionCommissionSummary {
    list: DistributionCommissionItem[];
    total_amount: string;
  }

  /** 订单主记录（含聚合字段） */
  export interface OrderRecord {
    id: number;
    sn: string;
    user_id: number;
    status: number;
    status_text?: string;
    total_amount: string;
    freight_amount: string;
    discount_amount: string;
    pay_amount: string;
    pay_method?: null | number;
    pay_method_text?: string;
    trade_no?: string;
    receiver_name?: string;
    receiver_phone?: string;
    receiver_province?: string;
    receiver_city?: string;
    receiver_district?: string;
    receiver_address?: string;
    logistics_platform?: string;
    logistics_company_id?: number;
    logistics_company?: string;
    logistics_company_code?: string;
    logistics_sn?: string;
    delivery_type?: 'physical' | 'virtual';
    delivery_type_text?: string;
    delivery_note?: null | string;
    buyer_remark?: string;
    admin_remark?: string;
    paid_at?: null | string;
    shipped_at?: null | string;
    received_at?: null | string;
    completed_at?: null | string;
    closed_at?: null | string;
    expire_at?: null | string;
    create_time: string;
    update_time?: string;
    items?: OrderItem[];
    buyer?: BuyerInfo;
    /** 实时聚合，非落库字段 */
    after_sale_tag_text?: string;
    points_deduction?: null | {
      discount_amount: string;
      returned_points: number;
      status: string;
      used_points: number;
    };
    points_reward?: null | {
      debt_points: number;
      frozen_points: number;
      recovered_points: number;
      release_time: string;
      released_at?: null | string;
      released_points: number;
      reward_points: number;
      status: string;
    };
    member_discount?: null | {
      discount_amount: string;
      level_id: number;
      level_name: string;
    };
    member_growth?: null | {
      after_growth: number;
      after_level_id: number;
      before_growth: number;
      before_level_id: number;
      change_growth: number;
      create_time: string;
      remark?: string;
    };
    distribution_commissions?: DistributionCommissionSummary;
  }

  /** 详情响应（在 OrderRecord 基础上附带 logs） */
  export interface OrderDetail extends OrderRecord {
    logs?: OrderLogItem[];
  }

  /** 列表筛选参数 */
  export interface ListParams {
    sn?: string;
    status?: number;
    user_id?: number;
    buyer_keyword?: string;
    ids?: string;
    logistics_sn?: string;
    created_start?: string;
    created_end?: string;
    has_after_sale?: 0 | 1;
    page?: number;
    limit?: number;
  }

  /** 发货参数 */
  export interface ShipParams {
    delivery_note?: string;
    delivery_type: 'physical' | 'virtual';
    logistics_platform: string;
    logistics_company_id?: number;
    logistics_company_code: string;
    logistics_company: string;
    logistics_sn: string;
  }

  /** 关闭订单参数 */
  export interface CloseParams {
    reason?: string;
  }

  /** 改价参数 */
  export type AdjustMode = 'item_discount' | 'pay_percent';

  export interface AdjustPriceItem {
    order_item_id: number;
    discount_amount: number | string;
  }

  export interface AdjustPriceParams {
    /** 运费（≥0），数字或字符串均可，后端 bcmath 重算 */
    freight_amount: number | string;
    /** 改价方式 */
    adjust_mode: AdjustMode;
    /** 逐商品优惠明细 */
    items?: AdjustPriceItem[];
    /** 整单实付比例，0-100，最多两位小数 */
    pay_percent?: number | string;
    /** 调整原因（可选，≤255） */
    reason?: string;
  }

  /** 枚举选项项 */
  export interface EnumOption {
    value: number;
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

  /** 枚举接口响应 */
  export interface StatusOptionsResponse {
    status: EnumOption[];
    pay_method: EnumOption[];
  }
}

/**
 * 获取订单列表（后台）
 */
export async function getOrderListApi(params?: OrderApi.ListParams) {
  return requestClient.get<{
    list: OrderApi.OrderRecord[];
    total: number;
  }>('/order/list', { params });
}

export async function getOrderStatsApi(params?: OrderApi.ListParams) {
  return requestClient.get<OrderApi.StatsResponse>('/order/stats', {
    params,
  });
}

export async function exportOrderCsvApi(params?: OrderApi.ListParams) {
  return requestClient.download<Blob>('/order/export', { params });
}

/**
 * 获取订单详情（后台）
 */
export async function getOrderDetailApi(id: number) {
  return requestClient.get<OrderApi.OrderDetail>(`/order/detail/${id}`);
}

/**
 * 订单发货或修改已发货订单物流信息
 */
export async function shipOrderApi(id: number, data: OrderApi.ShipParams) {
  return requestClient.post(`/order/ship/${id}`, data);
}

/**
 * 关闭订单（PENDING_PAY / PAID 可关闭，同步回滚库存）
 */
export async function closeOrderApi(id: number, data?: OrderApi.CloseParams) {
  return requestClient.post(`/order/close/${id}`, data ?? {});
}

/**
 * 订单改价（仅 PENDING_PAY；后端权威重算 pay_amount，并顶替旧 PREPAY 流水）
 */
export async function adjustOrderPriceApi(
  id: number,
  data: OrderApi.AdjustPriceParams,
) {
  return requestClient.post(`/order/adjustPrice/${id}`, data);
}

/**
 * 订单枚举选项（状态 + 支付方式）
 */
export async function getOrderStatusOptionsApi() {
  return requestClient.get<OrderApi.StatusOptionsResponse>(
    '/order/statusOptions',
  );
}
