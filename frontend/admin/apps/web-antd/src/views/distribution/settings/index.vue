<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import {
  getDistributionLevelListApi,
  getDistributionSettingsApi,
  saveDistributionSettingsApi,
} from '#/api/distribution';
import SettingDynamicForm from '#/views/settings/dynamic-form/index.vue';

defineOptions({ name: 'DistributionSettings' });

const saveDistributionSettingsConfig = (
  _groupCode: string,
  data: SettingApi.SaveConfigParams,
) => saveDistributionSettingsApi(data);

const loadDistributionLevelOptions = async (): Promise<SettingApi.OptionItem[]> => {
  const data = await getDistributionLevelListApi({
    limit: 100,
    page: 1,
    status: 1,
  });

  return data.list.map((item) => ({
    label: item.name,
    value: String(item.id),
  }));
};
</script>

<template>
  <SettingDynamicForm
    :field-option-loaders="{ distribution_level: loadDistributionLevelOptions }"
    group-code="DistributionConfig"
    save-access-code="SystemDistributionSettingsSave"
    :load-config-api="getDistributionSettingsApi"
    :save-config-api="saveDistributionSettingsConfig"
  />
</template>
