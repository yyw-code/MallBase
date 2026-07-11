<script lang="ts" setup>
import type { DistributionApi } from '#/api/distribution';

import { reactive, ref } from 'vue';

import { message } from 'ant-design-vue';

import {
  approveDistributionWithdrawApi,
  getDistributionWithdrawListApi,
  rejectDistributionWithdrawApi,
} from '#/api/distribution';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'DistributionWithdraw' });

const { tableData, loading, pagination, loadData } = useTableCrud<
  DistributionApi.WithdrawItem,
  DistributionApi.ListParams
>(
  { list: getDistributionWithdrawListApi },
  { immediateLoad: false },
);

const searchParams = ref({
  status: undefined as number | undefined,
  user_id: undefined as number | undefined,
});
const reviewVisible = ref(false);
const reviewMode = ref<'approve' | 'reject'>('approve');
const reviewTarget = ref<null | DistributionApi.WithdrawItem>(null);
const reviewForm = reactive({
  admin_remark: '',
});

const statusOptions = [
  { label: '待审核', value: 0 },
  { label: '已通过', value: 10 },
  { label: '已驳回', value: 20 },
];

const columns = [
  { title: '提现单号', dataIndex: 'sn', width: 190 },
  { title: '用户ID', dataIndex: 'user_id', width: 100 },
  { title: '金额', dataIndex: 'amount', width: 110 },
  { title: '账户类型', dataIndex: 'account_type', width: 110 },
  { title: '账户名', dataIndex: 'account_name', width: 140 },
  { title: '账户号', dataIndex: 'account_no', width: 180, ellipsis: true },
  { title: '状态', key: 'status', width: 110 },
  { title: '审核备注', dataIndex: 'admin_remark', width: 220, ellipsis: true },
  { title: '审核时间', dataIndex: 'reviewed_at', width: 170 },
  { title: '申请时间', dataIndex: 'create_time', width: 170 },
  { title: '操作', key: 'action', width: 160, fixed: 'right' },
];

function resetSearch() {
  searchParams.value = {
    status: undefined,
    user_id: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
}

function openReview(record: DistributionApi.WithdrawItem, mode: 'approve' | 'reject') {
  reviewTarget.value = record;
  reviewMode.value = mode;
  reviewForm.admin_remark = '';
  reviewVisible.value = true;
}

async function submitReview() {
  if (!reviewTarget.value) return;
  if (reviewMode.value === 'reject' && !reviewForm.admin_remark.trim()) {
    message.warning('请填写驳回原因');
    return;
  }

  if (reviewMode.value === 'approve') {
    await approveDistributionWithdrawApi(
      reviewTarget.value.id,
      reviewForm.admin_remark,
    );
    message.success('审核通过');
  } else {
    await rejectDistributionWithdrawApi(
      reviewTarget.value.id,
      reviewForm.admin_remark,
    );
    message.success('已驳回');
  }
  reviewVisible.value = false;
  await loadData(searchParams.value);
}

loadData(searchParams.value);
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">分销提现</h2>
      <a-button @click="() => loadData(searchParams)">刷新</a-button>
    </div>

    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item label="用户ID" class="mb-0">
          <a-input-number
            v-model:value="searchParams.user_id"
            class="w-full"
            :min="1"
            :precision="0"
          />
        </a-form-item>
        <a-form-item label="状态" class="mb-0">
          <a-select
            v-model:value="searchParams.status"
            allow-clear
            placeholder="请选择"
          >
            <a-select-option
              v-for="item in statusOptions"
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
        :scroll="{ x: 1460 }"
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
          <template v-if="column.key === 'status'">
            <a-tag
              :color="
                record.status === 0
                  ? 'orange'
                  : record.status === 10
                    ? 'green'
                    : 'red'
              "
            >
              {{ record.status_text }}
            </a-tag>
          </template>
          <template v-if="column.key === 'action'">
            <a-space v-if="record.status === 0">
              <a-button
                v-access:code="'SystemDistributionWithdrawApprove'"
                size="small"
                type="link"
                @click="openReview(record, 'approve')"
              >
                通过
              </a-button>
              <a-button
                v-access:code="'SystemDistributionWithdrawReject'"
                danger
                size="small"
                type="link"
                @click="openReview(record, 'reject')"
              >
                驳回
              </a-button>
            </a-space>
            <span v-else>-</span>
          </template>
        </template>
      </a-table>
    </div>

    <a-modal
      v-model:open="reviewVisible"
      :title="reviewMode === 'approve' ? '通过提现' : '驳回提现'"
      @ok="submitReview"
    >
      <a-form class="pt-4" :label-col="{ style: { width: '100px' } }">
        <a-form-item label="提现单号">
          <span>{{ reviewTarget?.sn }}</span>
        </a-form-item>
        <a-form-item label="提现金额">
          <span>{{ reviewTarget?.amount }}</span>
        </a-form-item>
        <a-form-item label="审核备注">
          <a-textarea
            v-model:value="reviewForm.admin_remark"
            :placeholder="
              reviewMode === 'reject' ? '请填写驳回原因' : '可选'
            "
            :rows="3"
            allow-clear
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
