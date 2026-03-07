<script lang="ts" setup>
import { message, Modal } from 'ant-design-vue';
import { ref } from 'vue';

import {
  createPermissionApi,
  deletePermissionApi,
  getPermissionInfoApi,
  getPermissionTreeApi,
  updatePermissionApi,
} from '#/api/system/permission';
import { useFormModal } from '#/composables/useTableCrud';

defineOptions({
  name: 'SystemPermission',
});

// 表格数据
const tableData = ref<any[]>([]);
const loading = ref(false);
const treeExpandedKeys = ref<number[]>([]);

// 加载数据
const loadData = async () => {
  loading.value = true;
  try {
    const result = await getPermissionTreeApi();
    tableData.value = result;
    // 默认展开所有节点
    const expandAll = (nodes: any[]) => {
      nodes.forEach((node) => {
        if (node.children && node.children.length > 0) {
          treeExpandedKeys.value.push(node.id);
          expandAll(node.children);
        }
      });
    };
    expandAll(result);
  } catch (error) {
    console.error(error);
  } finally {
    loading.value = false;
  }
};

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

// 打开新增弹窗
const handleCreate = () => {
  openCreateModal({
    parent_id: 0,
    name: '',
    code: '',
    type: 1,
    path: '',
    icon: '',
    component: '',
    sort: 0,
    status: 1,
    is_show: 1,
    remark: '',
  });
};

// 打开编辑弹窗
const handleEdit = async (row: any) => {
  await openEditModal(row, getPermissionInfoApi);
};

// 提交表单
const handleFormSubmit = async () => {
  await handleSubmit(
    {
      create: createPermissionApi,
      update: updatePermissionApi,
    },
    () => {
      loadData();
    },
  );
};

// 删除权限
const handleDelete = (row: any) => {
  Modal.confirm({
    content: `确定要删除权限"${row.name}"吗？`,
    onOk: async () => {
      await deletePermissionApi(row.id);
      message.success('删除成功');
      loadData();
    },
  });
};

// 表格列定义
const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '权限名称', dataIndex: 'name', width: 200 },
  { title: '权限编码', dataIndex: 'code', width: 180 },
  {
    title: '类型',
    dataIndex: 'type',
    width: 100,
    customRender: ({ record }: any) => {
      if (record.type === 1) return '菜单';
      if (record.type === 2) return '按钮';
      return '-';
    },
  },
  { title: '路由路径', dataIndex: 'path', width: 200 },
  { title: '图标', dataIndex: 'icon', width: 80 },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 80,
    customRender: ({ record }: any) =>
      record.status === 1 ? '启用' : '禁用',
  },
  {
    title: '显示',
    dataIndex: 'is_show',
    width: 80,
    customRender: ({ record }: any) =>
      record.is_show === 1 ? '显示' : '隐藏',
  },
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
      <a-button type="primary" @click="handleCreate">
        新增权限
      </a-button>
      <a-button class="ml-2" @click="loadData">
        刷新
      </a-button>
    </div>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="false"
      :scroll="{ x: 1400 }"
      :default-expanded-row-keys="treeExpandedKeys"
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
              @click="handleDelete(record)"
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
        <a-form-item label="上级权限" name="parent_id">
          <a-input-number
            v-model:value="formData.parent_id"
            :min="0"
            placeholder="0 表示顶级"
            style="width: 100%"
          />
        </a-form-item>
        <a-form-item
          label="权限名称"
          name="name"
          :rules="[{ required: true, message: '请输入权限名称' }]"
        >
          <a-input v-model:value="formData.name" placeholder="请输入权限名称" />
        </a-form-item>
        <a-form-item
          label="权限编码"
          name="code"
          :rules="[{ required: true, message: '请输入权限编码' }]"
        >
          <a-input v-model:value="formData.code" placeholder="请输入权限编码" />
        </a-form-item>
        <a-form-item label="权限类型" name="type">
          <a-radio-group v-model:value="formData.type">
            <a-radio :value="1">菜单</a-radio>
            <a-radio :value="2">按钮</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="路由路径" name="path">
          <a-input v-model:value="formData.path" placeholder="请输入路由路径" />
        </a-form-item>
        <a-form-item label="图标" name="icon">
          <a-input v-model:value="formData.icon" placeholder="请输入图标" />
        </a-form-item>
        <a-form-item label="组件路径" name="component">
          <a-input
            v-model:value="formData.component"
            placeholder="请输入组件路径"
          />
        </a-form-item>
        <a-form-item label="排序" name="sort">
          <a-input-number v-model:value="formData.sort" :min="0" style="width: 100%" />
        </a-form-item>
        <a-form-item label="状态" name="status">
          <a-radio-group v-model:value="formData.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="是否显示" name="is_show">
          <a-radio-group v-model:value="formData.is_show">
            <a-radio :value="1">显示</a-radio>
            <a-radio :value="0">隐藏</a-radio>
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