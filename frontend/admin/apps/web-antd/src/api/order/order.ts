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
    logistics_company?: string;
    logistics_sn?: string;
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
    /** 实时聚合，非落库字段 */
    after_sale_tag_text?: string;
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
    logistics_sn?: string;
    created_start?: string;
    created_end?: string;
    has_after_sale?: 0 | 1;
    page?: number;
    limit?: number;
  }

  /** 发货参数 */
  export interface ShipParams {
    logistics_company: string;
    logistics_sn: string;
  }

  /** 关闭订单参数 */
  export interface CloseParams {
    reason?: string;
  }

  /** 改价参数 */
  export interface AdjustPriceParams {
    /** 运费（≥0），数字或字符串均可，后端 bcmath 重算 */
    freight_amount: number | string;
    /** 优惠（允许负数=加价） */
    discount_amount: number | string;
    /** 调整原因（可选，≤255） */
    reason?: string;
  }

  /** 枚举选项项 */
  export interface EnumOption {
    value: number;
    label: string;
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

/**
 * 获取订单详情（后台）
 */
export async function getOrderDetailApi(id: number) {
  return requestClient.get<OrderApi.OrderDetail>(`/order/detail/${id}`);
}

/**
 * 订单发货（PAID → SHIPPED）
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
  return requestClient.post<void>(`/order/adjustPrice/${id}`, data);
}

/**
 * 订单枚举选项（状态 + 支付方式）
 */
export async function getOrderStatusOptionsApi() {
  return requestClient.get<OrderApi.StatusOptionsResponse>(
    '/order/statusOptions',
  );
}
