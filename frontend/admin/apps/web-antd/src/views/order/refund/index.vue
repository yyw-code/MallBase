<script lang="ts" setup>
import type { RefundApi } from '#/api/order/refund';

import { computed, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Modal } from 'ant-design-vue';

import {
  confirmRefundReturnApi,
  getRefundListApi,
  getRefundStatusOptionsApi,
  updateRefundInterceptApi,
} from '#/api/order/refund';
import { useTableCrud } from '#/composables/useTableCrud';

import ApproveModal from './approve-modal.vue';
import DetailDrawer from './detail-drawer.vue';
import RejectModal from './reject-modal.vue';

defineOptions({ name: 'RefundOrderManagement' });

const { hasAccessByCodes } = useAccess();

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData } = useTableCrud<
  RefundApi.RefundRecord,
  RefundApi.ListParams
>(
  {
    list: getRefundListApi,
  },
  { immediateLoad: false },
);

/* ---------------- 搜索参数 ---------------- */
interface SearchForm {
  sn: string;
  order_sn: string;
  status: null | number;
  type: null | number;
  user_phone: string;
  created_range: [string, string] | undefined;
  reviewed_range: [string, string] | undefined;
}

const searchParams = ref<SearchForm>({
  sn: '',
  order_sn: '',
  status: null,
  type: null,
  user_phone: '',
  created_range: undefined,
  reviewed_range: undefined,
});

const buildQuery = (): RefundApi.ListParams => {
  const cr = searchParams.value.created_range;
  const rr = searchParams.value.reviewed_range;
  return {
    sn: searchParams.value.sn?.trim() || undefined,
    order_sn: searchParams.value.order_sn?.trim() || undefined,
    status: searchParams.value.status ?? undefined,
    type: searchParams.value.type ?? undefined,
    user_phone: searchParams.value.user_phone?.trim() || undefined,
    created_start: cr?.[0] || undefined,
    created_end: cr?.[1] || undefined,
    reviewed_start: rr?.[0] || undefined,
    reviewed_end: rr?.[1] || undefined,
  };
};

const resetSearch = () => {
  searchParams.value = {
    sn: '',
    order_sn: '',
    status: null,
    type: null,
    user_phone: '',
    created_range: undefined,
    reviewed_range: undefined,
  };
  pagination.current = 1;
  loadData(buildQuery());
};

const submitSearch = () => {
  pagination.current = 1;
  loadData(buildQuery());
};

const handleTableChange = (newPagination: {
  current?: number;
  pageSize?: number;
}) => {
  pagination.current = newPagination.current ?? pagination.current;
  pagination.pageSize = newPagination.pageSize ?? pagination.pageSize;
  loadData(buildQuery());
};

/* ---------------- 枚举选项 ---------------- */
const statusOptions = ref<RefundApi.EnumOption[]>([]);
const typeOptions = ref<RefundApi.EnumOption[]>([]);

const statusMap = computed<Record<number, string>>(() =>
  Object.fromEntries(
    statusOptions.value.map((o) => [Number(o.value), o.label]),
  ),
);
const typeMap = computed<Record<number, string>>(() =>
  Object.fromEntries(typeOptions.value.map((o) => [Number(o.value), o.label])),
);

const loadStatusOptions = async () => {
  try {
    const res = await getRefundStatusOptionsApi();
    statusOptions.value = res?.status ?? [];
    typeOptions.value = res?.type ?? [];
  } catch (error: any) {
    console.error('加载售后枚举失败：', error?.message || error);
  }
};

/* ---------------- 详情抽屉 ---------------- */
const drawerOpen = ref(false);
const activeRefundId = ref<null | number>(null);

const openDetail = (record: RefundApi.RefundRecord) => {
  activeRefundId.value = record.id;
  drawerOpen.value = true;
};

/* ---------------- 审核弹窗 ---------------- */
const approveModalOpen = ref(false);
const rejectModalOpen = ref(false);
const activeRefund = ref<null | RefundApi.RefundRecord>(null);

const openApprove = (record: RefundApi.RefundRecord) => {
  if (record.status !== 0) {
    message.warning('仅待处理状态可审核');
    return;
  }
  activeRefund.value = record;
  approveModalOpen.value = true;
};

const openReject = (record: RefundApi.RefundRecord) => {
  if (record.status !== 0) {
    message.warning('仅待处理状态可审核');
    return;
  }
  activeRefund.value = record;
  rejectModalOpen.value = true;
};

