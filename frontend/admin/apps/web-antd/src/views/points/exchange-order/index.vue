<script lang="ts" setup>
import type { PointsExchangeOrderApi } from '#/api/points';

import { computed, onMounted, reactive, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Modal } from 'ant-design-vue';

import {
  closePointsExchangeOrderApi,
  completePointsExchangeOrderApi,
  getPointsExchangeOrderInfoApi,
  getPointsExchangeOrderListApi,
  getPointsExchangeOrderStatusOptionsApi,
  shipPointsExchangeOrderApi,
} from '#/api/points';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'PointsExchangeOrderManagement' });

const STATUS_PENDING_SHIP = 10;
const STATUS_SHIPPED = 20;
const STATUS_COMPLETED = 30;
const STATUS_CLOSED = 90;

const { hasAccessByCodes } = useAccess();

const { tableData, loading, pagination, loadData } = useTableCrud<
  PointsExchangeOrderApi.OrderItem,
  PointsExchangeOrderApi.ListParams
>(
  {
    list: getPointsExchangeOrderListApi,
  },
  { immediateLoad: false },
);

const searchParams = ref({
  sn: '',
  status: undefined as number | undefined,
  user_id: undefined as number | undefined,
});

const statusOptions = ref<PointsExchangeOrderApi.StatusOption[]>([]);
const detailVisible = ref(false);
const detailLoading = ref(false);
const detailData = ref<null | PointsExchangeOrderApi.OrderItem>(null);

const shipVisible = ref(false);
const shipLoading = ref(false);
const shipFormRef = ref();
const shipTarget = ref<null | PointsExchangeOrderApi.OrderItem>(null);
const shipForm = reactive<PointsExchangeOrderApi.ShipParams>({
  admin_remark: '',
  logistics_company: '',
  logistics_no: '',
});

const closeVisible = ref(false);
const closeLoading = ref(false);
const closeFormRef = ref();
const closeTarget = ref<null | PointsExchangeOrderApi.OrderItem>(null);
const closeForm = reactive<PointsExchangeOrderApi.CloseParams>({
  admin_remark: '',
});
const imageErrorKeys = ref<Record<string, true>>({});

const shipRules = {
  logistics_company: [
    { required: true, message: '请输入物流公司', trigger: 'blur' },
  ],
  logistics_no: [
    { required: true, message: '请输入物流单号', trigger: 'blur' },
  ],
};

const closeRules = {
  admin_remark: [
    { required: true, message: '请输入关闭原因', trigger: 'blur' },
  ],
};

