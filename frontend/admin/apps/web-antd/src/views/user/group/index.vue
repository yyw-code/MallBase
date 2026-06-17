<script lang="ts" setup>
import type { UserGroupApi } from '#/api/user';

import { h, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Modal, Switch, Tag } from 'ant-design-vue';

import {
  deleteUserGroupApi,
  getUserGroupCountApi,
  getUserGroupInfoApi,
  getUserGroupListApi,
  updateUserGroupStatusApi,
} from '#/api/user';
import { useColorMap } from '#/composables/useColorOptions';
import { useTableCrud } from '#/composables/useTableCrud';

import GroupModal from './group-modal.vue';

defineOptions({ name: 'UserGroupManagement' });

const { hasAccessByCodes } = useAccess();

// ==================== 颜色映射 ====================
const colorMap = useColorMap();

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  UserGroupApi.GroupItem,
  UserGroupApi.ListParams
>(
  {
    delete: deleteUserGroupApi,
    list: getUserGroupListApi,
  },
  { immediateLoad: false },
);

/* ---------------- 搜索参数 ---------------- */
const searchParams = ref({
  name: '',
  code: '',
  status: undefined as number | undefined,
});

const resetSearch = () => {
  searchParams.value = {
    name: '',
    code: '',
    status: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

/* ---------------- 弹窗 ---------------- */
const groupModalVisible = ref(false);
const editingItem = ref<null | UserGroupApi.GroupItem>(null);

const handleCreate = () => {
  editingItem.value = null;
  groupModalVisible.value = true;
};

const handleEdit = async (record: UserGroupApi.GroupItem) => {
  try {
    const detail = await getUserGroupInfoApi(record.id);
    editingItem.value = detail;
    groupModalVisible.value = true;
  } catch (error) {
    console.error('获取分组详情失败:', error);
    message.error('获取分组详情失败');
  }
};

const onModalSuccess = () => {
  loadData(searchParams.value);
};

/* ---------------- 状态切换 ---------------- */
const handleStatusChange = async (
  record: UserGroupApi.GroupItem,
  checked: boolean,
) => {
  try {
    await updateUserGroupStatusApi(record.id, checked ? 1 : 0);
    message.success('状态更新成功');
    await loadData(searchParams.value);
  } catch {
    // 失败后刷新列表恢复状态
    await loadData(searchParams.value);
  }
};

/* ---------------- 查看用户数 ---------------- */
const handleViewUsers = async (record: UserGroupApi.GroupItem) => {
  try {
    const result = await getUserGroupCountApi(record.id);
    Modal.info({
      title: '分组用户数',
      content: `「${record.name}」分组下共有 ${result.count} 个用户`,
    });
  } catch (error) {
    console.error('获取用户数失败:', error);
    message.error('获取用户数失败');
  }
};

/* ---------------- 表格列 ---------------- */
const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '分组名称', dataIndex: 'name', width: 150 },
  {
    title: '分组编码',
    dataIndex: 'code',
    width: 150,
    customRender: ({ record }: { record: UserGroupApi.GroupItem }) => {
      return h('span', { class: 'font-mono text-sm' }, record.code);
    },
  },
  {
    title: '显示颜色',
    dataIndex: 'color',
    width: 120,
    customRender: ({ record }: { record: UserGroupApi.GroupItem }) => {
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
    customRender: ({ record }: { record: UserGroupApi.GroupItem }) => {
      if (!hasAccessByCodes(['SystemUserGroupUpdateStatus'])) {
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
  { title: '描述', dataIndex: 'description', ellipsis: true },
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
      <h2 class="m-0 text-lg font-semibold">用户分组</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SystemUserGroupCreate'"
        >
          新增分组
        </a-button>
        <a-button @click="() => loadData(searchParams)"> 刷新 </a-button>
      </div>
    </div>

    <!-- 搜索表单 -->
    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item label="分组名称" class="mb-0">
          <a-input
            v-model:value="searchParams.name"
            placeholder="请输入分组名称"
            allow-clear
            class="w-full"
          />
        </a-form-item>
        <a-form-item label="分组编码" class="mb-0">
          <a-input
            v-model:value="searchParams.code"
            placeholder="请输入分组编码"
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
              <a-button
                type="link"
                size="small"
                @click="handleEdit(record)"
                v-access:code="'SystemUserGroupUpdate'"
              >
                编辑
              </a-button>
              <a-button
                type="link"
                size="small"
                @click="handleViewUsers(record)"
                v-access:code="'SystemUserGroupGetUserGroups'"
              >
                查看用户
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                @click="handleDelete(record, 'name', 'code')"
                v-access:code="'SystemUserGroupDelete'"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <!-- 分组表单弹窗 -->
    <GroupModal
      v-model:visible="groupModalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />
  </div>
</template>
