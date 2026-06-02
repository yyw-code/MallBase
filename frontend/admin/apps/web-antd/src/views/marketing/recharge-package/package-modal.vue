<script lang="ts" setup>
import type { RechargePackageApi } from '#/api/marketing';

import { computed, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import {
  createRechargePackageApi,
  updateRechargePackageApi,
} from '#/api/marketing';
import Upload from '#/components/upload/index.vue';

interface FileInfo {
  url: string;
  full_url?: string;
  name: string;
}

interface Props {
  visible: boolean;
  editData?: null | RechargePackageApi.PackageItem;
}

interface Emits {
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}

const props = withDefaults(defineProps<Props>(), {
  visible: false,
  editData: null,
});

const emit = defineEmits<Emits>();

const formRef = ref();
const loading = ref(false);
const isEdit = computed(() => !!props.editData);
const backgroundImageTip = '建议尺寸 750 x 320 px，比例约 2.35:1，重点内容尽量放在中间偏右区域';

const formData = reactive({
  name: '',
  pay_amount: '',
  gift_amount: '0.00',
  background_image: undefined as FileInfo | string | undefined,
  sort: 0,
  status: 1,
  remark: '',
});

const rules = {
  name: [{ required: true, message: '请输入套餐名称', trigger: 'blur' }],
  pay_amount: [{ required: true, message: '请输入支付金额', trigger: 'blur' }],
};

const balanceAmount = computed(() => {
  const pay = Number(formData.pay_amount || 0);
  const gift = Number(formData.gift_amount || 0);
  return (pay + gift).toFixed(2);
});

watch(
  () => props.visible,
  (visible) => {
    if (!visible) return;
    resetForm();
    if (props.editData) {
      Object.assign(formData, {
        name: props.editData.name,
        pay_amount: props.editData.pay_amount,
        gift_amount: props.editData.gift_amount,
        background_image: props.editData.background_image
          ? {
              url: props.editData.background_image,
              full_url: props.editData.background_image_full_url,
              name: '套餐背景图',
            }
          : undefined,
        sort: props.editData.sort,
        status: props.editData.status,
        remark: props.editData.remark || '',
      });
    }
  },
);

const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    name: '',
    pay_amount: '',
    gift_amount: '0.00',
    background_image: undefined,
    sort: 0,
    status: 1,
    remark: '',
  });
};

const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    loading.value = true;
    const submitData: RechargePackageApi.SaveParams = {
      ...formData,
      background_image:
        typeof formData.background_image === 'object'
          ? formData.background_image?.url || ''
          : formData.background_image || '',
    };

    if (isEdit.value) {
      await updateRechargePackageApi(props.editData!.id, submitData);
      message.success('更新成功');
    } else {
      await createRechargePackageApi(submitData);
      message.success('创建成功');
    }

    emit('success');
    emit('update:visible', false);
  } catch (error: any) {
    if (!error?.errorFields) {
      message.error(error?.message || '操作失败');
    }
  } finally {
    loading.value = false;
  }
};

const handleCancel = () => {
  emit('update:visible', false);
};
</script>

<template>
  <a-modal
    :confirm-loading="loading"
    :open="visible"
    :title="isEdit ? '编辑充值套餐' : '新增充值套餐'"
    @cancel="handleCancel"
    @ok="handleSubmit"
  >
    <a-form
      ref="formRef"
      class="pt-4"
      :label-col="{ style: { width: '100px' } }"
      :model="formData"
      :rules="rules"
    >
      <a-form-item label="套餐名称" name="name">
        <a-input
          v-model:value="formData.name"
          allow-clear
          placeholder="例如：充100送10"
        />
      </a-form-item>

      <a-form-item label="支付金额" name="pay_amount">
        <a-input-number
          v-model:value="formData.pay_amount"
          class="w-full"
          :min="0.01"
          placeholder="用户实际支付金额"
          :precision="2"
          string-mode
        />
      </a-form-item>

      <a-form-item label="赠送金额" name="gift_amount">
        <a-input-number
          v-model:value="formData.gift_amount"
          class="w-full"
          :min="0"
          placeholder="不赠送填 0"
          :precision="2"
          string-mode
        />
      </a-form-item>

      <a-form-item label="到账余额">
        <a-input :value="`¥${balanceAmount}`" disabled />
      </a-form-item>

      <a-form-item
        :extra="backgroundImageTip"
        label="背景图"
        name="background_image"
      >
        <Upload
          v-model:value="formData.background_image"
          module="marketing"
          type="image"
        />
      </a-form-item>

      <a-form-item label="排序" name="sort">
        <a-input-number
          v-model:value="formData.sort"
          class="w-full"
          :max="9999"
          :min="0"
          placeholder="数字越小越靠前"
        />
      </a-form-item>

      <a-form-item label="状态" name="status">
        <a-radio-group v-model:value="formData.status">
          <a-radio :value="1">启用</a-radio>
          <a-radio :value="0">禁用</a-radio>
        </a-radio-group>
      </a-form-item>

      <a-form-item label="备注" name="remark">
        <a-textarea
          v-model:value="formData.remark"
          allow-clear
          :maxlength="255"
          placeholder="内部备注，可留空"
          :rows="3"
          show-count
        />
      </a-form-item>
    </a-form>
  </a-modal>
</template>
