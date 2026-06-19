<script lang="ts" setup>
import type { GoodsBrandApi } from '#/api/goods';

import { h, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { Avatar, message, Switch } from 'ant-design-vue';

import {
  deleteGoodsBrandApi,
  getGoodsBrandInfoApi,
  getGoodsBrandListApi,
  updateGoodsBrandStatusApi,
} from '#/api/goods';
import { useTableCrud } from '#/composables/useTableCrud';

import BrandModal from './brand-modal.vue';

defineOptions({ name: 'GoodsBrandManagement' });

const { hasAccessByCodes } = useAccess();

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  GoodsBrandApi.BrandItem,
  GoodsBrandApi.ListParams
>(
  {
    delete: deleteGoodsBrandApi,
    list: getGoodsBrandListApi,
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
const brandModalVisible = ref(false);
const editingItem = ref<GoodsBrandApi.BrandItem | null>(null);

const handleCreate = () => {
  editingItem.value = null;
  brandModalVisible.value = true;
};

const handleEdit = async (record: GoodsBrandApi.BrandItem) => {
  try {
    const detail = await getGoodsBrandInfoApi(record.id);
    editingItem.value = detail;
    brandModalVisible.value = true;
  } catch (error) {
    console.error('获取品牌详情失败:', error);
    message.error('获取品牌详情失败');
  }
};

const onModalSuccess = () => {
  loadData(searchParams.value);
};

/* ---------------- 状态切换 ---------------- */
const handleStatusChange = async (
  record: GoodsBrandApi.BrandItem,
  checked: boolean,
) => {
  try {
    await updateGoodsBrandStatusApi(record.id, checked ? 1 : 0);
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
  {
    title: 'LOGO',
    dataIndex: 'logo',
    width: 80,
    customRender: ({ record }: { record: GoodsBrandApi.BrandItem }) => {
      if (!record.logo) return '-';
      return h(Avatar, {
        src: record.logo_full_url || record.logo,
        size: 32,
        shape: 'square',
      });
    },
  },
  { title: '品牌名称', dataIndex: 'name', width: 150 },
  { title: '描述', dataIndex: 'description', ellipsis: true },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: { record: GoodsBrandApi.BrandItem }) => {
      if (!hasAccessByCodes(['SystemGoodsBrandUpdateStatus'])) {
        return record.status === 1 ? '启用' : '禁用';
      }
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
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">商品品牌</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SystemGoodsBrandCreate'"
        >
          新增品牌
        </a-button>
        <a-button @click="() => loadData(searchParams)"> 刷新 </a-button>
      </div>
    </div>

    <!-- 搜索表单 -->
    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item label="品牌名称" class="mb-0">
          <a-input
            v-model:value="searchParams.name"
            placeholder="请输入品牌名称"
            allow-clear
            class="w-full"
          />
        </a-form-item>
        <a-form-item label="状态" class="mb-0">
          <a-select
            v-model:value="searchParams.status"
            placeholder="请选择"
            allow-clear
            class="w-full"
          >
            <a-select-option :value="1"> 启用 </a-select-option>
            <a-select-option :value="0"> 禁用 </a-select-option>
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
            <a-button @click="resetSearch"> 重置 </a-button>
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
        :scroll="{ x: 1000 }"
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
              <a-button
                type="link"
                size="small"
                @click="handleEdit(record)"
                v-access:code="'SystemGoodsBrandUpdate'"
              >
                编辑
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                @click="handleDelete(record, 'name')"
                v-access:code="'SystemGoodsBrandDelete'"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <!-- 品牌表单弹窗 -->
    <BrandModal
      v-model:visible="brandModalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />
  </div>
</template>
