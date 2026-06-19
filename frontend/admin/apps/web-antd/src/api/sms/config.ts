import type { SettingApi } from '#/api/setting';

import { requestClient } from '#/api/request';

export namespace SmsConfigApi {
  export type ConfigItem = SettingApi.ConfigResponse;

  export type SaveParams = SettingApi.SaveConfigParams;
}

export async function getSmsConfigApi() {
  return requestClient.get<SmsConfigApi.ConfigItem>('/sms/config/info');
}

export async function saveSmsConfigApi(data: SmsConfigApi.SaveParams) {
  return requestClient.post('/sms/config/save', data);
}
