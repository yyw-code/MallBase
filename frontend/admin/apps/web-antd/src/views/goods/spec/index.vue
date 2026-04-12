<script lang="ts" setup>
import type { GoodsSpecApi } from '#/api/goods';

import { h, onMounted, ref } from 'vue';

import { message, Switch, Tag } from 'ant-design-vue';

import {
  deleteGoodsSpecApi,
  getGoodsSpecInfoApi,
  getGoodsSpecListApi,
  updateGoodsSpecStatusApi,
} from '#/api/goods';
import { useTableCrud } from '#/composables/useTableCrud';

import SpecModal from './spec-modal.vue';
import SpecValueModal from './spec-value-modal.vue';

defineOptions({ name: 'GoodsSpecManagement' });

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  GoodsSpecApi.SpecItem,
  GoodsSpecApi.ListParams
>(
  {
    delete: deleteGoodsSpecApi,
    list: getGoodsSpecListApi,
  },
  { immediateLoad: false },
);

/* ---------------- 搜索参数 ---------------- */
const searchParams = ref({
  name: '',
  status: undefined as number | undefined,
});

const resetSearch = () => {
  searchParams.value = {
    name: '',
    status: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

/* ---------------- 规格组弹窗 ---------------- */
const specModalVisible = ref(false);
const editingItem = ref<GoodsSpecApi.SpecItem | null>(null);

const handleCreate = () => {
  editingItem.value = null;
  specModalVisible.value = true;
};

const handleEdit = async (record: GoodsSpecApi.SpecItem) => {
  try {
    const detail = await getGoodsSpecInfoApi(record.id);
    editingItem.value = detail;
    specModalVisible.value = true;
  } catch (error) {
    console.error('获取规格详情失败:', error);
    message.error('获取规格详情失败');
  }
};

const onModalSuccess = () => {
  loadData(searchParams.value);
};

/* ---------------- 规格值管理弹窗 ---------------- */
const specValueModalVisible = ref(false);
const editingSpec = ref<GoodsSpecApi.SpecItem | null>(null);

const handleManageValues = (record: GoodsSpecApi.SpecItem) => {
  editingSpec.value = record;
  specValueModalVisible.value = true;
};

const onSpecValueModalSuccess = () => {
  loadData(searchParams.value);
};

const getSpecValues = (record: GoodsSpecApi.SpecItem) => {
  return record.spec_values || record.specValues || [];
};

/* ---------------- 状态切换 ---------------- */
const handleStatusChange = async (
  record: GoodsSpecApi.SpecItem,
  checked: boolean,
) => {
  try {
    await updateGoodsSpecStatusApi(record.id, checked ? 1 : 0);
    message.success('状态更新成功');
    await loadData(searchParams.value);
  } catch {
    // 失败后刷新列表恢复状态
    await loadData(searchParams.value);
  }
};

/* ---------------- 表格列 ---------------- */
const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '规格名称', dataIndex: 'name', width: 150 },
  { title: '描述', dataIndex: 'description', ellipsis: true },
  {
    title: '规格值',
    dataIndex: 'spec_values',
    width: 320,
    customRender: ({ record }: { record: GoodsSpecApi.SpecItem }) => {
      const values = getSpecValues(record);
      if (values.length === 0) return '-';
      return h(
        'div',
        { style: 'display: flex; flex-wrap: wrap; gap: 4px;' },
        values.map((item: GoodsSpecApi.SpecValueItem) =>
          h(Tag, { color: 'blue' }, () => item.value),
        ),
      );
    },
  },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: { record: GoodsSpecApi.SpecItem }) => {
      return h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: (checked: boolean) => handleStatusChange(record, checked),
      });
    },
  },
  { title: '创建时间', dataIndex: 'create_time', width: 160 },
  { title: '操作', key: 'action', width: 280 },
];

/* ---------------- 初始化 ---------------- */
onMounted(() => {
  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate"> 新增规格 </a-button>
      <a-button class="ml-2" @click="() => loadData(searchParams)">
        刷新
      </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4">
      <a-form-item label="规格名称">
        <a-input
          v-model:value="searchParams.name"
          placeholder="请输入规格名称"
          allow-clear
          style="width: 180px"
        />
      </a-form-item>
      <a-form-item label="状态">
        <a-select
          v-model:value="searchParams.status"
          placeholder="请选择"
          allow-clear
          style="width: 120px"
        >
          <a-select-option :value="1">启用</a-select-option>
          <a-select-option :value="0">禁用</a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item>
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
        <a-button class="ml-2" @click="resetSearch"> 重置 </a-button>
      </a-form-item>
    </a-form>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1200 }"
      row-key="id"
      @change="
        (newPagination) => {
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
              size="small"
              @click="handleManageValues(record)"
            >
              管理规格值
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

    <!-- 规格组弹窗 -->
    <SpecModal
      v-model:visible="specModalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />

    <!-- 规格值管理弹窗 -->
    <SpecValueModal
      v-model:visible="specValueModalVisible"
      :spec-data="editingSpec"
      @success="onSpecValueModalSuccess"
    />
  </div>
</template>
