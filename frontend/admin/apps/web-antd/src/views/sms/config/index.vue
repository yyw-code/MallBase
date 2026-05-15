<script lang="ts" setup>
import type { SmsConfigApi } from '#/api/sms/config';

import { ref } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

import { getSmsConfigApi, saveSmsConfigApi } from '#/api/sms/config';

defineOptions({ name: 'SmsRateLimit' });

const { hasAccessByCodes } = useAccess();

const loading = ref(false);
const saving = ref(false);
const formData = ref<SmsConfigApi.SaveParams>({
  code_ttl: 300,
  rate_mobile_daily: 5,
  rate_ip_minute: 3,
});

const loadConfig = async () => {
  loading.value = true;
  try {
    const data = await getSmsConfigApi();
    formData.value = {
      code_ttl: data.code_ttl,
      rate_mobile_daily: data.rate_mobile_daily,
      rate_ip_minute: data.rate_ip_minute,
    };
  } finally {
    loading.value = false;
  }
};

const handleSave = async () => {
  saving.value = true;
  try {
    await saveSmsConfigApi(formData.value);
    message.success('保存成功');
  } finally {
    saving.value = false;
  }
};

if (hasAccessByCodes(['SmsConfigInfo'])) {
  loadConfig();
}
</script>

<template>
  <div class="p-4">
    <a-spin :spinning="loading">
      <a-form
        :model="formData"
        :label-col="{ span: 6 }"
        :wrapper-col="{ span: 12 }"
        style="max-width: 720px"
      >
        <a-form-item label="验证码有效期(秒)">
          <a-input-number
            v-model:value="formData.code_ttl"
            :min="30"
            :max="3600"
            style="width: 200px"
          />
          <div class="mt-1 text-xs text-gray-500">
            建议 300 秒（5 分钟），范围 30 ~ 3600
          </div>
        </a-form-item>
        <a-form-item label="同手机号 24h 上限">
          <a-input-number
            v-model:value="formData.rate_mobile_daily"
            :min="1"
            :max="100"
            style="width: 200px"
          />
          <div class="mt-1 text-xs text-gray-500">
            建议 5 次,避免被用户当作骚扰
          </div>
        </a-form-item>
        <a-form-item label="同 IP 每分钟上限">
          <a-input-number
            v-model:value="formData.rate_ip_minute"
            :min="1"
            :max="100"
            style="width: 200px"
          />
          <div class="mt-1 text-xs text-gray-500">
            建议 3 次,防止机器人批量发送
          </div>
        </a-form-item>
        <a-form-item :wrapper-col="{ offset: 6 }">
          <a-button
            type="primary"
            :loading="saving"
            @click="handleSave"
            v-access:code="'SmsConfigSave'"
          >
            保存
          </a-button>
          <a-button class="ml-2" @click="loadConfig">重新加载</a-button>
        </a-form-item>
      </a-form>
    </a-spin>
  </div>
</template>
