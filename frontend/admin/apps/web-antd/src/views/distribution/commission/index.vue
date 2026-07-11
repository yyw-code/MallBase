<script lang="ts" setup>
import type { DistributionApi } from '#/api/distribution';

import { computed, reactive, ref } from 'vue';

import { message } from 'ant-design-vue';

import {
  adjustDistributionCommissionApi,
  getDistributionCommissionListApi,
  getDistributionCommissionLogsApi,
} from '#/api/distribution';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'DistributionCommission' });

const {
  tableData: commissionData,
  loading: commissionLoading,
  pagination: commissionPagination,
  loadData: loadCommissions,
} = useTableCrud<DistributionApi.CommissionItem, DistributionApi.ListParams>(
  { list: getDistributionCommissionListApi },
  { immediateLoad: false },
);

const {
  tableData: logData,
  loading: logLoading,
  pagination: logPagination,
  loadData: loadLogs,
} = useTableCrud<DistributionApi.LogItem, DistributionApi.ListParams>(
  { list: getDistributionCommissionLogsApi },
  { immediateLoad: false },
);

const activeTab = ref('orders');
const commissionSearch = ref({
  buyer_user_id: undefined as number | undefined,
  distributor_user_id: undefined as number | undefined,
  order_sn: '',
  status: undefined as number | undefined,
});
const logSearch = ref({
  biz_type: '',
  direction: '',
  user_id: undefined as number | undefined,
});
const adjustVisible = ref(false);
const adjustForm = reactive({
  amount: '',
  direction: 'income',
  remark: '',
  user_id: undefined as number | undefined,
});

const commissionStatusOptions = [
  { label: '冻结中', value: 10 },
  { label: '待结算', value: 20 },
  { label: '已结算', value: 30 },
  { label: '已扣回', value: 80 },
  { label: '已取消', value: 90 },
];

const logDirectionOptions = [
  { label: '收入', value: 'income' },
  { label: '支出', value: 'expense' },
];

const logBizTypeOptions = [
  { label: '订单冻结', value: 'order_frozen' },
  { label: '订单结算', value: 'order_settle' },
  { label: '退款扣回', value: 'refund_recover' },
  { label: '提现申请', value: 'withdraw_apply' },
  { label: '提现通过', value: 'withdraw_approve' },
  { label: '提现驳回', value: 'withdraw_reject' },
  { label: '后台调整', value: 'admin_adjust' },
];

const commissionColumns = [
  { title: '订单号', dataIndex: 'order_sn', width: 190 },
  { title: '买家ID', dataIndex: 'buyer_user_id', width: 100 },
  { title: '分销员ID', dataIndex: 'distributor_user_id', width: 110 },
  { title: '层级', dataIndex: 'relation_level', width: 80 },
  { title: '计佣金额', dataIndex: 'base_amount', width: 110 },
  { title: '比例(%)', dataIndex: 'rate', width: 100 },
  { title: '佣金', dataIndex: 'amount', width: 110 },
  { title: '已扣回', dataIndex: 'recovered_amount', width: 110 },
  { title: '状态', key: 'status', width: 100 },
  { title: '释放时间', dataIndex: 'release_time', width: 170 },
  { title: '创建时间', dataIndex: 'create_time', width: 170 },
];

const logColumns = [
  { title: '用户ID', dataIndex: 'user_id', width: 100 },
  { title: '业务类型', dataIndex: 'biz_type_text', width: 120 },
  { title: '业务单号', dataIndex: 'biz_id', width: 170 },
  { title: '账户', dataIndex: 'account_type', width: 110 },
  { title: '方向', key: 'direction', width: 90 },
  { title: '变动金额', dataIndex: 'change_amount', width: 110 },
  { title: '变动前', dataIndex: 'before_amount', width: 110 },
  { title: '变动后', dataIndex: 'after_amount', width: 110 },
  { title: '备注', dataIndex: 'remark', width: 220, ellipsis: true },
  { title: '创建时间', dataIndex: 'create_time', width: 170 },
];

const loading = computed(() =>
  activeTab.value === 'orders' ? commissionLoading.value : logLoading.value,
);

function resetCommissionSearch() {
  commissionSearch.value = {
    buyer_user_id: undefined,
    distributor_user_id: undefined,
    order_sn: '',
    status: undefined,
  };
  commissionPagination.current = 1;
  loadCommissions(commissionSearch.value);
}

function resetLogSearch() {
  logSearch.value = {
    biz_type: '',
    direction: '',
    user_id: undefined,
  };
  logPagination.current = 1;
  loadLogs(logSearch.value);
}

function refreshCurrent() {
  if (activeTab.value === 'orders') {
    loadCommissions(commissionSearch.value);
    return;
  }
  loadLogs(logSearch.value);
}

async function submitAdjust() {
  if (!adjustForm.user_id) {
    message.warning('请输入分销员用户ID');
    return;
  }
  if (!adjustForm.amount) {
    message.warning('请输入调整金额');
    return;
  }
  if (!adjustForm.remark.trim()) {
    message.warning('请填写调整原因');
    return;
  }

  await adjustDistributionCommissionApi({
    amount: adjustForm.amount,
    direction: adjustForm.direction,
    remark: adjustForm.remark,
    user_id: adjustForm.user_id,
  });
  message.success('调整成功');
  adjustVisible.value = false;
  adjustForm.amount = '';
  adjustForm.remark = '';
  adjustForm.user_id = undefined;
  await Promise.all([
    loadCommissions(commissionSearch.value),
    loadLogs(logSearch.value),
  ]);
}