const resetSearch = () => {
  searchParams.value = {
    sn: '',
    status: undefined,
    user_id: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

const statusColor = (status: number) => {
  if (status === STATUS_PENDING_SHIP) return 'orange';
  if (status === STATUS_SHIPPED) return 'blue';
  if (status === STATUS_COMPLETED) return 'green';
  if (status === STATUS_CLOSED) return 'default';
  return 'default';
};

const canShip = (record: PointsExchangeOrderApi.OrderItem) =>
  record.status === STATUS_PENDING_SHIP &&
  hasAccessByCodes(['SystemPointsExchangeOrderShip']);

const canClose = (record: PointsExchangeOrderApi.OrderItem) =>
  record.status === STATUS_PENDING_SHIP &&
  hasAccessByCodes(['SystemPointsExchangeOrderClose']);

const canComplete = (record: PointsExchangeOrderApi.OrderItem) =>
  record.status === STATUS_SHIPPED &&
  hasAccessByCodes(['SystemPointsExchangeOrderComplete']);

const normalizeImageUrl = (raw?: string) => {
  const value = String(raw || '');
  return value && !/^\d+$/.test(value) ? value : '';
};

const goodsImageUrl = (record: PointsExchangeOrderApi.OrderItem) =>
  record.goods_image_full_url || normalizeImageUrl(record.goods_image);

const imageKey = (record: PointsExchangeOrderApi.OrderItem) =>
  `${record.id}:${goodsImageUrl(record)}`;

const hasGoodsImage = (record: PointsExchangeOrderApi.OrderItem) =>
  !!goodsImageUrl(record) && !imageErrorKeys.value[imageKey(record)];

const markImageError = (record: PointsExchangeOrderApi.OrderItem) => {
  imageErrorKeys.value = {
    ...imageErrorKeys.value,
    [imageKey(record)]: true,
  };
};

const columns = [
  { title: '兑换单号', dataIndex: 'sn', width: 190, ellipsis: true },
  { title: '商品', key: 'goods', width: 320 },
  { title: '用户ID', dataIndex: 'user_id', width: 100 },
  {
    title: '消耗积分',
    dataIndex: 'total_points',
    width: 120,
    customRender: ({ record }: { record: PointsExchangeOrderApi.OrderItem }) =>
      `${record.total_points} 积分`,
  },
  { title: '数量', dataIndex: 'quantity', width: 80 },
  { title: '收货人', key: 'receiver', width: 220 },
  { title: '状态', key: 'status', width: 110 },
  { title: '物流', key: 'logistics', width: 220 },
  { title: '创建时间', dataIndex: 'create_time', width: 170 },
  { title: '操作', key: 'action', width: 230, fixed: 'right' },
];

const showDetail = async (record: PointsExchangeOrderApi.OrderItem) => {
  detailLoading.value = true;
  detailVisible.value = true;
  try {
    detailData.value = await getPointsExchangeOrderInfoApi(record.id);
  } catch (error) {
    console.error('获取兑换单详情失败:', error);
    message.error('获取兑换单详情失败');
    detailVisible.value = false;
  } finally {
    detailLoading.value = false;
  }
};

const openShip = (record: PointsExchangeOrderApi.OrderItem) => {
  shipTarget.value = record;
  Object.assign(shipForm, {
    admin_remark: record.admin_remark || '',
    logistics_company: record.logistics_company || '',
    logistics_no: record.logistics_no || '',
  });
  shipVisible.value = true;
};

const submitShip = async () => {
  if (!shipTarget.value) return;
  try {
    await shipFormRef.value?.validate();
    shipLoading.value = true;
    await shipPointsExchangeOrderApi(shipTarget.value.id, { ...shipForm });
    message.success('发货成功');
    shipVisible.value = false;
    await loadData(searchParams.value);
  } catch (error: any) {
    if (!error?.errorFields) {
      message.error(error?.message || '发货失败');
    }
  } finally {
    shipLoading.value = false;
  }
};

const confirmComplete = (record: PointsExchangeOrderApi.OrderItem) => {
  Modal.confirm({
    content: `确认将兑换单 ${record.sn} 标记为已完成吗？`,
    title: '完成兑换单',
    onOk: async () => {
      await completePointsExchangeOrderApi(record.id);
      message.success('操作成功');
      await loadData(searchParams.value);
    },
  });
};

const openClose = (record: PointsExchangeOrderApi.OrderItem) => {
  closeTarget.value = record;
  closeForm.admin_remark = '';
  closeVisible.value = true;
};

const submitClose = async () => {
  if (!closeTarget.value) return;
  try {
    await closeFormRef.value?.validate();
    closeLoading.value = true;
    await closePointsExchangeOrderApi(closeTarget.value.id, { ...closeForm });
    message.success('关闭成功');
    closeVisible.value = false;
    await loadData(searchParams.value);
  } catch (error: any) {
    if (!error?.errorFields) {
      message.error(error?.message || '关闭失败');
    }
  } finally {
    closeLoading.value = false;
  }
};

const detailTitle = computed(() =>
  detailData.value ? `兑换单 ${detailData.value.sn}` : '兑换单详情',
);

onMounted(async () => {
  statusOptions.value = await getPointsExchangeOrderStatusOptionsApi();
  await loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">兑换订单</h2>
      <a-button @click="() => loadData(searchParams)">刷新</a-button>
    </div>

    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item label="兑换单号" class="mb-0">
          <a-input
            v-model:value="searchParams.sn"
            allow-clear
            class="w-full"
            placeholder="请输入兑换单号"
          />
        </a-form-item>
        <a-form-item label="用户ID" class="mb-0">
          <a-input-number
            v-model:value="searchParams.user_id"
            class="w-full"
            :min="1"
            :precision="0"
            placeholder="请输入用户ID"
          />
        </a-form-item>
        <a-form-item label="状态" class="mb-0">
          <a-select
            v-model:value="searchParams.status"
            allow-clear
            class="w-full"
            placeholder="请选择"
          >
            <a-select-option
              v-for="option in statusOptions"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item class="mb-0 md:col-span-3 xl:col-span-6">
          <div class="flex justify-end gap-2">
            <a-button
              type="primary"
              @click="
                () => {
                  pagination.current = 1;
                  loadData(searchParams);
                }
              "
            >
              搜索
            </a-button>
            <a-button @click="resetSearch">重置</a-button>
          </div>
        </a-form-item>
      </a-form>
    </div>

    <div class="overflow-hidden rounded-lg border bg-[hsl(var(--card))]">
      <a-table
        :columns="columns"
        :data-source="tableData"
        :loading="loading"
        :pagination="pagination"
        :scroll="{ x: 1560 }"
        row-key="id"
        @change="
          (newPagination: any) => {
            pagination.current = newPagination.current;
            pagination.pageSize = newPagination.pageSize;
            loadData(searchParams);
          }
        "
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'goods'">
            <div class="flex items-center gap-3">
              <img
                v-if="hasGoodsImage(record)"
                :src="goodsImageUrl(record)"
                class="h-12 w-12 shrink-0 rounded object-cover"
                @error="markImageError(record)"
              />
              <div
                v-else
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded bg-muted text-[10px] text-muted-foreground"
              >
                暂无图
              </div>
              <div class="min-w-0">
                <div class="truncate font-medium">
                  {{ record.goods_name || '-' }}
                </div>
                <div class="mt-1 text-xs text-muted-foreground">
                  {{ record.sku_spec || '默认规格' }} / 单件
                  {{ record.points_price }} 积分
                </div>
              </div>
            </div>
          </template>

          <template v-else-if="column.key === 'receiver'">
            <div>{{ record.receiver_name }} {{ record.receiver_phone }}</div>
            <div class="mt-1 truncate text-xs text-muted-foreground">
              {{ record.receiver_full_address }}
            </div>
          </template>

          <template v-else-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">
              {{ record.status_text || '-' }}
            </a-tag>
          </template>

          <template v-else-if="column.key === 'logistics'">
            <template v-if="record.logistics_company || record.logistics_no">
              <div>{{ record.logistics_company || '-' }}</div>
              <div class="mt-1 text-xs text-muted-foreground">
                {{ record.logistics_no || '-' }}
              </div>
            </template>
            <span v-else>-</span>
          </template>

          <template v-else-if="column.key === 'action'">
            <a-space>
              <a-button size="small" type="link" @click="showDetail(record)">
                详情
              </a-button>
              <a-button
                v-if="canShip(record)"
                size="small"
                type="link"
                @click="openShip(record)"
              >
                发货
              </a-button>
              <a-button
                v-if="canComplete(record)"
                size="small"
                type="link"
                @click="confirmComplete(record)"
              >
                完成
              </a-button>
              <a-button
                v-if="canClose(record)"
                danger
                size="small"
                type="link"
                @click="openClose(record)"
              >
                关闭
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <a-modal
      :open="detailVisible"
      :title="detailTitle"
      width="760px"
      @cancel="detailVisible = false"
      @ok="detailVisible = false"
    >
      <a-spin :spinning="detailLoading">
        <a-descriptions v-if="detailData" bordered :column="2" size="small">
          <a-descriptions-item label="状态">
            <a-tag :color="statusColor(detailData.status)">
              {{ detailData.status_text }}
            </a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="用户ID">
            {{ detailData.user_id }}
          </a-descriptions-item>
          <a-descriptions-item label="商品" :span="2">
            {{ detailData.goods_name }} /
            {{ detailData.sku_spec || '默认规格' }}
          </a-descriptions-item>
          <a-descriptions-item label="积分">
            {{ detailData.total_points }}
          </a-descriptions-item>
          <a-descriptions-item label="数量">
            {{ detailData.quantity }}
          </a-descriptions-item>
          <a-descriptions-item label="收货人">
            {{ detailData.receiver_name }} {{ detailData.receiver_phone }}
          </a-descriptions-item>
          <a-descriptions-item label="地址" :span="2">
            {{ detailData.receiver_full_address }}
          </a-descriptions-item>
          <a-descriptions-item label="物流公司">
            {{ detailData.logistics_company || '-' }}
          </a-descriptions-item>
          <a-descriptions-item label="物流单号">
            {{ detailData.logistics_no || '-' }}
          </a-descriptions-item>
          <a-descriptions-item label="买家备注" :span="2">
            {{ detailData.buyer_remark || '-' }}
          </a-descriptions-item>
          <a-descriptions-item label="后台备注" :span="2">
            {{ detailData.admin_remark || '-' }}
          </a-descriptions-item>
        </a-descriptions>
        <div
          v-if="detailData?.logs && detailData.logs.length > 0"
          class="mt-4 rounded-lg border p-4"
        >
          <div class="mb-3 font-medium">操作记录</div>
          <a-timeline>
            <a-timeline-item v-for="log in detailData.logs" :key="log.id">
              <div class="flex flex-col gap-1">
                <div>
                  <span class="font-medium">{{ log.action_text }}</span>
                  <span class="ml-2 text-xs text-muted-foreground">
                    {{ log.operator_type_text }}
                    <template v-if="log.operator_id">
                      #{{ log.operator_id }}
                    </template>
                  </span>
                </div>
                <div class="text-xs text-muted-foreground">
                  {{ log.create_time }}
                  <template
                    v-if="
                      log.from_status !== null && log.from_status !== undefined
                    "
                  >
                    · {{ log.from_status_text || '-' }} ->
                    {{ log.to_status_text || '-' }}
                  </template>
                </div>
                <div v-if="log.remark" class="text-xs text-muted-foreground">
                  {{ log.remark }}
                </div>
              </div>
            </a-timeline-item>
          </a-timeline>
        </div>
      </a-spin>
    </a-modal>

    <a-modal
      :confirm-loading="shipLoading"
      :open="shipVisible"
      title="兑换单发货"
      @cancel="shipVisible = false"
      @ok="submitShip"
    >
      <a-form
        ref="shipFormRef"
        class="pt-4"
        :label-col="{ style: { width: '100px' } }"
        :model="shipForm"
        :rules="shipRules"
      >
        <a-form-item label="物流公司" name="logistics_company">
          <a-input
            v-model:value="shipForm.logistics_company"
            allow-clear
            placeholder="请输入物流公司"
          />
        </a-form-item>
        <a-form-item label="物流单号" name="logistics_no">
          <a-input
            v-model:value="shipForm.logistics_no"
            allow-clear
            placeholder="请输入物流单号"
          />
        </a-form-item>
        <a-form-item label="后台备注" name="admin_remark">
          <a-textarea
            v-model:value="shipForm.admin_remark"
            :maxlength="255"
            :rows="3"
            allow-clear
            show-count
          />
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal
      :confirm-loading="closeLoading"
      :open="closeVisible"
      ok-text="关闭并返还"
      title="关闭兑换单"
      @cancel="closeVisible = false"
      @ok="submitClose"
    >
      <a-form
        ref="closeFormRef"
        class="pt-4"
        :label-col="{ style: { width: '100px' } }"
        :model="closeForm"
        :rules="closeRules"
      >
        <a-form-item label="关闭原因" name="admin_remark">
          <a-textarea
            v-model:value="closeForm.admin_remark"
            :maxlength="255"
            :rows="4"
            allow-clear
            show-count
            placeholder="关闭后会返还用户积分并恢复兑换库存"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
