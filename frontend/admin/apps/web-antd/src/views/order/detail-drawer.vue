<script lang="ts" setup>
import type { OrderApi } from '#/api/order';

import { ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { getOrderDetailApi } from '#/api/order';

interface Props {
  open: boolean;
  orderId: null | number;
  statusMap: Record<number, string>;
  payMethodMap: Record<number, string>;
}

const props = defineProps<Props>();
const emit = defineEmits<{
  (e: 'update:open', value: boolean): void;
}>();

const loading = ref(false);
const detail = ref<null | OrderApi.OrderDetail>(null);

const loadDetail = async (id: number) => {
  loading.value = true;
  try {
    detail.value = await getOrderDetailApi(id);
  } catch (error: any) {
    message.error(error?.message || '加载订单详情失败');
    detail.value = null;
  } finally {
    loading.value = false;
  }
};

watch(
  () => [props.open, props.orderId] as const,
  ([open, id]) => {
    if (open && id) {
      loadDetail(id);
    } else if (!open) {
      detail.value = null;
    }
  },
);

const operatorLabel = (type: number) => {
  switch (type) {
    case 0: {
      return '系统';
    }
    case 1: {
      return '买家';
    }
    case 2: {
      return '管理员';
    }
    default: {
      return `未知(${type})`;
    }
  }
};

const statusLabel = (status: null | number) => {
  if (status === null || status === undefined) return '—';
  return props.statusMap[status] ?? `#${status}`;
};

const payMethodLabel = (method?: null | number) => {
  if (method === null || method === undefined) return '—';
  return props.payMethodMap[method] ?? `#${method}`;
};

const itemColumns = [
  { title: '商品', dataIndex: 'goods_name', ellipsis: true },
  { title: '规格', dataIndex: 'sku_spec', width: 140, ellipsis: true },
  { title: '单价', dataIndex: 'unit_price', width: 90 },
  { title: '数量', dataIndex: 'quantity', width: 70 },
  { title: '小计', dataIndex: 'subtotal', width: 100 },
  { title: '优惠', dataIndex: 'discount_amount', width: 100 },
  { title: '实付', dataIndex: 'pay_amount', width: 100 },
  { title: '已发货', dataIndex: 'shipped_quantity', width: 80 },
  { title: '已退款', dataIndex: 'refunded_quantity', width: 80 },
  { title: '已退货', dataIndex: 'returned_quantity', width: 80 },
];
</script>

<template>
  <a-drawer
    :open="open"
    title="订单详情"
    :width="900"
    destroy-on-close
    @close="emit('update:open', false)"
  >
    <a-spin :spinning="loading">
      <template v-if="detail">
        <a-descriptions
          :column="2"
          size="small"
          bordered
          class="mb-4"
          :label-style="{ width: '120px' }"
        >
          <a-descriptions-item label="订单号">
            {{ detail.sn }}
          </a-descriptions-item>
          <a-descriptions-item label="状态">
            <a-tag>{{
              detail.status_text || statusLabel(detail.status)
            }}</a-tag>
            <a-tag
              v-if="detail.after_sale_tag_text"
              color="orange"
              class="ml-1"
            >
              售后：{{ detail.after_sale_tag_text }}
            </a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="买家 ID">
            {{ detail.user_id }}
          </a-descriptions-item>
          <a-descriptions-item label="支付方式">
            {{ detail.pay_method_text || payMethodLabel(detail.pay_method) }}
          </a-descriptions-item>
          <a-descriptions-item label="商品总额">
            ¥{{ detail.total_amount }}
          </a-descriptions-item>
          <a-descriptions-item label="运费">
            ¥{{ detail.freight_amount }}
          </a-descriptions-item>
          <a-descriptions-item label="优惠">
            ¥{{ detail.discount_amount }}
          </a-descriptions-item>
          <a-descriptions-item label="应付">
            <strong>¥{{ detail.pay_amount }}</strong>
          </a-descriptions-item>
          <a-descriptions-item label="交易号">
            {{ detail.trade_no || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="物流">
            <template v-if="detail.logistics_company || detail.logistics_sn">
              {{ detail.logistics_company || '—' }} /
              {{ detail.logistics_sn || '—' }}
            </template>
            <template v-else>—</template>
          </a-descriptions-item>
          <a-descriptions-item label="收件人" :span="2">
            {{ detail.receiver_name || '—' }}
            <span v-if="detail.receiver_phone">
              · {{ detail.receiver_phone }}
            </span>
            <div v-if="detail.receiver_province" class="text-gray-500">
              {{ detail.receiver_province }}{{ detail.receiver_city
              }}{{ detail.receiver_district }}{{ detail.receiver_address }}
            </div>
          </a-descriptions-item>
          <a-descriptions-item label="买家备注" :span="2">
            {{ detail.buyer_remark || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="管理员备注" :span="2">
            {{ detail.admin_remark || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="下单时间">
            {{ detail.create_time }}
          </a-descriptions-item>
          <a-descriptions-item label="支付时间">
            {{ detail.paid_at || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="发货时间">
            {{ detail.shipped_at || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="收货时间">
            {{ detail.received_at || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="完成时间">
            {{ detail.completed_at || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="关闭时间">
            {{ detail.closed_at || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="超时时间" :span="2">
            {{ detail.expire_at || '—' }}
          </a-descriptions-item>
        </a-descriptions>

        <a-divider orientation="left" plain>订单商品</a-divider>
        <a-table
          :columns="itemColumns"
          :data-source="detail.items || []"
          :pagination="false"
          size="small"
          row-key="id"
          :scroll="{ x: 1100 }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.dataIndex === 'goods_name'">
              <div class="flex items-center gap-2">
                <a-avatar
                  v-if="record.goods_image_full_url || record.goods_image"
                  shape="square"
                  :size="36"
                  :src="record.goods_image_full_url || record.goods_image"
                />
                <span>{{ record.goods_name }}</span>
              </div>
            </template>
          </template>
        </a-table>

        <a-divider orientation="left" plain class="mt-6">
          状态流转时间轴
        </a-divider>
        <a-timeline v-if="detail.logs && detail.logs.length > 0">
          <a-timeline-item
            v-for="log in detail.logs"
            :key="log.id"
            :color="log.to_status === 90 ? 'red' : 'blue'"
          >
            <div class="text-sm">
              <span class="mr-2 text-gray-500">{{ log.create_time }}</span>
              <a-tag>{{ operatorLabel(log.operator_type) }}</a-tag>
              <span class="mx-1">
                {{ statusLabel(log.from_status) }} →
                <strong>{{ statusLabel(log.to_status) }}</strong>
              </span>
            </div>
            <div v-if="log.remark" class="text-xs text-gray-500">
              {{ log.remark }}
            </div>
          </a-timeline-item>
        </a-timeline>
        <a-empty v-else description="暂无日志" />

        <a-divider orientation="left" plain class="mt-6">售后工单</a-divider>
        <a-alert
          message="售后工单请前往「售后订单」页面查看和管理"
          type="info"
          show-icon
        />
      </template>
    </a-spin>
  </a-drawer>
</template>
