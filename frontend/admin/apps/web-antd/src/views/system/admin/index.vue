<script lang="ts" setup>
import type { UploadProps } from 'ant-design-vue';

import type { AdminApi } from '#/api/system/admin';

import { computed, ref } from 'vue';

import { message, Modal } from 'ant-design-vue';

import { uploadImageApi } from '#/api/core/upload';
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

defineOptions({ name: 'SystemAdmin' });

/* ---------------- 表格 CRUD ---------------- */

const { tableData, loading, pagination, loadData, refresh, handleDelete } =
  useTableCrud(
    {
      delete: deleteAdminApi,
      getInfo: getAdminInfoApi,
      list: getAdminListApi,
    },
    { immediateLoad: false },
  );

/* ---------------- 表单 Modal ---------------- */

const {
  modalVisible,
  modalTitle,
  formData,
  formRef,
  openCreateModal,
  openEditModal,
  handleSubmit,
} = useFormModal<AdminApi.AdminItem>();

const isEdit = computed(() => modalTitle.value.includes('编辑'));

/* ---------------- 角色数据 ---------------- */

const allRoles = ref<AdminApi.RoleItem[]>([]);

const loadRoles = async () => {
  allRoles.value = await getAllRolesApi();
};

/* ---------------- 新增 ---------------- */

const handleCreate = async () => {
  await loadRoles();

  openCreateModal({
    username: '',
    password: '',
    password_confirm: '',
    nickname: '',
    avatar: '',
    email: '',
    mobile: '',
    status: 1,
    remark: '',
    role_ids: [],
  });
};

/* ---------------- 编辑 ---------------- */

const handleEdit = async (row: AdminApi.AdminItem) => {
  await loadRoles();
  await openEditModal(row, getAdminInfoApi);
};

/* ---------------- 提交 ---------------- */

const handleFormSubmit = async () => {
  const submitData = { ...formData.value };

  if (isEdit.value && !submitData.password) {
    delete submitData.password;
  }

  await handleSubmit(
    {
      create: createAdminApi,
      update: updateAdminApi,
    },
    () => loadData(),
  );
};

/* ---------------- 重置密码 ---------------- */

const handleResetPassword = (row: AdminApi.AdminItem) => {
  Modal.confirm({
    title: '重置密码',
    content: '确定要重置密码为 123456 吗？',
    async onOk() {
      await resetPasswordApi(row.id, '123456');
      message.success('密码重置成功');
    },
  });
};

/* ---------------- 头像上传 ---------------- */

const uploadProps: UploadProps = {
  name: 'file',
  maxCount: 1,
  listType: 'picture-card',

  beforeUpload(file) {
    if (!file.type.startsWith('image/')) {
      message.error('只能上传图片');
      return false;
    }

    if (file.size / 1024 / 1024 > 2) {
      message.error('图片不能超过2MB');
      return false;
    }

    return true;
  },
  async customRequest({ file, onSuccess, onError }) {
    try {
      const res = await uploadImageApi(file as File);
      formData.value.avatar = res.url;
      // 用于组件显示
      onSuccess?.({
        url: res.full_url,
      });
      message.success('上传成功');
    } catch (error) {
      onError?.(error as Error);
      message.error('上传失败');
    }
  },

  onRemove() {
    formData.value.avatar = '';
  },
};

/* ---------------- 表格列 ---------------- */

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '用户名', dataIndex: 'username', width: 120 },
  { title: '昵称', dataIndex: 'nickname', width: 120 },
  { title: '手机号', dataIndex: 'mobile', width: 120 },
  { title: '邮箱', dataIndex: 'email', width: 180 },

  {
    title: '角色',
    dataIndex: 'roles',
    width: 160,
    customRender: ({ record }: any) =>
      record.roles?.map((r: any) => r.name).join(', ') || '-',
  },

  {
    title: '状态',
    dataIndex: 'status',
    width: 80,
    customRender: ({ record }: any) => (record.status ? '启用' : '禁用'),
  },

  { title: '最后登录时间', dataIndex: 'last_login_time', width: 180 },

  {
    title: '操作',
    key: 'action',
    width: 260,
  },
];

/* ---------------- 初始化 ---------------- */

loadData();
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate">新增管理员</a-button>
      <a-button class="ml-2" @click="refresh">刷新</a-button>
    </div>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1200 }"
      row-key="id"
      @change="loadData"
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

    <!-- 表单弹窗 -->

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
            :disabled="isEdit"
          />
        </a-form-item>

        <a-form-item
          label="密码"
          name="password"
          :rules="[{ required: !isEdit, message: '请输入密码' }]"
        >
          <a-input-password
            v-model:value="formData.password"
            placeholder="请输入密码"
          />
        </a-form-item>

        <a-form-item
          v-if="!isEdit"
          label="确认密码"
          name="password_confirm"
          :rules="[
            { required: true, message: '请再次输入密码' },
            {
              async validator(_rule: any, value: any) {
                if (value !== formData.password) {
                  throw new Error('两次密码不一致');
                }
              },
            },
          ]"
        >
          <a-input-password
            v-model:value="formData.password_confirm"
            placeholder="请再次输入密码"
          />
        </a-form-item>

        <a-form-item label="昵称">
          <a-input v-model:value="formData.nickname" />
        </a-form-item>

        <a-form-item label="头像">
          <a-upload v-bind="uploadProps">
            <img
              v-if="!formData.avatar"
              :src="formData.full_url"
              style="width: 100%"
              alt="头像"
            />

            <div v-else>
              <div>
                <img :src="formData.avatar" alt="头像" style="width: 100%" />
              </div>
              <div>
                <span class="text-xl">+</span>
                <div class="mt-2 text-xs">上传头像</div>
              </div>
            </div>
          </a-upload>
        </a-form-item>

        <a-form-item label="手机号">
          <a-input v-model:value="formData.mobile" />
        </a-form-item>

        <a-form-item label="邮箱">
          <a-input v-model:value="formData.email" />
        </a-form-item>

        <a-form-item label="角色">
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

        <a-form-item label="状态">
          <a-radio-group v-model:value="formData.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>

        <a-form-item label="备注">
          <a-textarea v-model:value="formData.remark" :rows="3" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
