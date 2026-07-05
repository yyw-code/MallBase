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
const isPhysicalDelivery = computed(() => form.delivery_type !== 'virtual');
const modalTitle = computed(() =>
  isEditLogistics.value ? '修改发货信息' : '订单发货',
);
const submitText = computed(() =>
  isEditLogistics.value ? '保存修改' : '确认发货',
);

const form = reactive<OrderApi.ShipParams>({
  delivery_type: 'physical',
  delivery_note: '',
  logistics_platform: '',
  logistics_company_id: undefined,
  logistics_company_code: '',
  logistics_company: '',
  logistics_sn: '',
});

const rules = {
  delivery_note: [
    {
      trigger: 'blur',
      validator: async () => {
        if (form.delivery_type === 'virtual' && !form.delivery_note?.trim()) {
          throw new Error('请填写虚拟发货说明');
        }
      },
    },
  ],
  logistics_company_id: [
    {
      trigger: 'change',
      validator: async () => {
        if (isPhysicalDelivery.value && !form.logistics_company_id) {
          throw new Error('请选择物流公司');
        }
      },
    },
  ],
  logistics_sn: [
    {
      trigger: 'blur',
      validator: async () => {
        if (isPhysicalDelivery.value && !form.logistics_sn?.trim()) {
          throw new Error('请输入运单号');
        }
      },
    },
    { max: 64, message: '运单号最长 64 个字符', trigger: 'blur' },
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
  form.logistics_company_id = matched?.id;
  form.logistics_company_code = matched?.code || '';
  form.logistics_company = matched?.name || matched?.label || '';
};

const resetCompany = () => {
  form.logistics_company_id = undefined;
  form.logistics_company_code = '';
  form.logistics_company = '';
};

const initializeForm = async () => {
  await loadPlatforms();
  const platform =
    props.order?.logistics_platform || defaultPlatform()?.code || '';

  form.logistics_platform = platform;
  form.delivery_type = props.order?.delivery_type || 'physical';
  form.delivery_note = props.order?.delivery_note ?? '';
  form.logistics_company_id = props.order?.logistics_company_id || undefined;
  form.logistics_company_code = props.order?.logistics_company_code ?? '';
  form.logistics_company = props.order?.logistics_company ?? '';
  form.logistics_sn = props.order?.logistics_sn ?? '';

  await loadCompanyOptions(platform);

  const companyId = form.logistics_company_id;
  if (typeof companyId === 'number' && companyId > 0) {
    applyCompanySnapshot(companyId);
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

const handleDeliveryTypeChange = () => {
  formRef.value?.clearValidate?.();
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
    const isVirtual = form.delivery_type === 'virtual';
    await shipOrderApi(props.order.id, {
      delivery_type: form.delivery_type,
      delivery_note: isVirtual ? form.delivery_note?.trim() || '' : '',
      logistics_platform: isVirtual ? '' : form.logistics_platform,
      logistics_company_id: isVirtual ? 0 : form.logistics_company_id || 0,
      logistics_company_code: isVirtual ? '' : form.logistics_company_code,
      logistics_company: isVirtual ? '' : form.logistics_company.trim(),
      logistics_sn: isVirtual ? '' : form.logistics_sn.trim(),
    });
    message.success(isEditLogistics.value ? '发货信息已更新' : '发货成功');
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
      class="mb-3 rounded border border-[hsl(var(--border))] bg-[hsl(var(--muted))] p-3 text-xs text-[hsl(var(--foreground))]"
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
      <a-form-item label="发货方式" name="delivery_type">
        <a-radio-group
          v-model:value="form.delivery_type"
          button-style="solid"
          @change="handleDeliveryTypeChange"
        >
          <a-radio-button value="physical">实物快递</a-radio-button>
          <a-radio-button value="virtual">虚拟发货</a-radio-button>
        </a-radio-group>
      </a-form-item>

      <a-form-item
        v-if="isPhysicalDelivery && showPlatformSelect"
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

      <a-form-item
        v-if="isPhysicalDelivery"
        label="物流公司"
        name="logistics_company_id"
      >
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
      <a-form-item v-if="isPhysicalDelivery" label="运单号" name="logistics_sn">
        <a-input
          v-model:value="form.logistics_sn"
          placeholder="请输入物流单号"
          allow-clear
          :max-length="100"
        />
      </a-form-item>
      <a-form-item v-else label="发货说明" name="delivery_note">
        <a-textarea
          v-model:value="form.delivery_note"
          :maxlength="255"
          :rows="3"
          allow-clear
          placeholder="请输入虚拟发货说明，如卡密已发放、权益已开通"
          show-count
        />
      </a-form-item>
    </a-form>
  </a-modal>
</template>
