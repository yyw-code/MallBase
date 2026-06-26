<script lang="ts" setup>
import type { GoodsCategoryApi } from '#/api/goods';

import { h, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Modal, Switch } from 'ant-design-vue';

import {
  deleteGoodsCategoryApi,
  getGoodsCategoryInfoApi,
  getGoodsCategoryTreeApi,
  updateGoodsCategoryStatusApi,
} from '#/api/goods';

import CategoryModal from './category-modal.vue';

defineOptions({ name: 'GoodsCategoryManagement' });

const { hasAccessByCodes } = useAccess();

/* ---------------- 表格数据 ---------------- */
const tableData = ref<GoodsCategoryApi.CategoryItem[]>([]);
const loading = ref(false);

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
  loadData(searchParams.value);
};

const loadData = async (
  params: Pick<GoodsCategoryApi.ListParams, 'name' | 'status'> = {},
) => {
  loading.value = true;
  try {
    tableData.value = await getGoodsCategoryTreeApi(params);
  } catch (error) {
    console.error('加载分类树失败:', error);
    message.error('加载分类树失败');
  } finally {
    loading.value = false;
  }
};

const handleDelete = (record: GoodsCategoryApi.CategoryItem) => {
  Modal.confirm({
    content: `确定要删除"${record.name || '该分类'}"吗？`,
    onOk: async () => {
      await deleteGoodsCategoryApi(record.id);
      message.success('删除成功');
      await loadData(searchParams.value);
    },
  });
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
    title: '分类图片',
    dataIndex: 'image',
    width: 60,
    customRender: ({ record }: { record: GoodsCategoryApi.CategoryItem }) => {
      const imageUrl = record.image_full_url || record.image;
      if (!imageUrl) return '-';
      return h('div', { class: 'category-image-wrap' }, [
        h('img', {
          src: imageUrl,
          alt: record.name,
          class: 'category-image',
        }),
      ]);
    },
  },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: { record: GoodsCategoryApi.CategoryItem }) => {
      if (!hasAccessByCodes(['SystemGoodsCategoryUpdateStatus'])) {
        return record.status === 1 ? '启用' : '禁用';
      }
      return h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: (checked) => handleStatusChange(record, checked === true),
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
      <h2 class="m-0 text-lg font-semibold">商品分类</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SystemGoodsCategoryCreate'"
        >
          新增分类
        </a-button>
        <a-button @click="() => loadData(searchParams)"> 刷新 </a-button>
      </div>
    </div>

    <!-- 搜索表单 -->
    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item label="分类名称" class="mb-0">
          <a-input
            v-model:value="searchParams.name"
            placeholder="请输入分类名称"
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
        :pagination="false"
        :scroll="{ x: 920 }"
        row-key="id"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'action'">
            <a-space>
              <a-button
                type="link"
                size="small"
                @click="handleEdit(record)"
                v-access:code="'SystemGoodsCategoryUpdate'"
              >
                编辑
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                @click="handleDelete(record)"
                v-access:code="'SystemGoodsCategoryDelete'"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <!-- 分类表单弹窗 -->
    <CategoryModal
      v-model:visible="categoryModalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />
  </div>
</template>

<style scoped></style>
