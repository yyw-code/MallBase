<script lang="ts" setup>
import type { OrderApi } from '#/api/order';

import { computed, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Modal } from 'ant-design-vue';

import {
  closeOrderApi,
  getOrderListApi,
  getOrderStatusOptionsApi,
} from '#/api/order';
import { useTableCrud } from '#/composables/useTableCrud';

import DetailDrawer from './detail-drawer.vue';
import ShipModal from './ship-modal.vue';

defineOptions({ name: 'OrderManagement' });

const { hasAccessByCodes } = useAccess();

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData } = useTableCrud<
  OrderApi.OrderRecord,
  OrderApi.ListParams
>(
  {
    list: getOrderListApi,
  },
  { immediateLoad: false },
);

/* ---------------- 搜索参数 ---------------- */
interface SearchForm {
  sn: string;
  status: number | undefined;
  user_id: number | undefined;
  logistics_sn: string;
  created_range: [string, string] | undefined;
  has_after_sale: 0 | 1 | undefined;
}

const searchParams = ref<SearchForm>({
  sn: '',
  status: undefined,
  user_id: undefined,
  logistics_sn: '',
  created_range: undefined,
  has_after_sale: undefined,
});

const buildQuery = (): OrderApi.ListParams => {
  const range = searchParams.value.created_range;
  return {
    sn: searchParams.value.sn?.trim() || undefined,
    status: searchParams.value.status,
    user_id: searchParams.value.user_id,
    logistics_sn: searchParams.value.logistics_sn?.trim() || undefined,
    created_start: range?.[0] || undefined,
    created_end: range?.[1] || undefined,
    has_after_sale: searchParams.value.has_after_sale,
  };
};

const resetSearch = () => {
  searchParams.value = {
    sn: '',
    status: undefined,
    user_id: undefined,
    logistics_sn: '',
    created_range: undefined,
    has_after_sale: undefined,
  };
  pagination.current = 1;
  loadData(buildQuery());
};

const submitSearch = () => {
  pagination.current = 1;
  loadData(buildQuery());
};

/* ---------------- 枚举选项 ---------------- */
const statusOptions = ref<OrderApi.EnumOption[]>([]);
const payMethodOptions = ref<OrderApi.EnumOption[]>([]);

const statusMap = computed<Record<number, string>>(() =>
  Object.fromEntries(statusOptions.value.map((o) => [o.value, o.label])),
);
const payMethodMap = computed<Record<number, string>>(() =>
  Object.fromEntries(payMethodOptions.value.map((o) => [o.value, o.label])),
);

const loadStatusOptions = async () => {
  try {
    const res = await getOrderStatusOptionsApi();
    statusOptions.value = res?.status ?? [];
    payMethodOptions.value = res?.pay_method ?? [];
  } catch (error: any) {
    console.error('加载订单枚举失败：', error?.message || error);
  }
};

/* ---------------- 详情抽屉 ---------------- */
const drawerOpen = ref(false);
const activeOrderId = ref<null | number>(null);

const openDetail = (record: OrderApi.OrderRecord) => {
  activeOrderId.value = record.id;
  drawerOpen.value = true;
};

/* ---------------- 发货弹窗 ---------------- */
const shipModalOpen = ref(false);
const shipTargetOrder = ref<null | OrderApi.OrderRecord>(null);

const openShip = (record: OrderApi.OrderRecord) => {
  if (record.status !== 10) {
    message.warning('仅已支付订单可发货');
    return;
  }
  shipTargetOrder.value = record;
  shipModalOpen.value = true;
};

const onShipSuccess = async () => {
  await loadData(buildQuery());
};

/* ---------------- 关闭订单 ---------------- */
const handleClose = (record: OrderApi.OrderRecord) => {
  if (![0, 10].includes(record.status)) {
    message.warning('当前状态不允许关闭');
    return;
  }
  Modal.confirm({
    title: '关闭订单',
    content: `确认关闭订单 ${record.sn}？已扣减的库存将自动回滚。`,
    okText: '确认关闭',
    okButtonProps: { danger: true },
    cancelText: '取消',
    onOk: async () => {
      try {
        await closeOrderApi(record.id);
        message.success('订单已关闭');
        await loadData(buildQuery());
      } catch (error: any) {
        message.error(error?.message || '关闭失败');
      }
    },
  });
};

