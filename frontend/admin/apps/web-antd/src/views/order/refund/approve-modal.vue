<script lang="ts" setup>
import type { RefundApi } from '#/api/order/refund';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { approveRefundApi } from '#/api/order/refund';

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

const approveTip = computed(() => {
  const refund = props.refund;
  if (!refund) {
    return '';
  }
  if (refund.type === 1) {
    return '同意后将进入待买家退货，不会立即退款；买家提交退货物流后，后台确认收到退货再发起退款。';
  }
  if (
    refund.order?.status === 20 &&
    refund.receive_status === 0 &&
    refund.intercept_status === 'pending'
  ) {
    return '已发货未收到货的仅退款，需要先在后台确认物流拦截成功、已退回或物流异常，再同意退款。';
  }
  return '同意后将向微信发起退款；如微信返回处理中，售后单将进入退款中。';
});

watch(
  () => props.open,
  (val) => {
    if (val) {
      adminRemark.value = '';
    }
  },
);

const handleCancel = () => {
  if (submitting.value) return;
  emit('update:open', false);
};

const handleSubmit = async () => {
  if (!props.refund) return;
  submitting.value = true;
  try {
    await approveRefundApi(props.refund.id, {
      admin_remark: adminRemark.value.trim() || undefined,
    });
    message.success('审核通过');
    emit('success');
    emit('update:open', false);
  } catch (error: any) {
    message.error(error?.message || '审核失败');
  } finally {
    submitting.value = false;
  }
};
</script>

<template>
  <a-modal
    :open="open"
    title="同意售后申请"
    :mask-closable="false"
    :confirm-loading="submitting"
    ok-text="确认同意"
    cancel-text="取消"
    @cancel="handleCancel"
    @ok="handleSubmit"
  >
    <template v-if="refund">
      <div class="mb-3 rounded bg-gray-50 p-3 text-xs dark:bg-gray-800">
        <div>售后单号：{{ refund.sn }}</div>
        <div>订单号：{{ refund.order?.sn || '—' }}</div>
        <div class="mt-1 flex items-center gap-2">
          <a-avatar
            v-if="
              refund.order_item?.goods_image_full_url ||
              refund.order_item?.goods_image
            "
            shape="square"
            :size="32"
            :src="
              refund.order_item?.goods_image_full_url ||
              refund.order_item?.goods_image
            "
          />
          <span>{{ refund.order_item?.goods_name || '—' }}</span>
          <span v-if="refund.order_item?.sku_spec" class="text-gray-400">
            ({{ refund.order_item.sku_spec }})
          </span>
        </div>
        <div class="mt-1">
          退款数量：<strong>{{ refund.quantity }}</strong>
        </div>
        <div>
          退款金额：<strong class="text-red-500">
            ¥{{ refund.refund_amount }}
          </strong>
        </div>
      </div>

      <a-alert
        :message="approveTip"
        type="warning"
        show-icon
        class="mb-3"
      />

      <a-form layout="vertical">
        <a-form-item label="管理员备注（选填）">
          <a-textarea
            v-model:value="adminRemark"
            placeholder="如有备注可填写"
            :max-length="200"
            show-count
            :rows="3"
          />
        </a-form-item>
      </a-form>
    </template>
  </a-modal>
</template>
