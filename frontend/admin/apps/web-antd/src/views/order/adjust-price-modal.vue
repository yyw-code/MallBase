<script lang="ts" setup>
import type { OrderApi } from '#/api/order';

import { computed, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { adjustOrderPriceApi } from '#/api/order';

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

interface AdjustForm {
  freight_amount: number;
  discount_amount: number;
  reason: string;
}

const form = reactive<AdjustForm>({
  freight_amount: 0,
  discount_amount: 0,
  reason: '',
});

const rules = {
  freight_amount: [
    {
      required: true,
      type: 'number' as const,
      message: '请输入运费',
      trigger: 'blur' as const,
    },
    {
      validator: (_: unknown, value: number) =>
        value !== undefined && value !== null && value >= 0
          ? Promise.resolve()
          : Promise.reject('运费必须 ≥ 0'),
      trigger: 'blur' as const,
    },
  ],
  discount_amount: [
    {
      required: true,
      type: 'number' as const,
      message: '请输入优惠（无优惠请填 0）',
      trigger: 'blur' as const,
    },
  ],
  reason: [{ max: 255, message: '调整原因最多 255 个字符' }],
};

// 两位小数四舍五入，避免 0.1 + 0.2 浮点误差
const round2 = (value: number): number =>
  Math.round((Number(value) + Number.EPSILON) * 100) / 100;

const totalAmount = computed(() => Number(props.order?.total_amount ?? 0));

const previewPay = computed(() =>
  round2(totalAmount.value + (form.freight_amount ?? 0) - (form.discount_amount ?? 0)),
);

const previewInvalid = computed(() => previewPay.value <= 0);

// 打开时回填当前订单金额，避免首次打开全为 0 误导操作员
watch(
  () => props.open,
  (val) => {
    if (val && props.order) {
      form.freight_amount = Number(props.order.freight_amount ?? 0);
      form.discount_amount = Number(props.order.discount_amount ?? 0);
      form.reason = '';
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
  if (previewInvalid.value) {
    message.warning('改价后应付必须大于 0');
    return;
  }
  submitting.value = true;
  try {
    await adjustOrderPriceApi(props.order.id, {
      freight_amount: round2(form.freight_amount),
      discount_amount: round2(form.discount_amount),
      reason: form.reason.trim() || undefined,
    });
    message.success('改价成功');
    emit('success');
    emit('update:open', false);
  } catch (error: unknown) {
    const msg =
      error && typeof error === 'object' && 'message' in error
        ? String((error as { message?: unknown }).message ?? '')
        : '';
    message.error(msg || '改价失败');
  } finally {
    submitting.value = false;
  }
};
</script>

<template>
  <a-modal
    :open="open"
    title="订单改价"
    :mask-closable="false"
    :confirm-loading="submitting"
    :ok-button-props="{ disabled: previewInvalid }"
    ok-text="确认改价"
    cancel-text="取消"
    @cancel="handleCancel"
    @ok="handleSubmit"
  >
    <div
      v-if="order"
      class="mb-3 rounded bg-gray-50 p-3 text-xs dark:bg-gray-800"
    >
      <div>订单号：{{ order.sn }}</div>
      <div>当前应付：¥{{ order.pay_amount }}</div>
    </div>

    <a-form
      ref="formRef"
      :model="form"
      :rules="rules"
      :label-col="{ style: { width: '120px' } }"
      class="pt-4"
    >
      <a-form-item label="商品总额">
        <span class="text-sm">¥{{ totalAmount.toFixed(2) }}</span>
        <span class="ml-2 text-xs text-gray-400">（商品小计之和，不可改）</span>
      </a-form-item>

      <a-form-item label="运费" name="freight_amount">
        <a-input-number
          v-model:value="form.freight_amount"
          :min="0"
          :step="0.01"
          :precision="2"
          style="width: 200px"
          placeholder="0.00"
        />
      </a-form-item>

      <a-form-item label="优惠" name="discount_amount">
        <a-input-number
          v-model:value="form.discount_amount"
          :step="0.01"
          :precision="2"
          style="width: 200px"
          placeholder="0.00"
        />
        <span class="ml-2 text-xs text-gray-400">负数表示加价</span>
      </a-form-item>

      <a-form-item label="新应付金额">
        <span
          class="text-base font-semibold"
          :class="previewInvalid ? 'text-red-500' : 'text-emerald-600'"
        >
          ¥{{ previewPay.toFixed(2) }}
        </span>
        <span v-if="previewInvalid" class="ml-2 text-xs text-red-500">
          应付必须 &gt; 0
        </span>
        <span v-else class="ml-2 text-xs text-gray-400">
          = 商品总额 + 运费 - 优惠
        </span>
      </a-form-item>

      <a-form-item label="调整原因" name="reason">
        <a-textarea
          v-model:value="form.reason"
          placeholder="可选，最多 255 字"
          :maxlength="255"
          :auto-size="{ minRows: 2, maxRows: 4 }"
          show-count
        />
      </a-form-item>
    </a-form>
  </a-modal>
</template>
