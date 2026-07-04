<script lang="ts" setup>
import type { PointsLogApi } from '#/api/points';

import { onMounted, ref } from 'vue';

import { Tag } from 'ant-design-vue';

import { getPointsLogListApi } from '#/api/points';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'PointsLogManagement' });

const { tableData, loading, pagination, loadData } = useTableCrud<
  PointsLogApi.LogItem,
  PointsLogApi.ListParams
>(
  {
    list: getPointsLogListApi,
  },
  { immediateLoad: false },
);

const searchParams = ref({
  user_id: undefined as number | undefined,
  type: undefined as 'expense' | 'income' | undefined,
  biz_type: undefined as string | undefined,
});

const resetSearch = () => {
  searchParams.value = {
    user_id: undefined,
    type: undefined,
    biz_type: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

const columns = [
  { title: '时间', dataIndex: 'create_time', width: 170 },
  { title: '用户ID', dataIndex: 'user_id', width: 100 },
  {
    title: '方向',
    dataIndex: 'direction',
    width: 90,
    customRender: ({ record }: { record: PointsLogApi.LogItem }) =>
      record.direction === 'income' ? '收入' : '支出',
  },
  {
    title: '积分',
    dataIndex: 'change_points',
    width: 110,
    customRender: ({ record }: { record: PointsLogApi.LogItem }) =>
      `${record.direction === 'income' ? '+' : '-'}${record.change_points}`,
  },
  { title: '变动前', dataIndex: 'before_points', width: 110 },
  { title: '变动后', dataIndex: 'after_points', width: 110 },
  {
    title: '业务类型',
    dataIndex: 'biz_type_text',
    width: 130,
    customRender: ({ record }: { record: PointsLogApi.LogItem }) =>
      record.biz_type_text || record.biz_type || '-',
  },
  { title: '业务单号', dataIndex: 'biz_id', width: 190, ellipsis: true },
  {
    title: '操作人',
    dataIndex: 'operator_id',
    width: 120,
    customRender: ({ record }: { record: PointsLogApi.LogItem }) => {
      if (record.operator_type === 0) return '系统';
      if (record.operator_id) return record.operator_id;
      return '-';
    },
  },
  { title: '备注', dataIndex: 'remark', width: 240, ellipsis: true },
];

onMounted(() => {
  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">积分流水</h2>
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
            placeholder="请输入用户ID"
          />
        </a-form-item>
        <a-form-item label="方向" class="mb-0">
          <a-select
            v-model:value="searchParams.type"
            allow-clear
            class="w-full"
            placeholder="请选择"
          >
            <a-select-option value="income">收入</a-select-option>
            <a-select-option value="expense">支出</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="业务类型" class="mb-0">
          <a-select
            v-model:value="searchParams.biz_type"
            allow-clear
            class="w-full"
            placeholder="请选择"
          >
            <a-select-option value="order_complete">订单完成</a-select-option>
            <a-select-option value="refund">售后回收</a-select-option>
            <a-select-option value="admin_adjust">后台调整</a-select-option>
            <a-select-option value="points_exchange">积分商品兑换</a-select-option>
            <a-select-option value="points_exchange_return">
              兑换关闭返还
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
        :scroll="{ x: 1380 }"
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
          <template v-if="column.dataIndex === 'direction'">
            <Tag :color="record.direction === 'income' ? 'green' : 'orange'">
              {{ record.direction === 'income' ? '收入' : '支出' }}
            </Tag>
          </template>
        </template>
      </a-table>
    </div>
  </div>
</template>
