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

type AdjustMode = OrderApi.AdjustMode;

interface AdjustItemForm {
  discount_amount: number;
  goods_name: string;
  order_item_id: number;
  pay_amount: string;
  sku_spec?: string;
  subtotal: string;
  subtotal_cents: number;
}

interface AdjustForm {
  adjust_mode: AdjustMode;
  freight_amount: number;
  items: AdjustItemForm[];
  pay_percent: number;
  reason: string;
}

const props = defineProps<Props>();
const emit = defineEmits<{
  (e: 'update:open', value: boolean): void;
  (e: 'success'): void;
}>();

const formRef = ref();
const submitting = ref(false);

const form = reactive<AdjustForm>({
  adjust_mode: 'item_discount',
  freight_amount: 0,
  items: [],
  pay_percent: 100,
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
  pay_percent: [
    {
      validator: (_: unknown, value: number) =>
        form.adjust_mode !== 'pay_percent' ||
        (value !== undefined && value !== null && value >= 0 && value <= 100)
          ? Promise.resolve()
          : Promise.reject('整单实付比例必须在 0-100 之间'),
      trigger: 'blur' as const,
    },
  ],
  reason: [{ max: 255, message: '调整原因最多 255 个字符' }],
};

const modeOptions = [
  { label: '商品优惠', value: 'item_discount' },
  { label: '整单折扣', value: 'pay_percent' },
];

const itemColumns = [
  { title: '商品', dataIndex: 'goods_name', ellipsis: true },
  { title: '规格', dataIndex: 'sku_spec', width: 120, ellipsis: true },
  { title: '小计', dataIndex: 'subtotal', width: 100 },
  { title: '优惠', dataIndex: 'discount_amount', width: 150 },
  { title: '实付', dataIndex: 'pay_amount', width: 100 },
];

const moneyToCents = (value: number | string | undefined): number => {
  const raw = String(value ?? '0').trim();
  if (!/^\d+(\.\d+)?$/.test(raw)) return 0;
  const [integer, decimal = ''] = raw.split('.');
  return Number(integer) * 100 + Number(decimal.slice(0, 2).padEnd(2, '0'));
};

const centsToMoney = (cents: number): string => {
  const safe = Math.max(0, Math.trunc(cents));
  return `${Math.trunc(safe / 100)}.${String(safe % 100).padStart(2, '0')}`;
};

const centsToNumber = (cents: number): number => Number(centsToMoney(cents));

const percentToBasisPoints = (value: number): number => {
  const raw = String(value ?? 0);
  if (!/^\d+(\.\d+)?$/.test(raw)) return 0;
  const [integer, decimal = ''] = raw.split('.');
  return Number(integer) * 100 + Number(decimal.slice(0, 2).padEnd(2, '0'));
};

const totalCents = computed(() =>
  form.items.reduce((sum, item) => sum + item.subtotal_cents, 0),
);

const itemDiscountInvalid = computed(() =>
  form.items.some((item) => {
    const discountCents = moneyToCents(item.discount_amount);
    return discountCents > item.subtotal_cents;
  }),
);

const percentPreviewItems = computed(() => {
  const basisPoints = Math.min(
    10000,
    Math.max(0, percentToBasisPoints(form.pay_percent)),
  );
  const targetPayCents = Math.trunc((totalCents.value * basisPoints) / 10000);
  const rows = form.items.map((item) => {
    const numerator = item.subtotal_cents * basisPoints;
    return {
      order_item_id: item.order_item_id,
      pay_cents: Math.trunc(numerator / 10000),
      remainder: numerator % 10000,
      subtotal_cents: item.subtotal_cents,
    };
  });

  let allocated = rows.reduce((sum, row) => sum + row.pay_cents, 0);
  [...rows]
    .sort((left, right) => {
      const byRemainder = right.remainder - left.remainder;
      return byRemainder === 0
        ? left.order_item_id - right.order_item_id
        : byRemainder;
    })
    .some((row) => {
      if (allocated >= targetPayCents) return true;
      if (row.pay_cents >= row.subtotal_cents) return false;
      row.pay_cents += 1;
      allocated += 1;
      return false;
    });

  return rows.map((row) => ({
    order_item_id: row.order_item_id,
    discount_cents: row.subtotal_cents - row.pay_cents,
    pay_cents: row.pay_cents,
  }));
});

const previewItemMap = computed(() => {
  const map = new Map<number, { discount_cents: number; pay_cents: number }>();
  if (form.adjust_mode === 'pay_percent') {
    for (const item of percentPreviewItems.value) {
      map.set(item.order_item_id, item);
    }
    return map;
  }

  for (const item of form.items) {
    const discountCents = Math.min(
      moneyToCents(item.discount_amount),
      item.subtotal_cents,
    );
    map.set(item.order_item_id, {
      discount_cents: discountCents,
      pay_cents: item.subtotal_cents - discountCents,
    });
  }
  return map;
});

const goodsDiscountCents = computed(() =>
  [...previewItemMap.value.values()].reduce(
    (sum, item) => sum + item.discount_cents,
    0,
  ),
);

const goodsPayCents = computed(() =>
  [...previewItemMap.value.values()].reduce(
    (sum, item) => sum + item.pay_cents,
    0,
  ),
);

const previewPayCents = computed(
  () => goodsPayCents.value + moneyToCents(form.freight_amount),
);

const previewInvalid = computed(
  () =>
    previewPayCents.value <= 0 ||
    itemDiscountInvalid.value ||
    form.items.length === 0,
);

const currentGoodsPayPercent = (items: OrderApi.OrderItem[]): number => {
  const total = items.reduce(
    (sum, item) => sum + moneyToCents(item.subtotal),
    0,
  );
  if (total <= 0) return 100;
  const paid = items.reduce(
    (sum, item) => sum + moneyToCents(item.pay_amount),
    0,
  );
  return centsToNumber(Math.trunc((paid * 10000) / total));
};

const resetForm = () => {
  const items = props.order?.items ?? [];
  form.freight_amount = centsToNumber(
    moneyToCents(props.order?.freight_amount ?? '0.00'),
  );
  form.adjust_mode = 'item_discount';
  form.pay_percent = currentGoodsPayPercent(items);
  form.items = items.map((item) => ({
    discount_amount: centsToNumber(
      moneyToCents(item.discount_amount ?? '0.00'),
    ),
    goods_name: item.goods_name,
    order_item_id: item.id,
    pay_amount: item.pay_amount,
    sku_spec: item.sku_spec,
    subtotal: item.subtotal,
    subtotal_cents: moneyToCents(item.subtotal),
  }));
  form.reason = '';
};

// 打开时回填当前订单金额，避免首次打开全为 0 误导操作员
watch(
  () => props.open,
  (val) => {
    if (val && props.order) {
      resetForm();
    }
  },
);

const previewOf = (item: AdjustItemForm) =>
  previewItemMap.value.get(item.order_item_id) ?? {
    discount_cents: 0,
    pay_cents: item.subtotal_cents,
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
  if (previewInvalid.value) {
    message.warning('改价后应付必须大于 0，且商品优惠不能超过小计');
    return;
  }
  submitting.value = true;
  try {
    await adjustOrderPriceApi(props.order.id, {
      freight_amount: centsToMoney(moneyToCents(form.freight_amount)),
      adjust_mode: form.adjust_mode,
      items:
        form.adjust_mode === 'item_discount'
          ? form.items.map((item) => ({
              order_item_id: item.order_item_id,
              discount_amount: centsToMoney(moneyToCents(item.discount_amount)),
            }))
          : undefined,
      pay_percent:
        form.adjust_mode === 'pay_percent'
          ? centsToMoney(percentToBasisPoints(form.pay_percent))
          : undefined,
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
    :width="820"
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
      :label-col="{ style: { width: '100px' } }"
      class="pt-4"
    >
      <a-form-item label="商品总额">
        <span class="text-sm">¥{{ centsToMoney(totalCents) }}</span>
      </a-form-item>

      <a-form-item label="运费" name="freight_amount">
        <a-input-number
          v-model:value="form.freight_amount"
          :min="0"
          :step="0.01"
          :precision="2"
          style="width: 180px"
          placeholder="0.00"
        />
      </a-form-item>

      <a-form-item label="改价方式" name="adjust_mode">
        <a-segmented v-model:value="form.adjust_mode" :options="modeOptions" />
      </a-form-item>

      <a-form-item
        v-if="form.adjust_mode === 'pay_percent'"
        label="实付比例"
        name="pay_percent"
      >
        <a-input-number
          v-model:value="form.pay_percent"
          :min="0"
          :max="100"
          :step="0.01"
          :precision="2"
          style="width: 180px"
          placeholder="100.00"
        >
          <template #addonAfter>%</template>
        </a-input-number>
      </a-form-item>

      <a-form-item label="商品明细">
        <a-table
          :columns="itemColumns"
          :data-source="form.items"
          :pagination="false"
          size="small"
          row-key="order_item_id"
          :scroll="{ x: 760 }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.dataIndex === 'goods_name'">
              <span>{{ record.goods_name }}</span>
            </template>
            <template v-else-if="column.dataIndex === 'subtotal'">
              ¥{{ record.subtotal }}
            </template>
            <template v-else-if="column.dataIndex === 'discount_amount'">
              <a-input-number
                v-if="form.adjust_mode === 'item_discount'"
                v-model:value="record.discount_amount"
                :min="0"
                :max="Number(record.subtotal)"
                :step="0.01"
                :precision="2"
                size="small"
                style="width: 120px"
              />
              <span v-else
                >¥{{ centsToMoney(previewOf(record).discount_cents) }}</span
              >
            </template>
            <template v-else-if="column.dataIndex === 'pay_amount'">
              ¥{{ centsToMoney(previewOf(record).pay_cents) }}
            </template>
          </template>
        </a-table>
      </a-form-item>

      <a-form-item label="新应付金额">
        <span
          class="text-base font-semibold"
          :class="previewInvalid ? 'text-red-500' : 'text-emerald-600'"
        >
          ¥{{ centsToMoney(previewPayCents) }}
        </span>
        <span class="ml-2 text-xs text-gray-400">
          商品实付 ¥{{ centsToMoney(goodsPayCents) }} / 优惠 ¥{{
            centsToMoney(goodsDiscountCents)
          }}
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
