<script lang="ts" setup>
import type { ArticleCategoryApi } from '#/api/content';

import { h, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Switch } from 'ant-design-vue';

import {
  deleteArticleCategoryApi,
  getArticleCategoryInfoApi,
  getArticleCategoryListApi,
  updateArticleCategoryStatusApi,
} from '#/api/content';
import { useTableCrud } from '#/composables/useTableCrud';

import CategoryModal from './category-modal.vue';

defineOptions({ name: 'ArticleCategoryManagement' });

const { hasAccessByCodes } = useAccess();

const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  ArticleCategoryApi.CategoryItem,
  ArticleCategoryApi.ListParams
>(
  {
    delete: deleteArticleCategoryApi,
    list: getArticleCategoryListApi,
  },
  { immediateLoad: false },
);

const searchParams = ref({
  keyword: '',
  status: undefined as number | undefined,
});

const categoryModalVisible = ref(false);
const editingItem = ref<ArticleCategoryApi.CategoryItem | null>(null);

const submitSearch = () => {
  pagination.current = 1;
  loadData(searchParams.value);
};

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    status: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

const handleCreate = () => {
  editingItem.value = null;
  categoryModalVisible.value = true;
};

const handleEdit = async (record: ArticleCategoryApi.CategoryItem) => {
  try {
    editingItem.value = await getArticleCategoryInfoApi(record.id);
    categoryModalVisible.value = true;
  } catch (error: any) {
    message.error(error?.message || '获取分类详情失败');
  }
};

const handleStatusChange = async (
  record: ArticleCategoryApi.CategoryItem,
  checked: boolean | number | string,
) => {
  try {
    await updateArticleCategoryStatusApi(record.id, checked === true ? 1 : 0);
    message.success('状态更新成功');
    await loadData(searchParams.value);
  } catch (error: any) {
    message.error(error?.message || '状态更新失败');
    await loadData(searchParams.value);
  }
};

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '分类名称', dataIndex: 'name', width: 180 },
  { title: '描述', dataIndex: 'description', ellipsis: true },
  { title: '排序', dataIndex: 'sort', width: 90 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 100,
    customRender: ({ record }: { record: ArticleCategoryApi.CategoryItem }) => {
      if (!hasAccessByCodes(['SystemArticleCategoryUpdateStatus'])) {
        return record.status === 1 ? '启用' : '禁用';
      }
      return h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: (checked: boolean | number | string) =>
          handleStatusChange(record, checked),
      });
    },
  },
  { title: '创建时间', dataIndex: 'create_time', width: 170 },
  { title: '操作', key: 'action', width: 180 },
];

const handleTableChange = (newPagination: {
  current?: number;
  pageSize?: number;
}) => {
  pagination.current = newPagination.current ?? pagination.current;
  pagination.pageSize = newPagination.pageSize ?? pagination.pageSize;
  loadData(searchParams.value);
};

onMounted(() => {
  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">文章分类</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SystemArticleCategoryCreate'"
        >
          新增分类
        </a-button>
        <a-button @click="() => loadData(searchParams)">刷新</a-button>
      </div>
    </div>

    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3">
        <a-form-item class="mb-0" label="关键词">
          <a-input
            v-model:value="searchParams.keyword"
            allow-clear
            class="w-full"
            placeholder="分类名称 / 描述"
            @press-enter="submitSearch"
          />
        </a-form-item>
        <a-form-item class="mb-0" label="状态">
          <a-select
            v-model:value="searchParams.status"
            allow-clear
            class="w-full"
            placeholder="请选择"
          >
            <a-select-option :value="1">启用</a-select-option>
            <a-select-option :value="0">禁用</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item class="mb-0">
          <div class="flex justify-end gap-2">
            <a-button type="primary" @click="submitSearch">搜索</a-button>
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
        row-key="id"
        :scroll="{ x: 900 }"
        @change="handleTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'action'">
            <a-space>
              <a-button
                size="small"
                type="link"
                @click="handleEdit(record)"
                v-access:code="'SystemArticleCategoryUpdate'"
              >
                编辑
              </a-button>
              <a-button
                danger
                size="small"
                type="link"
                @click="handleDelete(record, 'name')"
                v-access:code="'SystemArticleCategoryDelete'"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <CategoryModal
      v-model:visible="categoryModalVisible"
      :edit-data="editingItem"
      @success="loadData(searchParams)"
    />
  </div>
</template>
