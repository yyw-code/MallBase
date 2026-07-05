<script lang="ts" setup>
import type { DistributionApi } from '#/api/distribution';

import { h, onMounted, reactive, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Switch, Tag } from 'ant-design-vue';

import {
  getDistributionDistributorListApi,
  getDistributionLevelListApi,
  openDistributionDistributorApi,
  updateDistributionDistributorStatusApi,
} from '#/api/distribution';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'DistributionDistributor' });

const { hasAccessByCodes } = useAccess();
const { tableData, loading, pagination, loadData } = useTableCrud<
  DistributionApi.DistributorItem,
  DistributionApi.ListParams
>(
  { list: getDistributionDistributorListApi },
  { immediateLoad: false },
);

const searchParams = ref({ keyword: '', level_id: undefined as number | undefined, status: undefined as number | undefined });
const levels = ref<DistributionApi.LevelItem[]>([]);
const openVisible = ref(false);
const openForm = reactive({ level_id: 1, remark: '', user_id: undefined as number | undefined });

const columns = [
  { title: '用户ID', dataIndex: 'user_id', width: 90 },
  {
    title: '用户',
    dataIndex: 'user',
    width: 180,
    customRender: ({ record }: { record: DistributionApi.DistributorItem }) =>
      record.user?.nickname || record.user?.mobile || '-',
  },
  { title: '等级', dataIndex: 'level_name', width: 130 },
  { title: '邀请码', dataIndex: 'invite_code', width: 130 },
  { title: '可提现', dataIndex: 'available_commission', width: 110 },
  { title: '冻结', dataIndex: 'frozen_commission', width: 110 },
  { title: '团队', key: 'team', width: 120 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 110,
    customRender: ({ record }: { record: DistributionApi.DistributorItem }) => {
      if (!hasAccessByCodes(['SystemDistributionDistributorStatus'])) {
        return h(Tag, { color: record.status === 1 ? 'green' : 'default' }, () =>
          record.status === 1 ? '启用' : '禁用',
        );
      }
      return h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: (checked: boolean | number | string) => updateStatus(record, checked === true ? 1 : 0),
      });
    },
  },
  { title: '创建时间', dataIndex: 'create_time', width: 170 },
];

function resetSearch() {
  searchParams.value = { keyword: '', level_id: undefined, status: undefined };
  pagination.current = 1;
  loadData(searchParams.value);
}

async function updateStatus(record: DistributionApi.DistributorItem, status: number) {
  await updateDistributionDistributorStatusApi(record.user_id, status);
  message.success('状态更新成功');
  await loadData(searchParams.value);
}

async function submitOpen() {
  if (!openForm.user_id) {
    message.warning('请输入用户ID');
    return;
  }
  await openDistributionDistributorApi({
    level_id: openForm.level_id,
    remark: openForm.remark,
    user_id: openForm.user_id,
  });
  message.success('开通成功');
  openVisible.value = false;
  await loadData(searchParams.value);
}

onMounted(async () => {
  const data = await getDistributionLevelListApi({ limit: 100, page: 1, status: 1 });
  levels.value = data.list;
  if (levels.value[0]) openForm.level_id = levels.value[0].id;
  await loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">分销员</h2>
      <div class="flex gap-2">
        <a-button @click="() => loadData(searchParams)">刷新</a-button>
        <a-button v-access:code="'SystemDistributionDistributorOpen'" type="primary" @click="openVisible = true">
          开通分销员
        </a-button>
      </div>
    </div>

    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6">
        <a-form-item label="关键词" class="mb-0">
          <a-input v-model:value="searchParams.keyword" allow-clear placeholder="昵称/手机/用户ID" />
        </a-form-item>
        <a-form-item label="等级" class="mb-0">
          <a-select v-model:value="searchParams.level_id" allow-clear placeholder="请选择">
            <a-select-option v-for="level in levels" :key="level.id" :value="level.id">
              {{ level.name }}
            </a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="状态" class="mb-0">
          <a-select v-model:value="searchParams.status" allow-clear placeholder="请选择">
            <a-select-option :value="1">启用</a-select-option>
            <a-select-option :value="0">禁用</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item class="mb-0 md:col-span-3 xl:col-span-6">
          <div class="flex justify-end gap-2">
            <a-button type="primary" @click="() => { pagination.current = 1; loadData(searchParams); }">搜索</a-button>
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
        :scroll="{ x: 1120 }"
        row-key="id"
        @change="(p: any) => { pagination.current = p.current; pagination.pageSize = p.pageSize; loadData(searchParams); }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'team'">
            {{ record.direct_user_count }} / {{ record.indirect_user_count }}
          </template>
        </template>
      </a-table>
    </div>

    <a-modal v-model:open="openVisible" title="开通分销员" @ok="submitOpen">
      <a-form class="pt-4" :label-col="{ style: { width: '100px' } }">
        <a-form-item label="用户ID">
          <a-input-number v-model:value="openForm.user_id" class="w-full" :min="1" :precision="0" />
        </a-form-item>
        <a-form-item label="等级">
          <a-select v-model:value="openForm.level_id">
            <a-select-option v-for="level in levels" :key="level.id" :value="level.id">
              {{ level.name }}
            </a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="备注">
          <a-textarea v-model:value="openForm.remark" :rows="3" allow-clear />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
