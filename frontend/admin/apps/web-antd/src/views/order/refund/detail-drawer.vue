<script lang="ts" setup>
import type { RefundApi } from '#/api/order/refund';

import { ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { getRefundDetailApi } from '#/api/order/refund';

interface Props {
  open: boolean;
  refundId: null | number;
  statusMap: Record<number, string>;
  typeMap: Record<number, string>;
}

const props = defineProps<Props>();
const emit = defineEmits<{
  (e: 'update:open', value: boolean): void;
}>();

const loading = ref(false);
const detail = ref<null | RefundApi.RefundDetail>(null);

const loadDetail = async (id: number) => {
  loading.value = true;
  try {
    detail.value = await getRefundDetailApi(id);
  } catch (error: any) {
    message.error(error?.message || '加载售后详情失败');
    detail.value = null;
  } finally {
    loading.value = false;
  }
};

watch(
  () => [props.open, props.refundId] as const,
  ([open, id]) => {
    if (open && id) {
      loadDetail(id);
    } else if (!open) {
      detail.value = null;
    }
  },
);

const statusLabel = (status: null | number | undefined) => {
  if (status === null || status === undefined) return '—';
  return props.statusMap[status] ?? `#${status}`;
};
</script>

<template>
  <a-drawer
    :open="open"
    title="售后详情"
    :width="800"
    destroy-on-close
    @close="emit('update:open', false)"
  >
    <a-spin :spinning="loading">
      <template v-if="detail">
        <!-- 售后基本信息 -->
        <a-descriptions
          :column="2"
          size="small"
          bordered
          class="mb-4"
          :label-style="{ width: '110px' }"
        >
          <a-descriptions-item label="售后单号">
            {{ detail.sn }}
          </a-descriptions-item>
          <a-descriptions-item label="状态">
            <a-tag>{{ detail.status_text || statusLabel(detail.status) }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="类型">
            {{ detail.type_text || typeMap[detail.type] || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="收货状态">
            {{ detail.receive_status_text || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="退款金额">
            <strong class="text-red-500">¥{{ detail.refund_amount }}</strong>
          </a-descriptions-item>
          <a-descriptions-item label="退款数量">
            {{ detail.quantity }}
          </a-descriptions-item>
          <a-descriptions-item label="申请原因">
            {{ detail.reason_text || detail.reason || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="买家备注" :span="2">
            {{ detail.remark || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="申请时间">
            {{ detail.create_time }}
          </a-descriptions-item>
          <a-descriptions-item label="审核时间">
            {{ detail.reviewed_at || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="退款完成时间">
            {{ detail.refunded_at || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="取消时间">
            {{ detail.canceled_at || '—' }}
          </a-descriptions-item>
        </a-descriptions>

        <!-- 物流拦截信息 -->
        <a-descriptions
          v-if="detail.type === 0 && detail.receive_status === 0"
          :column="2"
          size="small"
          bordered
          class="mb-4"
          title="物流拦截"
          :label-style="{ width: '110px' }"
        >
          <a-descriptions-item label="拦截状态">
            {{ detail.intercept_status_text || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="拦截备注">
            {{ detail.intercept_note || '—' }}
          </a-descriptions-item>
        </a-descriptions>

        <!-- 退货信息 -->
        <a-descriptions
          v-if="detail.type === 1"
          :column="2"
          size="small"
          bordered
          class="mb-4"
          title="退货信息"
          :label-style="{ width: '110px' }"
        >
          <a-descriptions-item label="退货收件人">
            {{ detail.return_receiver_name || '—' }}
            <span v-if="detail.return_receiver_phone">
              · {{ detail.return_receiver_phone }}
            </span>
          </a-descriptions-item>
          <a-descriptions-item label="退货地址">
            {{ detail.return_receiver_address || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="物流公司">
            {{ detail.return_company || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="物流单号">
            {{ detail.return_tracking_no || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="买家寄出时间">
            {{ detail.return_shipped_at || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="商家收货时间">
            {{ detail.return_received_at || '—' }}
          </a-descriptions-item>
        </a-descriptions>

        <!-- 审核信息 -->
        <a-descriptions
          v-if="detail.reviewed_by || detail.admin_remark"
          :column="2"
          size="small"
          bordered
          class="mb-4"
          title="审核信息"
          :label-style="{ width: '110px' }"
        >
          <a-descriptions-item label="审核人">
            {{
              detail.reviewer?.nickname ||
              detail.reviewer?.username ||
              `#${detail.reviewed_by}`
            }}
          </a-descriptions-item>
          <a-descriptions-item label="审核备注">
            {{ detail.admin_remark || '—' }}
          </a-descriptions-item>
        </a-descriptions>

        <!-- 商品快照 -->
        <a-divider orientation="left" plain>商品快照</a-divider>
        <div
          v-if="detail.order_item"
          class="mb-4 flex items-center gap-3 rounded bg-gray-50 p-3 dark:bg-gray-800"
        >
          <a-avatar
            v-if="
              detail.order_item.goods_image_full_url ||
              detail.order_item.goods_image
            "
            shape="square"
            :size="48"
            :src="
              detail.order_item.goods_image_full_url ||
              detail.order_item.goods_image
            "
          />
          <div>
            <div class="font-medium">
              {{ detail.order_item.goods_name }}
            </div>
            <div v-if="detail.order_item.sku_spec" class="text-xs text-gray-500">
              规格：{{ detail.order_item.sku_spec }}
            </div>
            <div class="text-xs text-gray-500">
              单价：¥{{ detail.order_item.unit_price }} / 数量：{{
                detail.order_item.quantity
              }}
            </div>
          </div>
        </div>
        <a-empty v-else description="无商品快照" />

        <!-- 关联主订单 -->
        <a-divider orientation="left" plain>关联订单</a-divider>
        <a-descriptions
          v-if="detail.order"
          :column="2"
          size="small"
          bordered
          class="mb-4"
          :label-style="{ width: '110px' }"
        >
          <a-descriptions-item label="订单号">
            {{ detail.order.sn }}
          </a-descriptions-item>
          <a-descriptions-item label="订单状态">
            <a-tag>{{ detail.order.status_text || '—' }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="应付金额">
            ¥{{ detail.order.pay_amount || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="下单时间">
            {{ detail.order.create_time || '—' }}
          </a-descriptions-item>
          <a-descriptions-item label="收件人" :span="2">
            {{ detail.order.receiver_name || '—' }}
            <span v-if="detail.order.receiver_phone">
              · {{ detail.order.receiver_phone }}
            </span>
            <div v-if="detail.order.receiver_province" class="text-gray-500">
              {{ detail.order.receiver_province
              }}{{ detail.order.receiver_city
              }}{{ detail.order.receiver_district
              }}{{ detail.order.receiver_address }}
            </div>
          </a-descriptions-item>
        </a-descriptions>
        <a-empty v-else description="无关联订单" />

        <!-- 买家信息 -->
        <a-divider orientation="left" plain>买家信息</a-divider>
        <div
          v-if="detail.user"
          class="mb-4 flex items-center gap-3"
        >
          <a-avatar
            v-if="detail.user.avatar_url || detail.user.avatar"
            :src="detail.user.avatar_url || detail.user.avatar"
            :size="36"
          />
          <div class="text-sm">
            <div>{{ detail.user.nickname || '—' }}</div>
            <div class="text-gray-500">
              {{ detail.user.phone || '—' }}
            </div>
          </div>
        </div>
        <a-empty v-else description="无买家信息" />

        <!-- 时间线 -->
        <a-divider orientation="left" plain class="mt-6">状态时间线</a-divider>
        <a-timeline>
          <a-timeline-item color="green">
            <div class="text-sm">
              <span class="mr-2 text-gray-500">{{ detail.create_time }}</span>
              <strong>买家申请售后</strong>
            </div>
            <div v-if="detail.reason_text" class="text-xs text-gray-500">
              原因：{{ detail.reason_text }}
            </div>
          </a-timeline-item>
          <a-timeline-item
            v-if="detail.reviewed_at"
            :color="detail.status === 10 ? 'green' : 'red'"
          >
            <div class="text-sm">
              <span class="mr-2 text-gray-500">{{ detail.reviewed_at }}</span>
              <strong>{{ detail.status === 10 ? '管理员同意' : '管理员驳回' }}</strong>
            </div>
            <div v-if="detail.admin_remark" class="text-xs text-gray-500">
              备注：{{ detail.admin_remark }}
            </div>
          </a-timeline-item>
          <a-timeline-item v-if="detail.refunded_at" color="green">
            <div class="text-sm">
              <span class="mr-2 text-gray-500">{{ detail.refunded_at }}</span>
              <strong>退款完成</strong>
            </div>
          </a-timeline-item>
          <a-timeline-item v-if="detail.canceled_at" color="gray">
            <div class="text-sm">
              <span class="mr-2 text-gray-500">{{ detail.canceled_at }}</span>
              <strong>买家取消申请</strong>
            </div>
          </a-timeline-item>
        </a-timeline>
      </template>
    </a-spin>
  </a-drawer>
</template>