/* ---------------- 表格列 ---------------- */
const columns = [
  { title: 'ID', dataIndex: 'id', width: 70, fixed: 'left' },
  { title: '订单号', dataIndex: 'sn', width: 180, fixed: 'left' },
  {
    title: '状态',
    dataIndex: 'status',
    width: 120,
    customRender: ({ record }: { record: OrderApi.OrderRecord }) => {
      const text = record.status_text || statusMap.value[record.status] || '—';
      return record.after_sale_tag_text
        ? `${text} · 售后 · ${record.after_sale_tag_text}`
        : text;
    },
  },
  { title: '买家 ID', dataIndex: 'user_id', width: 90 },
  { title: '应付', dataIndex: 'pay_amount', width: 100 },
  {
    title: '支付方式',
    dataIndex: 'pay_method',
    width: 100,
    customRender: ({ record }: { record: OrderApi.OrderRecord }) =>
      record.pay_method_text ||
      (record.pay_method == null ? '—' : payMethodMap.value[record.pay_method]),
  },
  { title: '收件人', dataIndex: 'receiver_name', width: 110, ellipsis: true },
  { title: '运单号', dataIndex: 'logistics_sn', width: 160, ellipsis: true },
  { title: '下单时间', dataIndex: 'create_time', width: 170 },
  { title: '操作', key: 'action', fixed: 'right', width: 220 },
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
      <a-form-item label="订单号">
        <a-input
          v-model:value="searchParams.sn"
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
          style="width: 140px"
          :options="
            statusOptions.map((o) => ({ label: o.label, value: o.value }))
          "
        />
      </a-form-item>
      <a-form-item label="买家ID">
        <a-input-number
          v-model:value="searchParams.user_id"
          placeholder="买家ID"
          :min="1"
          style="width: 120px"
          @press-enter="submitSearch"
        />
      </a-form-item>
      <a-form-item label="运单号">
        <a-input
          v-model:value="searchParams.logistics_sn"
          placeholder="运单号"
          allow-clear
          style="width: 160px"
          @press-enter="submitSearch"
        />
      </a-form-item>
      <a-form-item label="下单时间">
        <a-range-picker
          v-model:value="searchParams.created_range"
          show-time
          value-format="YYYY-MM-DD HH:mm:ss"
          style="width: 360px"
        />
      </a-form-item>
      <a-form-item label="售后">
        <a-select
          v-model:value="searchParams.has_after_sale"
          placeholder="是否有售后"
          allow-clear
          style="width: 140px"
        >
          <a-select-option :value="1">有进行中售后</a-select-option>
          <a-select-option :value="0">无进行中售后</a-select-option>
        </a-select>
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
      :scroll="{ x: 1500 }"
      row-key="id"
      @change="
        (newPagination) => {
          pagination.current = newPagination.current;
          pagination.pageSize = newPagination.pageSize;
          loadData(buildQuery());
        }
      "
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'action'">
          <a-space>
            <a-button
              type="link"
              size="small"
              v-access:code="'SystemOrderDetail'"
              @click="openDetail(record)"
            >
              详情
            </a-button>
            <a-button
              v-if="record.status === 10"
              type="link"
              size="small"
              v-access:code="'SystemOrderShip'"
              @click="openShip(record)"
            >
              发货
            </a-button>
            <a-button
              v-if="[0, 10].includes(record.status)"
              type="link"
              danger
              size="small"
              v-access:code="'SystemOrderClose'"
              @click="handleClose(record)"
            >
              关闭
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>

    <DetailDrawer
      v-model:open="drawerOpen"
      :order-id="activeOrderId"
      :status-map="statusMap"
      :pay-method-map="payMethodMap"
    />

    <ShipModal
      v-if="hasAccessByCodes(['SystemOrderShip'])"
      v-model:open="shipModalOpen"
      :order="shipTargetOrder"
      @success="onShipSuccess"
    />
  </div>
</template>
