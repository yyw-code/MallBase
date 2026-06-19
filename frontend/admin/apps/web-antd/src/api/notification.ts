import { requestClient } from '#/api/request';

export namespace NotificationApi {
  export interface Item {
    date: string;
    id: number | string;
    is_read: boolean;
    link?: string;
    message: string;
    query?: Record<string, number | string>;
    title: string;
  }
}

export async function getNotificationPendingShipmentApi() {
  return requestClient.get<NotificationApi.Item[]>(
    '/notification/pending-shipment',
  );
}

export async function getNotificationRefundPendingApi() {
  return requestClient.get<NotificationApi.Item[]>(
    '/notification/refund-pending',
  );
}

export async function getNotificationStockWarningApi() {
  return requestClient.get<NotificationApi.Item[]>(
    '/notification/stock-warning',
  );
}

export async function getNotificationLogisticsConfigApi() {
  return requestClient.get<NotificationApi.Item[]>(
    '/notification/logistics-config',
  );
}

export async function getNotificationSmsProviderConfigApi() {
  return requestClient.get<NotificationApi.Item[]>(
    '/notification/sms-provider-config',
  );
}
