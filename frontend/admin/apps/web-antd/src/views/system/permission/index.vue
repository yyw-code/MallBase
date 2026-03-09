<script lang="ts" setup>
import { h, ref } from 'vue';

import { IconPicker } from '@vben/common-ui';
import { IconifyIcon } from '@vben/icons';

import { message, Modal, Switch } from 'ant-design-vue';

import {
  batchUpdatePermissionApi,
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

// 权限树数据（用于上级权限选择）
const permissionTreeData = ref<any[]>([]);

// 图标集前缀
const iconPrefix = ref('ant-design');

// 搜索参数
const searchParams = ref({
  keyword: '',
  type: undefined as number | undefined,
  status: undefined as number | undefined,
});

// 加载数据
const loadData = async () => {
  loading.value = true;
  try {
    const result = await getPermissionTreeApi(searchParams.value);
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

    // 转换为树形选择器的数据格式
    permissionTreeData.value = transformToTreeData(result);
  } catch (error) {
    console.error(error);
  } finally {
    loading.value = false;
  }
};

// 重置搜索
const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    type: undefined,
    status: undefined,
  };
  loadData();
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
    redirect: '',
    affix_tab: 0,
    no_basic_layout: 0,
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
      update: async (id: number, data: any) => {
        return updatePermissionApi(id, data);
      },
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

// 转换权限树数据为树形选择器格式
interface TreeNode {
  label: string;
  title: string;
  value: number;
  children?: TreeNode[];
}

