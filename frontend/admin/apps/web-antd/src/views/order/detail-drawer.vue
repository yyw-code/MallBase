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

const buyerDisplayName = (order: OrderApi.OrderDetail) =>
  order.buyer?.nickname || `用户 ${order.user_id}`;

const buyerInitial = (order: OrderApi.OrderDetail) =>
  buyerDisplayName(order).slice(0, 1) || '买';

const distributorDisplayName = (item: OrderApi.DistributionCommissionItem) =>
  item.distributor_user?.nickname || `用户 ${item.distributor_user_id}`;

const distributorContact = (item: OrderApi.DistributionCommissionItem) =>
  item.distributor_user?.mobile || item.distributor_user?.email || '—';

const relationLevelLabel = (level: number) => (level === 2 ? '二级' : '一级');

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

const commissionColumns = [
  { title: '层级', dataIndex: 'relation_level', width: 80 },
  {
    title: '分销员',
    dataIndex: 'distributor_user',
    key: 'distributor_user',
    width: 170,
  },
  { title: '计佣基数', dataIndex: 'base_amount', width: 100 },
  { title: '比例', dataIndex: 'rate', width: 80 },
  { title: '佣金', dataIndex: 'amount', width: 100 },
  { title: '已扣回', dataIndex: 'recovered_amount', width: 100 },
  { title: '状态', dataIndex: 'status_text', width: 100 },
  { title: '结算时间', dataIndex: 'release_time', width: 160 },
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
            <a-tag>
              {{ detail.status_text || statusLabel(detail.status) }}
            </a-tag>
            <a-tag
              v-if="detail.after_sale_tag_text"
              color="orange"
              class="ml-1"
            >
              售后：{{ detail.after_sale_tag_text }}
            </a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="买家信息" :span="2">
            <div class="order-detail-buyer">
              <a-avatar
                :size="40"
                :src="detail.buyer?.avatar_full_url || undefined"
              >
                {{ buyerInitial(detail) }}
              </a-avatar>
              <div class="order-detail-buyer-meta">
                <div class="order-detail-buyer-name">
                  {{ buyerDisplayName(detail) }}
                  <span class="order-detail-buyer-id">
                    ID {{ detail.user_id }}
                  </span>
                </div>
                <div class="order-detail-buyer-sub">
                  手机号：{{ detail.buyer?.mobile || '—' }} · 邮箱：{{
                    detail.buyer?.email || '—'
                  }}
                </div>
              </div>
            </div>
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
          <a-descriptions-item label="物流" :span="2">
            <template v-if="detail.delivery_type === 'virtual'">
              <a-tag>{{ detail.delivery_type_text || '虚拟发货' }}</a-tag>
              <span class="text-muted-foreground">
                {{ detail.delivery_note || '虚拟商品已发货' }}
              </span>
            </template>
            <template
              v-else-if="detail.logistics_company || detail.logistics_sn"
            >
              <a-tag>{{ detail.delivery_type_text || '实物快递' }}</a-tag>
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

        <template
          v-if="
            detail.points_deduction ||
            detail.points_reward ||
            detail.member_discount ||
            detail.member_growth
          "
        >
          <a-divider orientation="left" plain>积分 / 会员</a-divider>
          <a-descriptions
            :column="2"
            size="small"
            bordered
            class="mb-4"
            :label-style="{ width: '130px' }"
          >
            <a-descriptions-item
              v-if="detail.points_deduction"
              label="积分抵扣"
            >
              使用 {{ detail.points_deduction.used_points }} 积分，抵扣 ¥{{
                detail.points_deduction.discount_amount
              }}
              <span v-if="detail.points_deduction.returned_points > 0">
                ，已返还 {{ detail.points_deduction.returned_points }}
              </span>
            </a-descriptions-item>
            <a-descriptions-item v-if="detail.points_reward" label="积分赠送">
              应赠 {{ detail.points_reward.reward_points }}，冻结
              {{ detail.points_reward.frozen_points }}，已释放
              {{ detail.points_reward.released_points }}
            </a-descriptions-item>
            <a-descriptions-item v-if="detail.points_reward" label="解冻时间">
              {{ detail.points_reward.release_time || '—' }}
            </a-descriptions-item>
            <a-descriptions-item v-if="detail.member_discount" label="会员优惠">
              {{ detail.member_discount.level_name || '会员' }}，优惠 ¥{{
                detail.member_discount.discount_amount
              }}
            </a-descriptions-item>
            <a-descriptions-item v-if="detail.member_growth" label="成长值">
              +{{ detail.member_growth.change_growth }}，{{
                detail.member_growth.before_growth
              }}
              -> {{ detail.member_growth.after_growth }}
            </a-descriptions-item>
          </a-descriptions>
        </template>

        <template v-if="detail.distribution_commissions?.list?.length">
          <a-divider orientation="left" plain>分销佣金</a-divider>
          <a-descriptions
            :column="2"
            size="small"
            bordered
            class="mb-4"
            :label-style="{ width: '130px' }"
          >
            <a-descriptions-item label="佣金合计">
              ¥{{ detail.distribution_commissions.total_amount }}
            </a-descriptions-item>
            <a-descriptions-item label="计佣记录">
              {{ detail.distribution_commissions.list.length }} 条
            </a-descriptions-item>
          </a-descriptions>
          <a-table
            :columns="commissionColumns"
            :data-source="detail.distribution_commissions.list"
            :pagination="false"
            row-key="id"
            size="small"
            :scroll="{ x: 900 }"
            class="mb-4"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.dataIndex === 'relation_level'">
                {{ relationLevelLabel(record.relation_level) }}
              </template>
              <template v-else-if="column.key === 'distributor_user'">
                <div>
                  <div>{{ distributorDisplayName(record) }}</div>
                  <div class="text-xs text-gray-500">
                    {{ distributorContact(record) }}
                  </div>
                </div>
              </template>
              <template v-else-if="column.dataIndex === 'base_amount'">
                ¥{{ record.base_amount }}
              </template>
              <template v-else-if="column.dataIndex === 'rate'">
                {{ record.rate }}%
              </template>
              <template v-else-if="column.dataIndex === 'amount'">
                ¥{{ record.amount }}
              </template>
              <template v-else-if="column.dataIndex === 'recovered_amount'">
                ¥{{ record.recovered_amount }}
              </template>
              <template v-else-if="column.dataIndex === 'release_time'">
                {{ record.release_time || '—' }}
              </template>
            </template>
          </a-table>
        </template>

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

<style scoped>
.order-detail-buyer {
  display: flex;
  align-items: center;
  min-width: 0;
  gap: 10px;
}

.order-detail-buyer-meta {
  min-width: 0;
}

.order-detail-buyer-name {
  color: hsl(var(--foreground));
  font-weight: 500;
  line-height: 22px;
}

.order-detail-buyer-id {
  margin-left: 8px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
  font-weight: 400;
}

.order-detail-buyer-sub {
  color: hsl(var(--muted-foreground));
  font-size: 12px;
  line-height: 18px;
}
</style>
