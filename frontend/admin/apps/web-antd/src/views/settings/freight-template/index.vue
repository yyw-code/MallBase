<script lang="ts" setup>
import type { FreightTemplateApi } from '#/api/setting/freight-template';

import { h, ref } from 'vue';

import { message, Switch, Tag } from 'ant-design-vue';

import {
  deleteFreightTemplateApi,
  getFreightTemplateInfoApi,
  getFreightTemplateListApi,
  updateFreightTemplateStatusApi,
} from '#/api/setting/freight-template';
import { useTableCrud } from '#/composables/useTableCrud';

import FreightTemplateModal from './freight-template-modal.vue';

defineOptions({ name: 'FreightTemplateManagement' });

const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  FreightTemplateApi.TemplateItem,
  FreightTemplateApi.ListParams
>(
  { delete: deleteFreightTemplateApi, list: getFreightTemplateListApi },
  { immediateLoad: false },
);

const searchParams = ref({
  name: '',
  status: undefined as number | undefined,
});

const getDefaultSearchParams = () => ({
  name: '',
  status: undefined as number | undefined,
});
const modalVisible = ref(false);
const editingItem = ref<FreightTemplateApi.TemplateItem | null>(null);

const handleCreate = () => {
  editingItem.value = null;
  modalVisible.value = true;
};

const handleEdit = async (record: FreightTemplateApi.TemplateItem) => {
  editingItem.value = await getFreightTemplateInfoApi(record.id);
  modalVisible.value = true;
};

const handleStatusChange = async (
  record: FreightTemplateApi.TemplateItem,
  checked: boolean,
) => {
  await updateFreightTemplateStatusApi(record.id, checked ? 1 : 0);
  message.success('状态更新成功');
  await loadData(searchParams.value);
};

async function handleSearch() {
  pagination.current = 1;
  await loadData(searchParams.value);
}

async function handleReset() {
  searchParams.value = getDefaultSearchParams();
  pagination.current = 1;
  await loadData(searchParams.value);
}

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '模板名称', dataIndex: 'name', width: 180 },
  {
    title: '计费方式',
    dataIndex: 'charge_type',
    width: 100,
    customRender: ({ record }: { record: FreightTemplateApi.TemplateItem }) =>
      h(Tag, { color: 'blue' }, () =>
        record.charge_type === 'weight' ? '按重' : '按件',
      ),
  },
  { title: '规则数', dataIndex: 'rule_count', width: 90 },
  {
    title: '失效规则',
    dataIndex: 'invalid_rule_count',
    width: 100,
    customRender: ({ record }: { record: FreightTemplateApi.TemplateItem }) =>
      h(
        Tag,
        { color: (record.invalid_rule_count || 0) > 0 ? 'error' : 'success' },
        () => String(record.invalid_rule_count || 0),
      ),
  },
  {
    title: '状态',
    width: 90,
    customRender: ({ record }: { record: FreightTemplateApi.TemplateItem }) =>
      h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: (checked: any) =>
          handleStatusChange(record, Boolean(checked)),
      }),
  },
  { title: '创建时间', dataIndex: 'create_time', width: 160 },
  { title: '操作', key: 'action', width: 160, fixed: 'right' },
];
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate">新增模板</a-button>
      <a-button class="ml-2" @click="() => loadData(searchParams)">
        刷新
      </a-button>
    </div>
    <a-form layout="inline" class="mb-4">
      <a-form-item label="模板名称">
        <a-input
          v-model:value="searchParams.name"
          placeholder="请输入模板名称"
          allow-clear
        />
      </a-form-item>
      <a-form-item label="状态">
        <a-select
          v-model:value="searchParams.status"
          allow-clear
          style="width: 120px"
        >
          <a-select-option :value="1">启用</a-select-option>
          <a-select-option :value="0">禁用</a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item>
        <a-space>
          <a-button type="primary" @click="handleSearch">搜索</a-button>
          <a-button @click="handleReset">重置</a-button>
        </a-space>
      </a-form-item>
    </a-form>
    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      row-key="id"
      :scroll="{ x: 960 }"
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
              @click="handleDelete(record, 'name')"
            >
              删除
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>
    <FreightTemplateModal
      v-model:visible="modalVisible"
      :edit-data="editingItem"
      @success="() => loadData(searchParams)"
    />
  </div>
</template>
