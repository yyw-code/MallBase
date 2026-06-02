<script lang="ts" setup>
import type { RefundApi } from '#/api/order/refund';

import { ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import {
  getRefundRejectReasonOptionsApi,
  rejectRefundApi,
} from '#/api/order/refund';

interface Props {
  open: boolean;
  refund: null | RefundApi.RefundRecord;
}

const props = defineProps<Props>();
const emit = defineEmits<{
  (e: 'success'): void;
  (e: 'update:open', value: boolean): void;
}>();

const submitting = ref(false);
const adminRemark = ref('');

const defaultCommonReasons = [
  '商品已签收，不符合退款条件',
  '买家申请理由不成立',
  '已超过售后期限',
  '需提供相关凭证后重新申请',
];
const commonReasons = ref<string[]>(defaultCommonReasons);

const loadCommonReasons = async () => {
  try {
    const options = await getRefundRejectReasonOptionsApi();
    const labels = (options || [])
      .map((item) => String(item.label || item.value || '').trim())
      .filter(Boolean);
    commonReasons.value = labels.length > 0 ? labels : defaultCommonReasons;
  } catch (error: any) {
    console.error('加载售后驳回原因失败：', error?.message || error);
    commonReasons.value = defaultCommonReasons;
  }
};

watch(
  () => props.open,
  (val) => {
    if (val) {
      adminRemark.value = '';
      loadCommonReasons();
    }
  },
);

const selectReason = (reason: string) => {
  adminRemark.value = reason;
};

const handleCancel = () => {
  if (submitting.value) return;
  emit('update:open', false);
};

const handleSubmit = async () => {
  if (!props.refund) return;
  const remark = adminRemark.value.trim();
  if (!remark) {
    message.warning('请填写驳回原因');
    return;
  }
  if (remark.length > 200) {
    message.warning('驳回原因不超过 200 字');
    return;
  }
  submitting.value = true;
  try {
    await rejectRefundApi(props.refund.id, { admin_remark: remark });
    message.success('已驳回');
    emit('success');
    emit('update:open', false);
  } catch (error: any) {
    message.error(error?.message || '驳回失败');
  } finally {
    submitting.value = false;
  }
};
</script>

<template>
  <a-modal
    :open="open"
    title="驳回售后申请"
    :mask-closable="false"
    :confirm-loading="submitting"
    ok-text="确认驳回"
    :ok-button-props="{ danger: true }"
    cancel-text="取消"
    @cancel="handleCancel"
    @ok="handleSubmit"
  >
    <template v-if="refund">
      <div class="mb-3 rounded bg-gray-50 p-3 text-xs dark:bg-gray-800">
        <div>售后单号：{{ refund.sn }}</div>
        <div>申请原因：{{ refund.reason_text || refund.reason || '—' }}</div>
        <div>
          退款金额：<strong>¥{{ refund.refund_amount }}</strong>
        </div>
      </div>

      <div class="mb-2 text-xs text-gray-500">快捷选择常用原因：</div>
      <div class="mb-3 flex flex-wrap gap-2">
        <a-tag
          v-for="reason in commonReasons"
          :key="reason"
          class="cursor-pointer"
          :color="adminRemark === reason ? 'blue' : undefined"
          @click="selectReason(reason)"
        >
          {{ reason }}
        </a-tag>
      </div>

      <a-form layout="vertical">
        <a-form-item label="驳回原因" required>
          <a-textarea
            v-model:value="adminRemark"
            placeholder="请填写驳回原因（必填，买家可见）"
            :max-length="200"
            show-count
            :rows="3"
          />
        </a-form-item>
      </a-form>
    </template>
  </a-modal>
</template>
