import { requestClient } from '#/api/request';

export namespace SmsConfigApi {
  export interface ConfigItem {
    id: number;
    code_ttl: number;
    rate_mobile_daily: number;
    rate_ip_minute: number;
    create_time?: string;
    update_time?: string;
  }

  export interface SaveParams {
    code_ttl: number;
    rate_mobile_daily: number;
    rate_ip_minute: number;
  }
}

export async function getSmsConfigApi() {
  return requestClient.get<SmsConfigApi.ConfigItem>('/sms/config/info');
}

export async function saveSmsConfigApi(data: SmsConfigApi.SaveParams) {
  return requestClient.post('/sms/config/save', data);
}