const canApprove = (record: RefundApi.RefundRecord) => {
  if (record.status !== 0) {
    return false;
  }
  if (
    record.type === 0 &&
    record.receive_status === 0 &&
    record.order?.status === 20
  ) {
    return ['exception', 'returned', 'success'].includes(
      record.intercept_status || '',
    );
  }
  return true;
};

const isUnreceivedRefundOnly = (record: RefundApi.RefundRecord) =>
  record.status === 0 && record.type === 0 && record.receive_status === 0;

const isFinalInterceptStatus = (status?: string) =>
  ['exception', 'returned', 'success'].includes(status || '');

const canUpdateIntercept = (record: RefundApi.RefundRecord) =>
  isUnreceivedRefundOnly(record) &&
  !isFinalInterceptStatus(record.intercept_status);

const canMarkIntercept = (record: RefundApi.RefundRecord, status: string) =>
  canUpdateIntercept(record) && record.intercept_status !== status;

const onReviewSuccess = async () => {
  await loadData(buildQuery());
};

const updateIntercept = async (
  record: RefundApi.RefundRecord,
  status: string,
  note: string,
) => {
  await updateRefundInterceptApi(record.id, {
    intercept_status: status,
    intercept_note: note,
  });
  message.success('物流拦截状态已更新');
  await loadData(buildQuery());
};

const confirmReturn = (record: RefundApi.RefundRecord) => {
  Modal.confirm({
    title: '确认收到退货？',
    content: '确认后将发起退款，请确认退回商品已验收无误。',
    okText: '确认收货并退款',
    cancelText: '取消',
    async onOk() {
      await confirmRefundReturnApi(record.id, {
        admin_remark: '确认收到买家退货',
      });
      message.success('已确认收货并发起退款');
      await loadData(buildQuery());
    },
  });
};

/* ---------------- 表格列 ---------------- */
const columns = [
  { title: 'ID', dataIndex: 'id', width: 70, fixed: 'left' },
  { title: '售后单号', dataIndex: 'sn', width: 180, fixed: 'left' },
  {
    title: '订单号',
    key: 'order_sn',
    width: 180,
    customRender: ({ record }: { record: RefundApi.RefundRecord }) =>
      record.order?.sn || '—',
  },
  {
    title: '买家',
    key: 'user',
    width: 130,
    customRender: ({ record }: { record: RefundApi.RefundRecord }) => {
      const u = record.user;
      if (!u) return '—';
      return u.phone ? `${u.nickname || ''} ${u.phone}` : u.nickname || '—';
    },
  },
  {
    title: '商品',
    key: 'goods',
    width: 180,
    ellipsis: true,
  },
  { title: '数量', dataIndex: 'quantity', width: 70 },
  {
    title: '退款金额',
    dataIndex: 'refund_amount',
    width: 100,
    customRender: ({ text }: { text: string }) => `¥${text}`,
  },
  {
    title: '类型',
    dataIndex: 'type',
    width: 100,
    customRender: ({ record }: { record: RefundApi.RefundRecord }) =>
      record.type_text || typeMap.value[record.type] || '—',
  },
  {
    title: '收货状态',
    dataIndex: 'receive_status',
    width: 100,
    customRender: ({ record }: { record: RefundApi.RefundRecord }) =>
      record.receive_status_text || '—',
  },
  {
    title: '拦截状态',
    dataIndex: 'intercept_status',
    width: 120,
    customRender: ({ record }: { record: RefundApi.RefundRecord }) =>
      record.intercept_status_text || '—',
  },
  {
    title: '状态',
    dataIndex: 'status',
    width: 100,
    customRender: ({ record }: { record: RefundApi.RefundRecord }) =>
      record.status_text || statusMap.value[record.status] || '—',
  },
  { title: '申请时间', dataIndex: 'create_time', width: 170 },
  { title: '操作', key: 'action', fixed: 'right', width: 260 },
];

