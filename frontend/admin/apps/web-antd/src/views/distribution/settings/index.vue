<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import {
  getDistributionSettingsApi,
  saveDistributionSettingsApi,
} from '#/api/distribution';
import SettingDynamicForm from '#/views/settings/dynamic-form/index.vue';

defineOptions({ name: 'DistributionSettings' });

const saveDistributionSettingsConfig = (
  _groupCode: string,
  data: SettingApi.SaveConfigParams,
) => saveDistributionSettingsApi(data);
</script>

<template>
  <SettingDynamicForm
    group-code="DistributionConfig"
    save-access-code="SystemDistributionSettingsSave"
    :load-config-api="getDistributionSettingsApi"
    :save-config-api="saveDistributionSettingsConfig"
  />
</template>
