<script lang="ts" setup>
import type { GoodsCategoryApi } from '#/api/goods';

import { h, onMounted, ref } from 'vue';

import { Avatar, message, Switch } from 'ant-design-vue';

import {
  deleteGoodsCategoryApi,
  getGoodsCategoryInfoApi,
  getGoodsCategoryListApi,
  updateGoodsCategoryStatusApi,
} from '#/api/goods';
import { useTableCrud } from '#/composables/useTableCrud';

import CategoryModal from './category-modal.vue';

defineOptions({ name: 'GoodsCategoryManagement' });

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  GoodsCategoryApi.CategoryItem,
  GoodsCategoryApi.ListParams
>(
  {
    delete: deleteGoodsCategoryApi,
    list: getGoodsCategoryListApi,
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

/* ---------------- 弹窗 ---------------- */
const categoryModalVisible = ref(false);
const editingItem = ref<GoodsCategoryApi.CategoryItem | null>(null);

const handleCreate = () => {
  editingItem.value = null;
  categoryModalVisible.value = true;
};

const handleEdit = async (record: GoodsCategoryApi.CategoryItem) => {
  try {
    const detail = await getGoodsCategoryInfoApi(record.id);
    editingItem.value = detail;
    categoryModalVisible.value = true;
  } catch (error) {
    console.error('获取分类详情失败:', error);
    message.error('获取分类详情失败');
  }
};

const onModalSuccess = () => {
  loadData(searchParams.value);
};

/* ---------------- 状态切换 ---------------- */
const handleStatusChange = async (
  record: GoodsCategoryApi.CategoryItem,
  checked: boolean,
) => {
  try {
    await updateGoodsCategoryStatusApi(record.id, checked ? 1 : 0);
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
  { title: '分类名称', dataIndex: 'name', width: 150 },
  {
    title: '图标',
    dataIndex: 'icon',
    width: 80,
    customRender: ({ record }: { record: GoodsCategoryApi.CategoryItem }) => {
      if (!record.icon) return '-';
      return h(Avatar, { src: record.icon, size: 32 });
    },
  },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: { record: GoodsCategoryApi.CategoryItem }) => {
      return h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: (checked: boolean) => handleStatusChange(record, checked),
      });
    },
  },
  { title: '创建时间', dataIndex: 'create_time', width: 160 },
  { title: '操作', key: 'action', width: 200 },
];

/* ---------------- 初始化 ---------------- */
onMounted(() => {
  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate"> 新增分类 </a-button>
      <a-button class="ml-2" @click="() => loadData(searchParams)">
        刷新
      </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4">
      <a-form-item label="分类名称">
        <a-input
          v-model:value="searchParams.name"
          placeholder="请输入分类名称"
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
      :scroll="{ x: 900 }"
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

    <!-- 分类表单弹窗 -->
    <CategoryModal
      v-model:visible="categoryModalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />
  </div>
</template>