onMounted(() => {
  loadData(buildQuery());
  loadStatusOptions();
});
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button @click="() => loadData(buildQuery())">刷新</a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4">
      <a-form-item label="售后单号">
        <a-input
          v-model:value="searchParams.sn"
          placeholder="售后单号（支持模糊）"
          allow-clear
          style="width: 180px"
          @press-enter="submitSearch"
        />
      </a-form-item>
      <a-form-item label="订单号">
        <a-input
          v-model:value="searchParams.order_sn"
          placeholder="订单号（支持模糊）"
          allow-clear
          style="width: 180px"
          @press-enter="submitSearch"
        />
      </a-form-item>
      <a-form-item label="状态">
        <a-select
          v-model:value="searchParams.status"
          placeholder="请选择"
          allow-clear
          style="width: 130px"
          :options="
            statusOptions.map((o) => ({
              label: o.label,
              value: Number(o.value),
            }))
          "
        />
      </a-form-item>
      <a-form-item label="类型">
        <a-select
          v-model:value="searchParams.type"
          placeholder="请选择"
          allow-clear
          style="width: 130px"
          :options="
            typeOptions.map((o) => ({
              label: o.label,
              value: Number(o.value),
            }))
          "
        />
      </a-form-item>
      <a-form-item label="买家手机">
        <a-input
          v-model:value="searchParams.user_phone"
          placeholder="手机号"
          allow-clear
          style="width: 140px"
          @press-enter="submitSearch"
        />
      </a-form-item>
      <a-form-item label="申请时间">
        <a-range-picker
          v-model:value="searchParams.created_range"
          show-time
          value-format="YYYY-MM-DD HH:mm:ss"
          style="width: 360px"
        />
      </a-form-item>
      <a-form-item label="审核时间">
        <a-range-picker
          v-model:value="searchParams.reviewed_range"
          show-time
          value-format="YYYY-MM-DD HH:mm:ss"
          style="width: 360px"
        />
      </a-form-item>
      <a-form-item>
        <a-button type="primary" @click="submitSearch">搜索</a-button>
        <a-button class="ml-2" @click="resetSearch">重置</a-button>
      </a-form-item>
    </a-form>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1600 }"
      row-key="id"
      @change="handleTableChange"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'goods'">
          <div class="flex items-center gap-2">
            <a-avatar
              v-if="
                record.order_item?.goods_image_full_url ||
                record.order_item?.goods_image
              "
              shape="square"
              :size="32"
              :src="
                record.order_item?.goods_image_full_url ||
                record.order_item?.goods_image
              "
            />
            <span class="truncate">
              {{ record.order_item?.goods_name || '—' }}
            </span>
          </div>
        </template>
        <template v-if="column.key === 'action'">
          <div class="refund-actions">
            <a-button
              type="link"
              size="small"
              v-access:code="'SystemRefundOrderDetail'"
              @click="openDetail(record)"
            >
              详情
            </a-button>
            <a-button
              v-if="canApprove(record)"
              type="link"
              size="small"
              v-access:code="'SystemRefundOrderApprove'"
              @click="openApprove(record)"
            >
              同意
            </a-button>
            <a-button
              v-if="record.status === 0"
              type="link"
              danger
              size="small"
              v-access:code="'SystemRefundOrderReject'"
              @click="openReject(record)"
            >
              驳回
            </a-button>
            <a-button
              v-if="canMarkIntercept(record, 'success')"
              type="link"
              size="small"
              v-access:code="'SystemRefundOrderUpdateIntercept'"
              @click="
                updateIntercept(record, 'success', '人工确认物流拦截成功')
              "
            >
              拦截成功
            </a-button>
            <a-button
              v-if="canMarkIntercept(record, 'returned')"
              type="link"
              size="small"
              v-access:code="'SystemRefundOrderUpdateIntercept'"
              @click="updateIntercept(record, 'returned', '人工确认包裹已退回')"
            >
              已退回
            </a-button>
            <a-button
              v-if="canMarkIntercept(record, 'exception')"
              type="link"
              size="small"
              v-access:code="'SystemRefundOrderUpdateIntercept'"
              @click="
                updateIntercept(record, 'exception', '人工确认物流异常/丢件')
              "
            >
              物流异常
            </a-button>
            <a-button
              v-if="
                record.status === 1 &&
                record.type === 1 &&
                record.return_tracking_no
              "
              type="link"
              size="small"
              v-access:code="'SystemRefundOrderConfirmReturn'"
              @click="confirmReturn(record)"
            >
              确认收货
            </a-button>
          </div>
        </template>
      </template>
    </a-table>

    <DetailDrawer
      v-model:open="drawerOpen"
      :refund-id="activeRefundId"
      :status-map="statusMap"
      :type-map="typeMap"
    />

    <ApproveModal
      v-if="hasAccessByCodes(['SystemRefundOrderApprove'])"
      v-model:open="approveModalOpen"
      :refund="activeRefund"
      @success="onReviewSuccess"
    />

    <RejectModal
      v-if="hasAccessByCodes(['SystemRefundOrderReject'])"
      v-model:open="rejectModalOpen"
      :refund="activeRefund"
      @success="onReviewSuccess"
    />
  </div>
</template>

<style scoped>
.refund-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 12px;
  max-width: 240px;
  white-space: normal;
}

.refund-actions :deep(.ant-btn) {
  height: 24px;
  padding: 0;
  line-height: 22px;
}
</style>
