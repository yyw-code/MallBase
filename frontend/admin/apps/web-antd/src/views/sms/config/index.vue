<script lang="ts" setup>
import { getSmsConfigApi, saveSmsConfigApi } from '#/api/sms/config';
import SettingDynamicForm from '#/views/settings/dynamic-form/index.vue';

defineOptions({ name: 'SmsRateLimit' });

const saveSmsRateLimitConfig = (
  _groupCode: string,
  data: Record<string, any>,
) => saveSmsConfigApi(data);
</script>

<template>
  <SettingDynamicForm
    group-code="SmsRateLimit"
    save-access-code="SmsConfigSave"
    :load-config-api="getSmsConfigApi"
    :save-config-api="saveSmsRateLimitConfig"
  />
</template>