loadCommissions(commissionSearch.value);
loadLogs(logSearch.value);
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">分销佣金</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button @click="refreshCurrent">刷新</a-button>
        <a-button
          v-access:code="'SystemDistributionCommissionAdjust'"
          type="primary"
          @click="adjustVisible = true"
        >
          调整佣金
        </a-button>
      </div>
    </div>

    <a-tabs v-model:active-key="activeTab">
      <a-tab-pane key="orders" tab="佣金订单">
        <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
          <a-form
            class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
          >
            <a-form-item label="订单号" class="mb-0">
              <a-input
                v-model:value="commissionSearch.order_sn"
                allow-clear
                placeholder="请输入订单号"
              />
            </a-form-item>
            <a-form-item label="分销员ID" class="mb-0">
              <a-input-number
                v-model:value="commissionSearch.distributor_user_id"
                class="w-full"
                :min="1"
                :precision="0"
              />
            </a-form-item>
            <a-form-item label="买家ID" class="mb-0">
              <a-input-number
                v-model:value="commissionSearch.buyer_user_id"
                class="w-full"
                :min="1"
                :precision="0"
              />
            </a-form-item>
            <a-form-item label="状态" class="mb-0">
              <a-select
                v-model:value="commissionSearch.status"
                allow-clear
                placeholder="请选择"
              >
                <a-select-option
                  v-for="item in commissionStatusOptions"
                  :key="item.value"
                  :value="item.value"
                >
                  {{ item.label }}
                </a-select-option>
              </a-select>
            </a-form-item>
            <a-form-item class="mb-0 md:col-span-3 xl:col-span-6">
              <div class="flex justify-end gap-2">
                <a-button
                  type="primary"
                  @click="
                    () => {
                      commissionPagination.current = 1;
                      loadCommissions(commissionSearch);
                    }
                  "
                >
                  搜索
                </a-button>
                <a-button @click="resetCommissionSearch">重置</a-button>
              </div>
            </a-form-item>
          </a-form>
        </div>

        <div class="overflow-hidden rounded-lg border bg-[hsl(var(--card))]">
          <a-table
            :columns="commissionColumns"
            :data-source="commissionData"
            :loading="loading"
            :pagination="commissionPagination"
            :scroll="{ x: 1440 }"
            row-key="id"
            @change="
              (newPagination: any) => {
                commissionPagination.current = newPagination.current;
                commissionPagination.pageSize = newPagination.pageSize;
                loadCommissions(commissionSearch);
              }
            "
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'status'">
                <a-tag>{{ record.status_text }}</a-tag>
              </template>
            </template>
          </a-table>
        </div>
      </a-tab-pane>

      <a-tab-pane key="logs" tab="佣金流水">
        <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
          <a-form
            class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
          >
            <a-form-item label="用户ID" class="mb-0">
              <a-input-number
                v-model:value="logSearch.user_id"
                class="w-full"
                :min="1"
                :precision="0"
              />
            </a-form-item>
            <a-form-item label="业务类型" class="mb-0">
              <a-select
                v-model:value="logSearch.biz_type"
                allow-clear
                placeholder="请选择"
              >
                <a-select-option
                  v-for="item in logBizTypeOptions"
                  :key="item.value"
                  :value="item.value"
                >
                  {{ item.label }}
                </a-select-option>
              </a-select>
            </a-form-item>
            <a-form-item label="方向" class="mb-0">
              <a-select
                v-model:value="logSearch.direction"
                allow-clear
                placeholder="请选择"
              >
                <a-select-option
                  v-for="item in logDirectionOptions"
                  :key="item.value"
                  :value="item.value"
                >
                  {{ item.label }}
                </a-select-option>
              </a-select>
            </a-form-item>
            <a-form-item class="mb-0 md:col-span-3 xl:col-span-6">
              <div class="flex justify-end gap-2">
                <a-button
                  type="primary"
                  @click="
                    () => {
                      logPagination.current = 1;
                      loadLogs(logSearch);
                    }
                  "
                >
                  搜索
                </a-button>
                <a-button @click="resetLogSearch">重置</a-button>
              </div>
            </a-form-item>
          </a-form>
        </div>

        <div class="overflow-hidden rounded-lg border bg-[hsl(var(--card))]">
          <a-table
            :columns="logColumns"
            :data-source="logData"
            :loading="loading"
            :pagination="logPagination"
            :scroll="{ x: 1380 }"
            row-key="id"
            @change="
              (newPagination: any) => {
                logPagination.current = newPagination.current;
                logPagination.pageSize = newPagination.pageSize;
                loadLogs(logSearch);
              }
            "
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'direction'">
                <a-tag :color="record.direction === 'income' ? 'green' : 'red'">
                  {{ record.direction === 'income' ? '收入' : '支出' }}
                </a-tag>
              </template>
            </template>
          </a-table>
        </div>
      </a-tab-pane>
    </a-tabs>

    <a-modal v-model:open="adjustVisible" title="调整佣金" @ok="submitAdjust">
      <a-form class="pt-4" :label-col="{ style: { width: '110px' } }">
        <a-form-item label="分销员ID">
          <a-input-number
            v-model:value="adjustForm.user_id"
            class="w-full"
            :min="1"
            :precision="0"
          />
        </a-form-item>
        <a-form-item label="调整方向">
          <a-radio-group v-model:value="adjustForm.direction">
            <a-radio value="income">增加</a-radio>
            <a-radio value="expense">扣减</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="金额">
          <a-input v-model:value="adjustForm.amount" placeholder="例如 10.00" />
        </a-form-item>
        <a-form-item label="调整原因">
          <a-textarea
            v-model:value="adjustForm.remark"
            :rows="3"
            allow-clear
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
