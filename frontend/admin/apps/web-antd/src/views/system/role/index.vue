<script lang="ts" setup>
import { h, ref } from 'vue';

import { message, Switch } from 'ant-design-vue';

import { getPermissionTreeApi } from '#/api/system/permission';
import {
  createRoleApi,
  deleteRoleApi,
  getRoleInfoApi,
  getRoleListApi,
  updateRoleApi,
  updateRoleStatusApi,
} from '#/api/system/role';
import { useFormModal, useTableCrud } from '#/composables/useTableCrud';

defineOptions({
  name: 'SystemRole',
});

// 使用表格 CRUD composable
const { tableData, loading, pagination, loadData, refresh, handleDelete } =
  useTableCrud(
    {
      list: getRoleListApi,
      delete: deleteRoleApi,
      getInfo: getRoleInfoApi,
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
} = useFormModal();

// 权限树数据
const permissionTree = ref<any[]>([]);

// 加载权限树
const loadPermissionTree = async () => {
  const result = await getPermissionTreeApi();
  permissionTree.value = result;
};

// 打开新增弹窗
const handleCreate = async () => {
  await loadPermissionTree();
  openCreateModal({
    name: '',
    code: '',
    remark: '',
    status: 1,
    sort: 0,
    permission_ids: [],
  });
};

// 打开编辑弹窗
const handleEdit = async (row: any) => {
  await loadPermissionTree();
  await openEditModal(row, getRoleInfoApi);
};

// 提交表单
const handleFormSubmit = async () => {
  await handleSubmit(
    {
      create: createRoleApi,
      update: updateRoleApi,
    },
    () => {
      loadData();
    },
  );
};

// 表格列定义
const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '角色名称', dataIndex: 'name', width: 150 },
  { title: '角色编码', dataIndex: 'code', width: 150 },
  { title: '备注', dataIndex: 'remark', width: 200 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 80,
    customRender: ({ record }: any) => {
      return h(Switch, {
        checked: record.status === 1,
        onChange: async (checked: any) => {
          await updateRoleStatusApi(record.id, {
            status: checked ? 1 : 0,
          });
          message.success('更新成功');
          await loadData();
        },
      });
    },
  },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '操作',
    key: 'action',
    width: 200,
  },
];

// 初始化加载数据
loadData();
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate"> 新增角色 </a-button>
      <a-button class="ml-2" @click="refresh"> 刷新 </a-button>
    </div>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 900 }"
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
              danger
              size="small"
              @click="handleDelete(record, 'name')"
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
      width="700px"
      @ok="handleFormSubmit"
    >
      <a-form
        ref="formRef"
        :model="formData"
        :label-col="{ span: 4 }"
        :wrapper-col="{ span: 20 }"
      >
        <a-form-item
          label="角色名称"
          name="name"
          :rules="[{ required: true, message: '请输入角色名称' }]"
        >
          <a-input v-model:value="formData.name" placeholder="请输入角色名称" />
        </a-form-item>
        <a-form-item
          label="角色编码"
          name="code"
          :rules="[{ required: true, message: '请输入角色编码' }]"
        >
          <a-input v-model:value="formData.code" placeholder="请输入角色编码" />
        </a-form-item>
        <a-form-item label="排序" name="sort">
          <a-input-number
            v-model:value="formData.sort"
            :min="0"
            style="width: 100%"
          />
        </a-form-item>
        <a-form-item label="状态" name="status">
          <a-radio-group v-model:value="formData.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="权限分配" name="permission_ids">
          <a-tree
            v-model:checked-keys="formData.permission_ids"
            checkable
            :tree-data="permissionTree"
            :field-names="{
              title: 'name',
              key: 'id',
              children: 'children',
            }"
            :check-strictly="false"
            class="w-full"
            style="max-height: 400px; overflow-y: auto"
          />
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
