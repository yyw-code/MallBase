<script lang="ts" setup>
import type { OrderApi } from '#/api/order';

import { reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

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

const form = reactive<OrderApi.ShipParams>({
  logistics_company: '',
  logistics_sn: '',
});

const rules = {
  logistics_company: [
    { required: true, message: '请输入物流公司', trigger: 'blur' },
    { max: 100, message: '物流公司最长 100 个字符', trigger: 'blur' },
  ],
  logistics_sn: [
    { required: true, message: '请输入运单号', trigger: 'blur' },
    { max: 100, message: '运单号最长 100 个字符', trigger: 'blur' },
  ],
};

// 每次打开弹窗时清空表单，避免复用上次残留
watch(
  () => props.open,
  (val) => {
    if (val) {
      form.logistics_company = props.order?.logistics_company ?? '';
      form.logistics_sn = props.order?.logistics_sn ?? '';
    }
  },
);

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
      logistics_company: form.logistics_company.trim(),
      logistics_sn: form.logistics_sn.trim(),
    });
    message.success('发货成功');
    emit('success');
    emit('update:open', false);
  } catch (error: any) {
    message.error(error?.message || '发货失败');
  } finally {
    submitting.value = false;
  }
};
</script>

<template>
  <a-modal
    :open="open"
    title="订单发货"
    :mask-closable="false"
    :confirm-loading="submitting"
    ok-text="确认发货"
    cancel-text="取消"
    @cancel="handleCancel"
    @ok="handleSubmit"
  >
    <div v-if="order" class="mb-3 rounded bg-gray-50 p-3 text-xs dark:bg-gray-800">
      <div>订单号：{{ order.sn }}</div>
      <div v-if="order.receiver_name">
        收件人：{{ order.receiver_name }}
        <span v-if="order.receiver_phone"> · {{ order.receiver_phone }}</span>
      </div>
      <div v-if="order.receiver_province">
        收件地址：{{ order.receiver_province }}{{ order.receiver_city }}{{
          order.receiver_district
        }}{{ order.receiver_address }}
      </div>
    </div>

    <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-form-item label="物流公司" name="logistics_company">
        <a-input
          v-model:value="form.logistics_company"
          placeholder="如：顺丰速运 / 圆通快递"
          allow-clear
          :max-length="100"
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
