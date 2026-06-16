<script lang="ts" setup>
import type { LogisticsApi } from '#/api/logistics';
import type { OrderApi } from '#/api/order';

import { computed, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import {
  getLogisticsCompanyOptionsApi,
  getLogisticsPlatformListApi,
} from '#/api/logistics';
import { shipOrderApi } from '#/api/order';

interface Props {
  /** 受控显示 */
  open: boolean;
  /** 目标订单（为 null 时隐藏表单） */
  order: null | OrderApi.OrderRecord;
}

const props = defineProps<Props>();
const emit = defineEmits<{
  (e: 'update:open', value: boolean): void;
  (e: 'success'): void;
}>();

const formRef = ref();
const submitting = ref(false);
const loadingPlatforms = ref(false);
const loadingCompanies = ref(false);
const platformOptions = ref<LogisticsApi.PlatformItem[]>([]);
const companyOptions = ref<LogisticsApi.CompanyOption[]>([]);
const isEditLogistics = computed(() => props.order?.status === 20);
const modalTitle = computed(() =>
  isEditLogistics.value ? '修改物流信息' : '订单发货',
);
const submitText = computed(() =>
  isEditLogistics.value ? '保存修改' : '确认发货',
);

const form = reactive<OrderApi.ShipParams>({
  logistics_platform: '',
  logistics_company_id: 0,
  logistics_company_code: '',
  logistics_company: '',
  logistics_sn: '',
});

const rules = {
  logistics_platform: [
    { required: true, message: '请选择物流平台', trigger: 'change' },
  ],
  logistics_company_id: [
    { required: true, message: '请选择物流公司', trigger: 'change' },
  ],
  logistics_sn: [
    { required: true, message: '请输入运单号', trigger: 'blur' },
    { max: 100, message: '运单号最长 100 个字符', trigger: 'blur' },
  ],
};

const showPlatformSelect = computed(() => platformOptions.value.length > 1);

const platformSelectOptions = computed(() =>
  platformOptions.value.map((item) => ({
    label: item.is_default === 1 ? `${item.name}（默认）` : item.name,
    value: item.code,
  })),
);

const companySelectOptions = computed(() =>
  companyOptions.value.map((item) => ({
    label: item.label || item.name,
    value: item.value || item.id,
  })),
);

const defaultPlatform = () =>
  platformOptions.value.find((item) => item.is_default === 1) ||
  platformOptions.value[0];

const loadPlatforms = async () => {
  if (platformOptions.value.length > 0 || loadingPlatforms.value) return;
  loadingPlatforms.value = true;
  try {
    const res = await getLogisticsPlatformListApi({
      limit: 100,
      page: 1,
      status: 1,
    });
    platformOptions.value = res.list || [];
  } catch (error: any) {
    message.error(error?.message || '物流平台加载失败');
  } finally {
    loadingPlatforms.value = false;
  }
};

const loadCompanyOptions = async (platform: string) => {
  companyOptions.value = [];
  if (!platform) return;
  loadingCompanies.value = true;
  try {
    companyOptions.value = await getLogisticsCompanyOptionsApi(platform);
  } catch (error: any) {
    message.error(error?.message || '物流公司加载失败');
  } finally {
    loadingCompanies.value = false;
  }
};

const applyCompanySnapshot = (companyId: number) => {
  const matched = companyOptions.value.find((item) => item.id === companyId);
  form.logistics_company_id = matched?.id || 0;
  form.logistics_company_code = matched?.code || '';
  form.logistics_company = matched?.name || matched?.label || '';
};

const resetCompany = () => {
  form.logistics_company_id = 0;
  form.logistics_company_code = '';
  form.logistics_company = '';
};

const initializeForm = async () => {
  await loadPlatforms();
  const platform =
    props.order?.logistics_platform || defaultPlatform()?.code || '';

  form.logistics_platform = platform;
  form.logistics_company_id = props.order?.logistics_company_id || 0;
  form.logistics_company_code = props.order?.logistics_company_code ?? '';
  form.logistics_company = props.order?.logistics_company ?? '';
  form.logistics_sn = props.order?.logistics_sn ?? '';

  await loadCompanyOptions(platform);

  if (form.logistics_company_id > 0) {
    applyCompanySnapshot(form.logistics_company_id);
  } else if (form.logistics_company_code) {
    const matched = companyOptions.value.find(
      (item) => item.code === form.logistics_company_code,
    );
    if (matched) {
      applyCompanySnapshot(matched.id);
    }
  }
};

watch(
  () => props.open,
  (val) => {
    if (val) {
      void initializeForm();
    }
  },
);

const handlePlatformChange = async (platform?: string) => {
  form.logistics_platform = platform || '';
  resetCompany();
  await loadCompanyOptions(form.logistics_platform);
};

const handleCompanyChange = (value?: number) => {
  applyCompanySnapshot(Number(value || 0));
};

const handleCancel = () => {
  if (submitting.value) return;
  emit('update:open', false);
};

const handleSubmit = async () => {
  if (!props.order) return;
  try {
    await formRef.value?.validate();
  } catch {
    return;
  }
  submitting.value = true;
  try {
    await shipOrderApi(props.order.id, {
      logistics_platform: form.logistics_platform,
      logistics_company_id: form.logistics_company_id,
      logistics_company_code: form.logistics_company_code,
      logistics_company: form.logistics_company.trim(),
      logistics_sn: form.logistics_sn.trim(),
    });
    message.success(isEditLogistics.value ? '物流信息已更新' : '发货成功');
    emit('success');
    emit('update:open', false);
  } catch (error: any) {
    message.error(
      error?.message || (isEditLogistics.value ? '修改失败' : '发货失败'),
    );
  } finally {
    submitting.value = false;
  }
};
</script>

<template>
  <a-modal
    :open="open"
    :title="modalTitle"
    :mask-closable="false"
    :confirm-loading="submitting"
    :ok-text="submitText"
    cancel-text="取消"
    @cancel="handleCancel"
    @ok="handleSubmit"
  >
    <div
      v-if="order"
      class="mb-3 rounded bg-gray-50 p-3 text-xs dark:bg-gray-800"
    >
      <div>订单号：{{ order.sn }}</div>
      <div v-if="order.receiver_name">
        收件人：{{ order.receiver_name }}
        <span v-if="order.receiver_phone"> · {{ order.receiver_phone }}</span>
      </div>
      <div v-if="order.receiver_province">
        收件地址：{{ order.receiver_province }}{{ order.receiver_city
        }}{{ order.receiver_district }}{{ order.receiver_address }}
      </div>
    </div>

    <a-form
      ref="formRef"
      :model="form"
      :rules="rules"
      :label-col="{ style: { width: '100px' } }"
      class="pt-4"
    >
      <a-form-item
        v-if="showPlatformSelect"
        label="物流平台"
        name="logistics_platform"
      >
        <a-select
          v-model:value="form.logistics_platform"
          :loading="loadingPlatforms"
          :options="platformSelectOptions"
          placeholder="请选择物流平台"
          @change="handlePlatformChange"
        />
      </a-form-item>

      <a-form-item label="物流公司" name="logistics_company_id">
        <a-select
          v-model:value="form.logistics_company_id"
          :disabled="!form.logistics_platform"
          :loading="loadingCompanies"
          :options="companySelectOptions"
          placeholder="请选择物流公司"
          show-search
          option-filter-prop="label"
          allow-clear
          @change="handleCompanyChange"
        />
      </a-form-item>
      <a-form-item label="运单号" name="logistics_sn">
        <a-input
          v-model:value="form.logistics_sn"
          placeholder="请输入物流单号"
          allow-clear
          :max-length="100"
        />
      </a-form-item>
    </a-form>
  </a-modal>
</template>
