import { requestClient } from '#/api/request';

export namespace AnalyticsApi {
  export interface Card {
    key: string;
    title: string;
    total_title: string;
    total_value: number;
    value: number;
  }

  export interface Trend {
    amount: number[];
    labels: string[];
    orders: number[];
  }

  export interface MonthlyOrders {
    labels: string[];
    orders: number[];
  }

  export interface Health {
    current: number[];
    indicators: string[];
    previous: number[];
  }

  export interface PieItem {
    name: string;
    value: number;
  }
}

export async function getAnalyticsCardsApi() {
  return requestClient.get<AnalyticsApi.Card[]>('/analytics/cards');
}

export async function getAnalyticsTrendApi() {
  return requestClient.get<AnalyticsApi.Trend>('/analytics/trend');
}

export async function getAnalyticsMonthlyOrdersApi() {
  return requestClient.get<AnalyticsApi.MonthlyOrders>(
    '/analytics/monthly-orders',
  );
}

export async function getAnalyticsHealthApi() {
  return requestClient.get<AnalyticsApi.Health>('/analytics/health');
}

export async function getAnalyticsOrderChannelsApi() {
  return requestClient.get<AnalyticsApi.PieItem[]>('/analytics/order-channels');
}

export async function getAnalyticsSalesStructureApi() {
  return requestClient.get<AnalyticsApi.PieItem[]>(
    '/analytics/sales-structure',
  );
}
