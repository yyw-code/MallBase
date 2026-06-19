<script lang="ts" setup>
import type { RegionApi } from '#/api/region';

import { h, onMounted, ref } from 'vue';

import { message, Modal, Switch, Tag } from 'ant-design-vue';

import {
  deleteRegionApi,
  getRegionInfoApi,
  getRegionListApi,
  updateRegionStatusApi,
} from '#/api/region';
import { useTableCrud } from '#/composables/useTableCrud';

import RegionModal from './region-modal.vue';

defineOptions({ name: 'RegionManagement' });

const { tableData, loading, pagination, loadData } = useTableCrud<
  RegionApi.RegionItem,
  { keyword?: string; level?: number; status?: number }
>(
  { delete: deleteRegionApi, list: getRegionListApi },
  { immediateLoad: false },
);

const searchParams = ref({
  keyword: '',
  level: undefined as number | undefined,
  status: undefined as number | undefined,
});

const getDefaultSearchParams = () => ({
  keyword: '',
  level: undefined as number | undefined,
  status: undefined as number | undefined,
});
const modalVisible = ref(false);
const editingItem = ref<
  null | (RegionApi.RegionItem & { path?: RegionApi.RegionItem[] })
>(null);

const handleCreate = () => {
  editingItem.value = null;
  modalVisible.value = true;
};

const handleEdit = async (record: RegionApi.RegionItem) => {
  editingItem.value = await getRegionInfoApi(record.id);
  modalVisible.value = true;
};

const handleStatusChange = async (
  record: RegionApi.RegionItem,
  checked: boolean,
) => {
  await updateRegionStatusApi(record.id, checked ? 1 : 0);
  message.success('状态更新成功');
  await loadData(searchParams.value);
};

const handleDeleteRegion = (record: RegionApi.RegionItem) => {
  Modal.confirm({
    content:
      `确定要删除地区“${record.name}”吗？这会一并删除该地区及全部子级，` +
      '相关收货地址和运费规则不会被删除，但会标记为失效。',
    onOk: async () => {
      await deleteRegionApi(record.id);
      message.success('删除成功');
      await loadData(searchParams.value);
    },
  });
};

onMounted(async () => {
  await loadData(searchParams.value);
});

async function handleSearch() {
  pagination.current = 1;
  await loadData(searchParams.value);
}

async function handleReset() {
  searchParams.value = getDefaultSearchParams();
  pagination.current = 1;
  await loadData(searchParams.value);
}

const levelMap: Record<number, string> = {
  1: '省',
  2: '市',
  3: '区县',
  4: '街道',
};
const columns = [
  { title: 'ID', dataIndex: 'id', width: 90 },
  { title: '名称', dataIndex: 'name', width: 180 },
  { title: '编码', dataIndex: 'code', width: 140 },
  {
    title: '层级',
    dataIndex: 'level',
    width: 90,
    customRender: ({ record }: { record: RegionApi.RegionItem }) =>
      h(
        Tag,
        { color: 'blue' },
        () => levelMap[record.level] || String(record.level),
      ),
  },
  { title: '父级ID', dataIndex: 'parent_id', width: 100 },
  { title: '路径编码', dataIndex: 'path_codes', ellipsis: true },
  {
    title: '状态',
    width: 90,
    customRender: ({ record }: { record: RegionApi.RegionItem }) =>
      h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: (checked: any) =>
          handleStatusChange(record, Boolean(checked)),
      }),
  },
  { title: '操作', key: 'action', width: 160, fixed: 'right' },
];
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">地区管理</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button type="primary" @click="handleCreate">新增地区</a-button>
        <a-button @click="() => loadData(searchParams)"> 刷新 </a-button>
      </div>
    </div>
    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item label="关键词" class="mb-0">
          <a-input
            class="w-full"
            v-model:value="searchParams.keyword"
            allow-clear
            placeholder="名称/编码"
          />
        </a-form-item>
        <a-form-item label="层级" class="mb-0">
          <a-select
            v-model:value="searchParams.level"
            allow-clear
            class="w-full"
          >
            <a-select-option :value="1"> 省 </a-select-option>
            <a-select-option :value="2"> 市 </a-select-option>
            <a-select-option :value="3"> 区县 </a-select-option>
            <a-select-option :value="4"> 街道 </a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="状态" class="mb-0">
          <a-select
            v-model:value="searchParams.status"
            allow-clear
            class="w-full"
          >
            <a-select-option :value="1"> 启用 </a-select-option>
            <a-select-option :value="0"> 禁用 </a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item class="mb-0 md:col-span-3 xl:col-span-6">
          <div class="flex justify-end gap-2">
            <a-space>
              <a-button type="primary" @click="handleSearch">搜索</a-button>
              <a-button @click="handleReset">重置</a-button>
            </a-space>
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
        :scroll="{ x: 1200 }"
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
          <template v-if="column.key === 'action'">
            <a-space>
              <a-button type="link" size="small" @click="handleEdit(record)">
                编辑
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                @click="handleDeleteRegion(record)"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>
    <RegionModal
      v-model:visible="modalVisible"
      :edit-data="editingItem"
      @success="() => loadData(searchParams)"
    />
  </div>
</template>
