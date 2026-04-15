<script lang="ts" setup>
import type { GoodsSpecTemplateApi } from '#/api/goods';

import { h, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Switch, Tag } from 'ant-design-vue';

import {
  deleteGoodsSpecTemplateApi,
  getGoodsSpecTemplateInfoApi,
  getGoodsSpecTemplateListApi,
  updateGoodsSpecTemplateStatusApi,
} from '#/api/goods';
import { useTableCrud } from '#/composables/useTableCrud';

import SpecTemplateModal from './spec-template-modal.vue';

defineOptions({ name: 'GoodsSpecTemplateManagement' });

const { hasAccessByCodes } = useAccess();

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  GoodsSpecTemplateApi.TemplateItem,
  GoodsSpecTemplateApi.ListParams
>(
  {
    delete: deleteGoodsSpecTemplateApi,
    list: getGoodsSpecTemplateListApi,
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
const templateModalVisible = ref(false);
const editingItem = ref<GoodsSpecTemplateApi.TemplateItem | null>(null);

const handleCreate = () => {
  editingItem.value = null;
  templateModalVisible.value = true;
};

const handleEdit = async (record: GoodsSpecTemplateApi.TemplateItem) => {
  try {
    const detail = await getGoodsSpecTemplateInfoApi(record.id);
    editingItem.value = detail;
    templateModalVisible.value = true;
  } catch (error) {
    console.error('获取模板详情失败:', error);
    message.error('获取模板详情失败');
  }
};

const onModalSuccess = () => {
  loadData(searchParams.value);
};

/* ---------------- 状态切换 ---------------- */
const handleStatusChange = async (
  record: GoodsSpecTemplateApi.TemplateItem,
  checked: boolean,
) => {
  try {
    await updateGoodsSpecTemplateStatusApi(record.id, checked ? 1 : 0);
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
  { title: '模板名称', dataIndex: 'name', width: 180 },
  {
    title: '规格数',
    dataIndex: 'detail',
    width: 90,
    customRender: ({
      record,
    }: {
      record: GoodsSpecTemplateApi.TemplateItem;
    }) => {
      const count = Array.isArray(record.detail) ? record.detail.length : 0;
      return h(Tag, { color: count > 0 ? 'blue' : 'default' }, () =>
        String(count),
      );
    },
  },
  {
    title: '规格预览',
    dataIndex: 'detail',
    key: 'detail_preview',
    ellipsis: true,
    customRender: ({
      record,
    }: {
      record: GoodsSpecTemplateApi.TemplateItem;
    }) => {
      if (!Array.isArray(record.detail) || record.detail.length === 0) {
        return '-';
      }
      return h(
        'div',
        { style: 'display: flex; flex-wrap: wrap; gap: 4px;' },
        record.detail.map((item) =>
          h(
            Tag,
            { color: 'geekblue', style: 'margin: 0' },
            () => `${item.spec_name}（${item.values.length}个值）`,
          ),
        ),
      );
    },
  },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({
      record,
    }: {
      record: GoodsSpecTemplateApi.TemplateItem;
    }) => {
      if (!hasAccessByCodes(['SystemGoodsSpecTemplateUpdateStatus'])) {
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
  { title: '操作', key: 'action', width: 160, fixed: 'right' },
];

/* ---------------- 初始化 ---------------- */
onMounted(() => {
  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate" v-access:code="'SystemGoodsSpecTemplateCreate'"> 新增模板 </a-button>
      <a-button class="ml-2" @click="() => loadData(searchParams)">
        刷新
      </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4">
      <a-form-item label="模板名称">
        <a-input
          v-model:value="searchParams.name"
          placeholder="请输入模板名称"
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
      :scroll="{ x: 1100 }"
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
            <a-button type="link" size="small" @click="handleEdit(record)" v-access:code="'SystemGoodsSpecTemplateUpdate'">
              编辑
            </a-button>
            <a-button
              type="link"
              danger
              size="small"
              @click="handleDelete(record, 'name')"
              v-access:code="'SystemGoodsSpecTemplateDelete'"
            >
              删除
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>

    <!-- 规格模板表单弹窗 -->
    <SpecTemplateModal
      v-model:visible="templateModalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />
  </div>
</template>
