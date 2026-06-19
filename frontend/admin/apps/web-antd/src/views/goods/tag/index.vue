<script lang="ts" setup>
import type { GoodsTagApi } from '#/api/goods';

import { h, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Switch, Tag } from 'ant-design-vue';

import {
  deleteGoodsTagApi,
  getGoodsTagInfoApi,
  getGoodsTagListApi,
  updateGoodsTagStatusApi,
} from '#/api/goods';
import { useColorMap } from '#/composables/useColorOptions';
import { useTableCrud } from '#/composables/useTableCrud';

import TagModal from './tag-modal.vue';

defineOptions({ name: 'GoodsTagManagement' });

const { hasAccessByCodes } = useAccess();

// ==================== 颜色映射 ====================
const colorMap = useColorMap();

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  GoodsTagApi.TagItem,
  GoodsTagApi.ListParams
>(
  {
    delete: deleteGoodsTagApi,
    list: getGoodsTagListApi,
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
const tagModalVisible = ref(false);
const editingItem = ref<GoodsTagApi.TagItem | null>(null);

const handleCreate = () => {
  editingItem.value = null;
  tagModalVisible.value = true;
};

const handleEdit = async (record: GoodsTagApi.TagItem) => {
  try {
    const detail = await getGoodsTagInfoApi(record.id);
    editingItem.value = detail;
    tagModalVisible.value = true;
  } catch (error) {
    console.error('获取标签详情失败:', error);
    message.error('获取标签详情失败');
  }
};

const onModalSuccess = () => {
  loadData(searchParams.value);
};

/* ---------------- 状态切换 ---------------- */
const handleStatusChange = async (
  record: GoodsTagApi.TagItem,
  checked: boolean,
) => {
  try {
    await updateGoodsTagStatusApi(record.id, checked ? 1 : 0);
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
  { title: '标签名称', dataIndex: 'name', width: 150 },
  {
    title: '显示颜色',
    dataIndex: 'color',
    width: 120,
    customRender: ({ record }: { record: GoodsTagApi.TagItem }) => {
      if (!record.color) return '-';
      const config = colorMap.value[record.color] || {
        label: record.color,
        color: record.color,
      };
      return h(Tag, { color: config.color }, () => config.label);
    },
  },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: { record: GoodsTagApi.TagItem }) => {
      if (!hasAccessByCodes(['SystemGoodsTagUpdateStatus'])) {
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
      <h2 class="m-0 text-lg font-semibold">商品标签</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SystemGoodsTagCreate'"
        >
          新增标签
        </a-button>
        <a-button @click="() => loadData(searchParams)"> 刷新 </a-button>
      </div>
    </div>

    <!-- 搜索表单 -->
    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item label="标签名称" class="mb-0">
          <a-input
            v-model:value="searchParams.name"
            placeholder="请输入标签名称"
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
              <a-button
                type="link"
                size="small"
                @click="handleEdit(record)"
                v-access:code="'SystemGoodsTagUpdate'"
              >
                编辑
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                @click="handleDelete(record, 'name')"
                v-access:code="'SystemGoodsTagDelete'"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <!-- 标签表单弹窗 -->
    <TagModal
      v-model:visible="tagModalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />
  </div>
</template>
