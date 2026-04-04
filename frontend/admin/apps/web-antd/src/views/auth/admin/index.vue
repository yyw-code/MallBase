<script lang="ts" setup>
import type { AdminApi } from '#/api/auth/admin';

import { computed, h, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Modal, Switch } from 'ant-design-vue';

import {
  createAdminApi,
  deleteAdminApi,
  getAdminInfoApi,
  getAdminListApi,
  resetPasswordApi,
  updateAdminApi,
  updateAdminStatusApi,
} from '#/api/auth/admin';
import { getAllRolesApi } from '#/api/auth/role';
import Upload from '#/components/upload/index.vue';
import { useFormModal, useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'SystemAdmin' });

const { hasAccessByCodes } = useAccess();

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
    avatar_full_url: '',
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
    () => loadData(searchParams.value),
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

const handleAvatarChange = (val: any) => {
  if (val) {
    formData.value.avatar = val.url;
    formData.value.avatar_full_url = val.full_url;
  } else {
    formData.value.avatar = '';
    formData.value.avatar_full_url = '';
  }
};

// 搜索参数
const searchParams = ref({
  keyword: '',
  status: undefined as number | undefined,
});

// 重置搜索
const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    status: undefined,
  };
  loadData(searchParams.value);
};

/* ---------------- 表格列 ---------------- */

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '头像', width: 100 },
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
    customRender: ({ record }: any) => {
      if (!hasAccessByCodes(['SystemAdminChangeStatus'])) {
        return record.status === 1 ? '启用' : '禁用';
      }
      return h(Switch, {
        checked: record.status === 1,
        onChange: async (checked: any) => {
          await updateAdminStatusApi(record.id, {
            status: checked ? 1 : 0,
          });
          message.success('更新成功');
          await loadData(searchParams.value);
        },
      });
    },
  },

  { title: '最后登录时间', dataIndex: 'last_login_time', width: 180 },

  {
    title: '操作',
    key: 'action',
    width: 260,
  },
];

/* ---------------- 初始化 ---------------- */

if (hasAccessByCodes(['SystemAdminList'])) {
  loadData(searchParams.value);
}
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button
        type="primary"
        @click="handleCreate"
        v-access:code="'SystemAdminCreate'"
      >
        新增管理员
      </a-button>
      <a-button class="ml-2" @click="refresh" v-access:code="'SystemAdminList'">
        刷新
      </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4" v-access:code="'SystemAdminList'">
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="用户名/昵称/手机号/邮箱"
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
        <a-button type="primary" @click="loadData(searchParams)">
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
      :scroll="{ x: 1200 }"
      row-key="id"
      @change="() => loadData(searchParams)"
      v-access:code="'SystemAdminList'"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.title === '头像'">
          <a-image
            v-if="record.avatar_full_url"
            :src="record.avatar_full_url"
            :width="60"
            :height="60"
            :style="{ objectFit: 'cover', borderRadius: '50%' }"
          />
          <span v-else>-</span>
        </template>

        <template v-if="column.key === 'action'">
          <a-space>
            <a-button
              type="link"
              size="small"
              @click="handleEdit(record)"
              v-access:code="'SystemAdminUpdate'"
            >
              编辑
            </a-button>

            <a-button
              type="link"
              size="small"
              @click="handleResetPassword(record)"
              v-access:code="'SystemAdminResetPassword'"
            >
              重置密码
            </a-button>

            <a-button
              type="link"
              danger
              size="small"
              @click="handleDelete(record, 'nickname')"
              v-access:code="'SystemAdminDelete'"
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
          <Upload
            :value="
              formData.avatar_full_url
                ? {
                    url: formData.avatar,
                    full_url: formData.avatar_full_url,
                    name: 'avatar',
                  }
                : undefined
            "
            type="image"
            module="admin"
            @update:value="handleAvatarChange"
          />
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