const transformToTreeData = (nodes: any[], isRoot = true): TreeNode[] => {
  const rootOption: TreeNode[] = isRoot
    ? [
        {
          label: '顶级',
          title: '顶级',
          value: 0,
        },
      ]
    : [];

  // 添加所有权限节点
  return [
    ...rootOption,
    ...nodes.map(
      (node): TreeNode => ({
        label: node.name,
        title: node.name,
        value: node.id,
        children: node.children
          ? transformToTreeData(node.children, false)
          : undefined,
      }),
    ),
  ];
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
  {
    title: '图标',
    dataIndex: 'icon',
    width: 80,
    customRender: ({ record }: any) => {
      if (!record.icon) return '-';
      return h(IconifyIcon, {
        icon: record.icon,
        class: 'text-lg',
      });
    },
  },
  { title: '重定向', dataIndex: 'redirect', width: 150 },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 80,
    customRender: ({ record }: any) => {
      return h(Switch, {
        checked: record.status === 1,
        onChange: async (checked: boolean) => {
          const hasChildren = record.children && record.children.length > 0;

          if (hasChildren) {
            Modal.confirm({
              title: '确认操作',
              content: checked
                ? '是否同时启用所有子权限？'
                : '是否同时禁用所有子权限？',
              okText: '是',
              cancelText: '否',
              onOk: async () => {
                await batchUpdatePermissionApi(record.id, {
                  field: 'status',
                  value: checked ? 1 : 0,
                  include_children: true,
                });
                message.success('更新成功');
                loadData();
              },
              onCancel: async () => {
                await batchUpdatePermissionApi(record.id, {
                  field: 'status',
                  value: checked ? 1 : 0,
                  include_children: false,
                });
                message.success('更新成功');
                loadData();
              },
            });
          } else {
            await batchUpdatePermissionApi(record.id, {
              field: 'status',
              value: checked ? 1 : 0,
              include_children: false,
            });
            message.success('更新成功');
            loadData();
          }
        },
      });
    },
  },
  {
    title: '显示',
    dataIndex: 'is_show',
    width: 80,
    customRender: ({ record }: any) => {
      return h(Switch, {
        checked: record.is_show === 1,
        onChange: async (checked: boolean) => {
          const hasChildren = record.children && record.children.length > 0;

          if (hasChildren) {
            Modal.confirm({
              title: '确认操作',
              content: checked
                ? '是否同时显示所有子菜单？'
                : '是否同时隐藏所有子菜单？',
              okText: '是',
              cancelText: '否',
              onOk: async () => {
                await batchUpdatePermissionApi(record.id, {
                  field: 'is_show',
                  value: checked ? 1 : 0,
                  include_children: true,
                });
                message.success('更新成功');
                loadData();
              },
              onCancel: async () => {
                await batchUpdatePermissionApi(record.id, {
                  field: 'is_show',
                  value: checked ? 1 : 0,
                  include_children: false,
                });
                message.success('更新成功');
                loadData();
              },
            });
          } else {
            await batchUpdatePermissionApi(record.id, {
              field: 'is_show',
              value: checked ? 1 : 0,
              include_children: false,
            });
            message.success('更新成功');
            loadData();
          }
        },
      });
    },
  },
  {
    title: '固定标签',
    dataIndex: 'affix_tab',
    width: 100,
    customRender: ({ record }: any) => {
      return h(Switch, {
        checked: record.affix_tab === 1,
        onChange: async (checked: any) => {
          await batchUpdatePermissionApi(record.id, {
            field: 'affix_tab',
            value: checked ? 1 : 0,
            include_children: false,
          });
          message.success('更新成功');
          loadData();
        },
      });
    },
  },
  {
    title: '基础布局',
    dataIndex: 'no_basic_layout',
    width: 100,
    customRender: ({ record }: any) => {
      return h(Switch, {
        checked: record.no_basic_layout === 1,
        onChange: async (checked: any) => {
          await batchUpdatePermissionApi(record.id, {
            field: 'no_basic_layout',
            value: checked ? 1 : 0,
            include_children: false,
          });
          message.success('更新成功');
          loadData();
        },
      });
    },
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
      <a-button type="primary" @click="handleCreate"> 新增权限 </a-button>
      <a-button class="ml-2" @click="loadData"> 刷新 </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4">
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="权限名称/编码"
          allow-clear
          style="width: 200px"
        />
      </a-form-item>
      <a-form-item label="类型">
        <a-select
          v-model:value="searchParams.type"
          placeholder="请选择"
          allow-clear
          style="width: 150px"
        >
          <a-select-option :value="1">菜单</a-select-option>
          <a-select-option :value="2">按钮</a-select-option>
        </a-select>
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
          <a-tree-select
            v-model:value="formData.parent_id"
            :tree-data="permissionTreeData"
            :dropdown-style="{ maxHeight: '400px', overflow: 'auto' }"
            placeholder="0 表示顶级，不选则默认顶级"
            allow-clear
            tree-default-expand-all
            :field-names="{
              label: 'title',
              value: 'value',
              children: 'children',
            }"
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
          <div class="flex flex-col" style="width: 100%">
            <div class="mb-2">
              <a-select
                v-model:value="iconPrefix"
                style="width: 200px"
                placeholder="选择图标集"
              >
                <a-select-option value="ant-design">
                  Ant Design
                </a-select-option>
                <a-select-option value="lucide">Lucide</a-select-option>
                <a-select-option value="mdi">Material Design</a-select-option>
                <a-select-option value="carbon">Carbon</a-select-option>
                <a-select-option value="mdi-light">MDI Light</a-select-option>
              </a-select>
              <span class="sm ml-2 text-gray-400">
                也可直接输入，如：lucide:shield
              </span>
            </div>
            <IconPicker
              v-model="formData.icon"
              :prefix="iconPrefix"
              placeholder="请选择图标"
              style="width: 100%"
            />
          </div>
        </a-form-item>
        <a-form-item label="组件路径" name="component">
          <a-input
            v-model:value="formData.component"
            placeholder="请输入组件路径"
          />
        </a-form-item>
        <a-form-item label="重定向路径" name="redirect">
          <a-input
            v-model:value="formData.redirect"
            placeholder="请输入重定向路径，如：/dashboard"
          />
        </a-form-item>
        <a-form-item label="固定标签" name="affix_tab">
          <a-radio-group v-model:value="formData.affix_tab">
            <a-radio :value="1">固定</a-radio>
            <a-radio :value="0">不固定</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="基础布局" name="no_basic_layout">
          <a-radio-group v-model:value="formData.no_basic_layout">
            <a-radio :value="1">不需要</a-radio>
            <a-radio :value="0">需要</a-radio>
          </a-radio-group>
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
