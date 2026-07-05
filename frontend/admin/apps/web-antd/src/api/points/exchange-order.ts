import { requestClient } from '#/api/request';

export namespace PointsExchangeOrderApi {
  export interface OrderLogItem {
    id: number;
    exchange_order_id: number;
    exchange_sn: string;
    action: string;
    action_text?: string;
    from_status?: null | number;
    from_status_text?: string;
    to_status: number;
    to_status_text?: string;
    operator_type: number;
    operator_type_text?: string;
    operator_id?: null | number;
    remark?: string;
    create_time: string;
  }

  export interface OrderItem {
    id: number;
    sn: string;
    user_id: number;
    points_goods_id: number;
    goods_id: number;
    sku_id: number;
    goods_name: string;
    goods_image?: string;
    goods_image_full_url?: string;
    sku_spec?: string;
    points_price: number;
    quantity: number;
    total_points: number;
    address_id: number;
    receiver_name: string;
    receiver_phone: string;
    receiver_province: string;
    receiver_city: string;
    receiver_district: string;
    receiver_address: string;
    receiver_full_address?: string;
    status: number;
    status_text?: string;
    delivery_type?: 'physical' | 'virtual';
    delivery_type_text?: string;
    delivery_note?: string;
    logistics_platform?: string;
    logistics_company_id?: number;
    logistics_company_code?: string;
    logistics_company?: string;
    logistics_no?: string;
    buyer_remark?: string;
    admin_remark?: string;
    shipped_at?: string;
    completed_at?: string;
    closed_at?: string;
    logs?: OrderLogItem[];
    create_time: string;
    update_time: string;
  }

  export interface ListParams {
    user_id?: number;
    status?: number;
    sn?: string;
    page?: number;
    limit?: number;
  }

  export interface StatusOption {
    label: string;
    value: number;
  }

  export interface ShipParams {
    delivery_note?: string;
    delivery_type: 'physical' | 'virtual';
    logistics_platform: string;
    logistics_company_id?: number;
    logistics_company_code: string;
    logistics_company: string;
    logistics_no: string;
    admin_remark?: string;
  }

  export interface CloseParams {
    admin_remark: string;
  }
}

export async function getPointsExchangeOrderListApi(
  params?: PointsExchangeOrderApi.ListParams,
) {
  return requestClient.get<{
    list: PointsExchangeOrderApi.OrderItem[];
    total: number;
  }>('/points/exchange-order/list', { params });
}

export async function getPointsExchangeOrderInfoApi(id: number) {
  return requestClient.get<PointsExchangeOrderApi.OrderItem>(
    `/points/exchange-order/info/${id}`,
  );
}

export async function getPointsExchangeOrderStatusOptionsApi() {
  return requestClient.get<PointsExchangeOrderApi.StatusOption[]>(
    '/points/exchange-order/statusOptions',
  );
}

export async function shipPointsExchangeOrderApi(
  id: number,
  data: PointsExchangeOrderApi.ShipParams,
) {
  return requestClient.post(`/points/exchange-order/ship/${id}`, data);
}

export async function completePointsExchangeOrderApi(id: number) {
  return requestClient.post(`/points/exchange-order/complete/${id}`);
}

export async function closePointsExchangeOrderApi(
  id: number,
  data: PointsExchangeOrderApi.CloseParams,
) {
  return requestClient.post(`/points/exchange-order/close/${id}`, data);
}
