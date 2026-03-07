<script lang="ts" setup>
import type { AdminApi } from '#/api/system/admin';

import { ref } from 'vue';

import { message, Modal } from 'ant-design-vue';

import {
  createAdminApi,
  deleteAdminApi,
  getAdminInfoApi,
  getAdminListApi,
  resetPasswordApi,
  updateAdminApi,
} from '#/api/system/admin';
import { getAllRolesApi } from '#/api/system/role';
import { useFormModal, useTableCrud } from '#/composables/useTableCrud';

defineOptions({
  name: 'SystemAdmin',
});

// 使用表格 CRUD composable
const { tableData, loading, pagination, loadData, refresh, handleDelete } =
  useTableCrud(
    {
      delete: deleteAdminApi,
      getInfo: getAdminInfoApi,
      list: getAdminListApi,
    },
    { immediateLoad: false },
  );

// 使用表单弹窗 composable
const {
  modalVisible,
  modalTitle,
  formData,
  formRef,
  openCreateModal,
  openEditModal,
  handleSubmit,
} = useFormModal<AdminApi.AdminItem>();

// 所有角色列表
const allRoles = ref<AdminApi.RoleItem[]>([]);

// 加载角色列表
const loadRoles = async () => {
  allRoles.value = await getAllRolesApi();
};

// 打开新增弹窗
const handleCreate = async () => {
  await loadRoles();
  openCreateModal({
    username: '',
    password: '',
    nickname: '',
    avatar: '',
    email: '',
    mobile: '',
    status: 1,
    remark: '',
    role_ids: [],
  });
};

// 打开编辑弹窗
const handleEdit = async (row: AdminApi.AdminItem) => {
  await loadRoles();
  await openEditModal(row, getAdminInfoApi);
};

// 提交表单
const handleFormSubmit = async () => {
  // 如果编辑时密码为空，则不更新密码字段
  const submitData = { ...formData.value };
  if (modalTitle.value.includes('编辑') && !submitData.password) {
    delete submitData.password;
  }

  await handleSubmit(
    {
      create: createAdminApi,
      update: updateAdminApi,
    },
    () => {
      loadData();
    },
  );
};

// 重置密码
const handleResetPassword = (row: AdminApi.AdminItem) => {
  Modal.confirm({
    title: '重置密码',
    content: '确定要重置密码为 123456 吗？',
    onOk: async () => {
      await resetPasswordApi(row.id, '123456');
      message.success('密码重置成功');
    },
  });
};

// 表格列定义
const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '用户名', dataIndex: 'username', width: 120 },
  { title: '昵称', dataIndex: 'nickname', width: 120 },
  { title: '手机号', dataIndex: 'mobile', width: 120 },
  { title: '邮箱', dataIndex: 'email', width: 180 },
  {
    title: '角色',
    dataIndex: 'roles',
    width: 150,
    customRender: ({ record }: any) => {
      if (!record.roles || record.roles.length === 0) return '-';
      return record.roles
        .map((role: AdminApi.RoleItem) => role.name)
        .join(', ');
    },
  },
  {
    title: '状态',
    dataIndex: 'status',
    width: 80,
    customRender: ({ record }: any) => (record.status === 1 ? '启用' : '禁用'),
  },
  { title: '最后登录时间', dataIndex: 'last_login_time', width: 160 },
  {
    title: '操作',
    key: 'action',
    width: 280,
  },
];

// 初始化加载数据
loadData();
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate"> 新增管理员 </a-button>
      <a-button class="ml-2" @click="refresh"> 刷新 </a-button>
    </div>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1200 }"
      @change="loadData"
      row-key="id"
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
              @click="handleDelete(record, 'nickname')"
            >
              删除
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>

    <!-- 新增/编辑弹窗 -->
    <a-modal
      v-model:open="modalVisible"
      :title="modalTitle"
      width="600px"
      @ok="handleFormSubmit"
    >
      <a-form
        ref="formRef"
        :model="formData"
        :label-col="{ span: 6 }"
        :wrapper-col="{ span: 16 }"
      >
        <a-form-item
          label="用户名"
          name="username"
          :rules="[{ required: true, message: '请输入用户名' }]"
        >
          <a-input
            v-model:value="formData.username"
            placeholder="请输入用户名"
            :disabled="modalTitle.includes('编辑')"
          />
        </a-form-item>
        <a-form-item
          label="密码"
          name="password"
          :rules="[
            {
              required: modalTitle.includes('新增'),
              message: '请输入密码',
            },
          ]"
        >
          <a-input-password
            v-model:value="formData.password"
            placeholder="请输入密码"
          />
        </a-form-item>
        <a-form-item label="昵称" name="nickname">
          <a-input v-model:value="formData.nickname" placeholder="请输入昵称" />
        </a-form-item>
        <a-form-item label="手机号" name="mobile">
          <a-input v-model:value="formData.mobile" placeholder="请输入手机号" />
        </a-form-item>
        <a-form-item label="邮箱" name="email">
          <a-input v-model:value="formData.email" placeholder="请输入邮箱" />
        </a-form-item>
        <a-form-item label="角色" name="role_ids">
          <a-select
            v-model:value="formData.role_ids"
            mode="multiple"
            placeholder="请选择角色"
            :options="
              allRoles.map((role) => ({
                label: role.name,
                value: role.id,
              }))
            "
          />
        </a-form-item>
        <a-form-item label="状态" name="status">
          <a-radio-group v-model:value="formData.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="备注" name="remark">
          <a-textarea
            v-model:value="formData.remark"
            :rows="3"
            placeholder="请输入备注"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
