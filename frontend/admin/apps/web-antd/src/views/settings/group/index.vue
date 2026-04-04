<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import { h, onMounted, ref } from 'vue';

import { IconifyIcon } from '@vben/icons';

import { message, Modal, Switch } from 'ant-design-vue';

import {
  deleteSettingGroupApi,
  getSettingGroupTreeApi,
  updateSettingGroupApi,
} from '#/api/setting';

import GroupModal from './group-modal.vue';

defineOptions({ name: 'SettingGroup' });

/* ---------------- 数据加载 ---------------- */

const tableData = ref<SettingApi.SettingGroup[]>([]);
const loading = ref(false);

const loadData = async () => {
  loading.value = true;
  try {
    tableData.value = await getSettingGroupTreeApi(searchParams.value);
  } catch (error) {
    console.error('加载分组数据失败:', error);
    message.error('加载分组数据失败');
  } finally {
    loading.value = false;
  }
};

/* ---------------- 搜索参数 ---------------- */

const searchParams = ref({
  keyword: '',
  status: undefined as number | undefined,
});

const resetSearch = () => {
  searchParams.value = { keyword: '', status: undefined };
  loadData();
};

/* ---------------- 弹窗 ---------------- */

const groupModalVisible = ref(false);
const editingGroup = ref<null | SettingApi.SettingGroup>(null);

const handleCreate = () => {
  editingGroup.value = null;
  groupModalVisible.value = true;
};

const handleEdit = (record: SettingApi.SettingGroup) => {
  editingGroup.value = record;
  groupModalVisible.value = true;
};

const onModalSuccess = () => {
  loadData();
};

/* ---------------- 删除 ---------------- */

const handleDelete = (record: SettingApi.SettingGroup) => {
  const childCount = record.children?.length || 0;
  const extra =
    childCount > 0 ? `（包含 ${childCount} 个子分组，将一并删除）` : '';
  Modal.confirm({
    content: `确定要删除分组「${record.name}」吗？${extra}`,
    okType: 'danger',
    title: '删除分组',
    async onOk() {
      await deleteSettingGroupApi(record.id);
      message.success('删除成功');
      loadData();
    },
  });
};

/* ---------------- 表格列 ---------------- */

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '分组名称', dataIndex: 'name', width: 200 },
  { title: '分组编码', dataIndex: 'code', width: 180 },
  { title: '图标', dataIndex: 'icon', width: 60 },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 100,
    customRender: ({ record }: any) =>
      h(Switch, {
        checked: record.status === 1,
        'checked-children': '启用',
        'un-checked-children': '禁用',
        onChange: async (checked: any) => {
          await updateSettingGroupApi(record.id, {
            status: checked ? 1 : 0,
          });
          message.success('更新成功');
          loadData();
        },
      }),
  },
  { title: '创建时间', dataIndex: 'create_time', width: 180 },
  { title: '操作', key: 'action', width: 160, fixed: 'right' },
];

/* ---------------- 初始化 ---------------- */

onMounted(() => {
  loadData();
});
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate"> 新增分组 </a-button>
      <a-button class="ml-2" @click="loadData"> 刷新 </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4">
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="分组名称/编码"
          allow-clear
          style="width: 200px"
        />
      </a-form-item>
      <a-form-item label="状态">
        <a-select
          v-model:value="searchParams.status"
          placeholder="请选择"
          allow-clear
          style="width: 150px"
        >
          <a-select-option :value="1">启用</a-select-option>
          <a-select-option :value="0">禁用</a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item>
        <a-button type="primary" @click="loadData"> 搜索 </a-button>
        <a-button class="ml-2" @click="resetSearch"> 重置 </a-button>
      </a-form-item>
    </a-form>

    <!-- 树形表格 -->
    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="false"
      :default-expand-all-rows="true"
      row-key="id"
      :scroll="{ x: 1100 }"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.dataIndex === 'name'">
          <div class="flex items-center gap-1">
            <IconifyIcon
              v-if="record.icon"
              :icon="record.icon"
              class="text-base"
            />
            <span>{{ record.name }}</span>
          </div>
        </template>

        <template v-if="column.dataIndex === 'icon'">
          <IconifyIcon v-if="record.icon" :icon="record.icon" class="text-lg" />
          <span v-else class="text-gray-300">-</span>
        </template>

        <template v-if="column.key === 'action'">
          <a-space>
            <a-button type="link" size="small" @click="handleEdit(record)">
              编辑
            </a-button>
            <a-button
              type="link"
              danger
              size="small"
              @click="handleDelete(record)"
            >
              删除
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>

    <!-- 分组弹窗 -->
    <GroupModal
      v-model:visible="groupModalVisible"
      :edit-data="editingGroup"
      @success="onModalSuccess"
    />
  </div>
</template>
