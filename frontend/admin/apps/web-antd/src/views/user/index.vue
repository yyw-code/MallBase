<script lang="ts" setup>
import type { ClientUserApi, UserGroupApi, UserTagApi } from '#/api/user';

import { h, onMounted, ref } from 'vue';

import { message, Modal, Switch, Tag } from 'ant-design-vue';

import {
  deleteClientUserApi,
  getClientUserInfoApi,
  getClientUserListApi,
  getUserGroupListApi,
  getUserTagListApi,
  resetClientUserPasswordApi,
  updateClientUserStatusApi,
} from '#/api/user';
import { useColorMap } from '#/composables/useColorOptions';
import { useTableCrud } from '#/composables/useTableCrud';

import UserModal from './user-modal.vue';

defineOptions({ name: 'ClientUserManagement' });

// ==================== 性别映射 ====================
const GENDER_MAP: Record<number, { color: string; label: string }> = {
  0: { label: '未知', color: 'default' },
  1: { label: '男', color: 'blue' },
  2: { label: '女', color: 'pink' },
};

// ==================== 注册类型映射 ====================
const REGISTER_TYPE_MAP: Record<string, { color: string; label: string }> = {
  mobile: { label: '手机', color: 'cyan' },
  email: { label: '邮箱', color: 'purple' },
};

// ==================== 分组和标签选项 ====================
const groupOptions = ref<UserGroupApi.GroupItem[]>([]);
const tagOptions = ref<UserTagApi.TagItem[]>([]);
const colorMap = useColorMap();

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  ClientUserApi.UserItem,
  ClientUserApi.ListParams
>(
  {
    delete: deleteClientUserApi,
    list: getClientUserListApi,
  },
  { immediateLoad: false },
);

/* ---------------- 搜索参数 ---------------- */
const searchParams = ref({
  keyword: '',
  status: undefined as number | undefined,
  register_type: undefined as string | undefined,
  group_ids: [] as number[],
  tag_ids: [] as number[],
});

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    status: undefined,
    register_type: undefined,
    group_ids: [],
    tag_ids: [],
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

/* ---------------- 弹窗 ---------------- */
const userModalVisible = ref(false);
const editingItem = ref<ClientUserApi.UserItem | null>(null);

const handleCreate = () => {
  editingItem.value = null;
  userModalVisible.value = true;
};

const handleEdit = async (record: ClientUserApi.UserItem) => {
  try {
    const detail = await getClientUserInfoApi(record.id);
    editingItem.value = detail;
    userModalVisible.value = true;
  } catch (error) {
    console.error('获取用户详情失败:', error);
    message.error('获取用户详情失败');
  }
};

const onModalSuccess = () => {
  loadData(searchParams.value);
};

/* ---------------- 重置密码 ---------------- */
const handleResetPassword = (record: ClientUserApi.UserItem) => {
  Modal.confirm({
    title: '重置密码',
    content: `确定要重置用户「${record.nickname || record.mobile || record.email}」的密码为 123456 吗？`,
    onOk: async () => {
      // 生成默认密码 123456
      await resetClientUserPasswordApi(record.id, '123456');
      message.success('密码已重置为：123456');
    },
  });
};

/* ---------------- 状态切换 ---------------- */
const handleStatusChange = async (
  record: ClientUserApi.UserItem,
  checked: boolean,
) => {
  try {
    await updateClientUserStatusApi(record.id, { status: checked ? 1 : 0 });
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
    title: '头像',
    width: 80,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      if (!record.avatar) return '-';
      return h('img', {
        src: record.avatar_full_url || record.avatar,
        class: 'w-8 h-8 rounded-full object-cover',
        alt: 'avatar',
      });
    },
  },
  { title: '昵称', dataIndex: 'nickname', width: 120, ellipsis: true },
  { title: '手机号', dataIndex: 'mobile', width: 130 },
  { title: '邮箱', dataIndex: 'email', width: 180, ellipsis: true },
  {
    title: '注册方式',
    dataIndex: 'register_type',
    width: 90,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      const config = REGISTER_TYPE_MAP[record.register_type || 'mobile'];
      return h(
        'span',
        {
          class: `ant-tag ant-tag-${config.color}`,
        },
        config.label,
      );
    },
  },
  {
    title: '性别',
    dataIndex: 'gender',
    width: 70,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      const config = GENDER_MAP[record.gender || 0];
      return h(
        'span',
        {
          class: `ant-tag ant-tag-${config.color}`,
        },
        config.label,
      );
    },
  },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      return h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: (checked: boolean) => handleStatusChange(record, checked),
      });
    },
  },
  {
    title: '分组',
    dataIndex: 'groups',
    width: 150,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      if (!record.groups || record.groups.length === 0) return '-';

      return h('div', { class: 'flex flex-wrap gap-1' },
        record.groups.map((group: UserGroupApi.GroupItem) => {
          const config = colorMap.value[group.color] || {
            label: group.name,
            color: group.color || 'default'
          };
          return h(Tag, { color: config.color }, () => config.label);
        })
      );
    },
  },
  {
    title: '标签',
    dataIndex: 'tags',
    width: 150,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      if (!record.tags || record.tags.length === 0) return '-';

      return h('div', { class: 'flex flex-wrap gap-1' },
        record.tags.map((tag: UserTagApi.TagItem) => {
          const config = colorMap.value[tag.color] || {
            label: tag.name,
            color: tag.color || 'default'
          };
          return h(Tag, { color: config.color }, () => config.label);
        })
      );
    },
  },
  {
    title: '最后登录',
    dataIndex: 'last_login_time',
    width: 160,
    ellipsis: true,
  },
  { title: '注册时间', dataIndex: 'create_time', width: 160 },
  { title: '操作', key: 'action', width: 200 },
];

/* ---------------- 初始化 ---------------- */
onMounted(async () => {
  try {
    const [groups, tags] = await Promise.all([
      getUserGroupListApi({ status: 1, limit: 100 }),
      getUserTagListApi({ status: 1, limit: 100 }),
    ]);
    groupOptions.value = groups.list;
    tagOptions.value = tags.list;
  } catch (error) {
    console.error('加载分组和标签失败:', error);
  }

  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate"> 新增用户 </a-button>
      <a-button class="ml-2" @click="() => loadData(searchParams)">
        刷新
      </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4">
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="手机号/邮箱/昵称"
          allow-clear
          style="width: 200px"
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
      <a-form-item label="注册方式">
        <a-select
          v-model:value="searchParams.register_type"
          placeholder="请选择"
          allow-clear
          style="width: 120px"
        >
          <a-select-option value="mobile">手机</a-select-option>
          <a-select-option value="email">邮箱</a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item label="分组">
        <a-select
          v-model:value="searchParams.group_ids"
          mode="multiple"
          placeholder="请选择分组"
          allow-clear
          style="width: 200px"
          :options="groupOptions.map((g) => ({ label: g.name, value: g.id }))"
        />
      </a-form-item>
      <a-form-item label="标签">
        <a-select
          v-model:value="searchParams.tag_ids"
          mode="multiple"
          placeholder="请选择标签"
          allow-clear
          style="width: 200px"
          :options="tagOptions.map((t) => ({ label: t.name, value: t.id }))"
        />
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
      :scroll="{ x: 1700 }"
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
              @click="handleResetPassword(record)"
            >
              重置密码
            </a-button>
            <a-button
              type="link"
              danger
              size="small"
              @click="handleDelete(record, 'nickname', 'mobile', 'email')"
            >
              删除
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>

    <!-- 用户表单弹窗 -->
    <UserModal
      v-model:visible="userModalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />
  </div>
</template>
