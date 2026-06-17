<script lang="ts" setup>
import { computed, h, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Switch } from 'ant-design-vue';

import { getPermissionTreeApi } from '#/api/auth/permission';
import {
  createRoleApi,
  deleteRoleApi,
  getRoleInfoApi,
  getRoleListApi,
  updateRoleApi,
  updateRoleStatusApi,
} from '#/api/auth/role';
import { useFormModal, useTableCrud } from '#/composables/useTableCrud';

defineOptions({
  name: 'SystemRole',
});

const { hasAccessByCodes } = useAccess();

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
void formRef;

// 权限树数据（菜单 + 按钮）
const permissionTree = ref<any[]>([]);

// 用户显式选择的权限 ID；父级全选/半选状态由树结构计算得出。
const selectedPermissionIds = ref<number[]>([]);

// 菜单 ID 到父菜单 ID 的映射
const menuParentMap = ref<Record<number, number>>({});

// 所有可见菜单节点 ID
const allVisibleMenuIds = ref<Set<number>>(new Set());

// 所有按钮权限 ID
const allButtonPermissionIds = ref<Set<number>>(new Set());

// 按钮 ID 到所属可见菜单 ID 的映射
const buttonParentMenuMap = ref<Record<number, number>>({});

// 菜单 ID 到隐藏读权限 ID 列表的映射
const menuHiddenPermissionMap = ref<Record<number, number[]>>({});

// 菜单 ID 到隐藏按钮权限 ID 列表的映射
const menuHiddenButtonPermissionMap = ref<Record<number, number[]>>({});

// 权限节点索引
const permissionNodeMap = ref<Record<number, any>>({});

// 加载权限数据
const loadPermissionData = async () => {
  const result = await getPermissionTreeApi();
  permissionTree.value = buildPermissionTree(result);
};

/**
 * 构建角色编辑使用的权限树：显示页面菜单和按钮，隐藏列表/详情/枚举读权限。
 */
function buildPermissionTree(permissions: any[]): any[] {
  const visibleMenuIds = new Set<number>();
  const buttonIds = new Set<number>();
  const nodeMap: Record<number, any> = {};
  const parentMap: Record<number, number> = {};
  const buttonParentMap: Record<number, number> = {};
  const hiddenMap: Record<number, number[]> = {};
  const hiddenButtonMap: Record<number, number[]> = {};

  function collectHiddenPermissions(nodes: any[], visibleMenuId: number) {
    for (const node of nodes) {
      if (node.type === 1) {
        (hiddenMap[visibleMenuId] ??= []).push(node.id);
      }
      if (node.type === 2) {
        buttonIds.add(node.id);
        buttonParentMap[node.id] = visibleMenuId;
        (hiddenButtonMap[visibleMenuId] ??= []).push(node.id);
      }
      if (node.children?.length > 0) {
        collectHiddenPermissions(node.children, visibleMenuId);
      }
    }
  }

  function build(nodes: any[], parentVisibleMenuId = 0): any[] {
    const treeNodes: any[] = [];

    for (const item of nodes) {
      if (item.type === 1) {
        if (item.is_show === 0) {
          if (parentVisibleMenuId) {
            collectHiddenPermissions([item], parentVisibleMenuId);
          }
          continue;
        }

        visibleMenuIds.add(item.id);
        parentMap[item.id] = parentVisibleMenuId;

        const children = build(item.children || [], item.id);
        const node = {
          ...item,
          children,
        };

        nodeMap[item.id] = node;
        treeNodes.push(node);
        continue;
      }

      if (item.type === 2 && parentVisibleMenuId) {
        buttonIds.add(item.id);
        buttonParentMap[item.id] = parentVisibleMenuId;

        const node = {
          ...item,
          children: [],
        };

        nodeMap[item.id] = node;
        treeNodes.push(node);
      }
    }

    return treeNodes;
  }

  const tree = build(permissions);

  allVisibleMenuIds.value = visibleMenuIds;
  allButtonPermissionIds.value = buttonIds;
  menuParentMap.value = parentMap;
  buttonParentMenuMap.value = buttonParentMap;
  menuHiddenPermissionMap.value = hiddenMap;
  menuHiddenButtonPermissionMap.value = hiddenButtonMap;
  permissionNodeMap.value = nodeMap;

  return tree;
}

/**
 * 获取节点及所有可见后代节点 ID。
 */
function getNodeAndDescendantIds(nodeId: number): number[] {
  const node = permissionNodeMap.value[nodeId];
  if (!node) {
    return [];
  }

  const ids = [nodeId];
  const visit = (children: any[] = []) => {
    for (const child of children) {
      ids.push(child.id);
      if (child.children?.length > 0) {
        visit(child.children);
      }
    }
  };
  visit(node.children || []);
  return ids;
}

/**
 * 获取某个菜单的所有父级菜单 ID。
 */
function getAllParentMenuIds(menuId: number): number[] {
  const parentIds: number[] = [];
  let parentId = menuParentMap.value[menuId];

  while (parentId) {
    parentIds.push(parentId);
    parentId = menuParentMap.value[parentId];
  }

  return parentIds;
}

/**
 * 补全所有选中菜单的父级菜单。
 */
function ensureParentMenus(menuIds: number[]): number[] {
  const allIds = new Set(menuIds);
  for (const menuId of menuIds) {
    for (const parentId of getAllParentMenuIds(menuId)) {
      allIds.add(parentId);
    }
  }

  return [...allIds];
}

/**
 * 补全页面菜单对应的隐藏读权限，避免角色编辑展示列表/详情/枚举等内部节点。
 */
function completeMenuPermissions(menuIds: number[]): number[] {
  const allIds = new Set(ensureParentMenus(menuIds));

  for (const menuId of allIds) {
    const hiddenIds = menuHiddenPermissionMap.value[menuId] || [];
    hiddenIds.forEach((id) => allIds.add(id));
  }

  return [...allIds];
}

/**
 * 按选中的树节点拆分为后端需要的菜单和按钮权限。
 */
function splitPermissionIds(ids: number[]) {
  const menuIds = ids.filter((id) => allVisibleMenuIds.value.has(id));
  const buttonIds = ids.filter((id) => allButtonPermissionIds.value.has(id));

  for (const buttonId of buttonIds) {
    const parentMenuId = buttonParentMenuMap.value[buttonId];
    if (parentMenuId) {
      menuIds.push(parentMenuId);
    }
  }

  return {
    buttonIds: completeButtonPermissions([
      ...new Set(buttonIds),
      ...getHiddenButtonIdsByMenuIds(menuIds),
    ]),
    menuIds: completeMenuPermissions([...new Set(menuIds)]),
  };
}

/**
 * 获取选中菜单附带的隐藏按钮权限。
 */
function getHiddenButtonIdsByMenuIds(menuIds: number[]): number[] {
  const ids: number[] = [];
  for (const menuId of menuIds) {
    ids.push(...(menuHiddenButtonPermissionMap.value[menuId] || []));
  }
  return ids;
}

function completeButtonPermissions(buttonIds: number[]): number[] {
  return [...new Set(buttonIds)];
}

function calculateTreeCheckState(ids: number[]): {
  checked: number[];
  halfChecked: number[];
} {
  const selected = new Set(ids);
  const checked = new Set<number>();
  const halfChecked = new Set<number>();

  function visit(node: any): 0 | 1 | 2 {
    const children = node.children || [];
    const childStates = children.map((child: any) => visit(child));
    const selfSelected = selected.has(node.id);

    if (children.length === 0) {
      if (selfSelected) {
        checked.add(node.id);
        return 2;
      }
      return 0;
    }

    const allChildrenChecked = childStates.every(
      (state: 0 | 1 | 2) => state === 2,
    );
    const hasCheckedChild = childStates.some((state: 0 | 1 | 2) => state > 0);

    if (allChildrenChecked) {
      checked.add(node.id);
      return 2;
    }

    if (selfSelected || hasCheckedChild) {
      halfChecked.add(node.id);
      return 1;
    }

    return 0;
  }

  permissionTree.value.forEach((node) => visit(node));

  return {
    checked: [...checked],
    halfChecked: [...halfChecked],
  };
}

const treeCheckedKeys = computed(() =>
  calculateTreeCheckState(selectedPermissionIds.value),
);

/**
 * 同步树选中状态。
 */
function handlePermissionCheck(
  _checkedKeys: number[] | { checked: number[]; halfChecked: number[] },
  e: { checked: boolean; node: any },
) {
  const nodeId = Number(e.node.id ?? e.node.key);
  const ids = new Set(selectedPermissionIds.value);
  const affectedIds = getNodeAndDescendantIds(nodeId);

  if (e.checked) {
    affectedIds.forEach((id) => ids.add(id));
  } else {
    affectedIds.forEach((id) => ids.delete(id));
  }

  selectedPermissionIds.value = [...ids];
}

/**
 * 全选当前树中的所有可见菜单和按钮。
 */
function selectAllPermissions() {
  selectedPermissionIds.value = [
    ...allVisibleMenuIds.value,
    ...allButtonPermissionIds.value,
  ];
}

/**
 * 清空所有权限。
 */
function clearAllPermissions() {
  selectedPermissionIds.value = [];
}

const selectedMenuCount = computed(
  () =>
    selectedPermissionIds.value.filter((id) => allVisibleMenuIds.value.has(id))
      .length,
);

const selectedButtonCount = computed(
  () =>
    selectedPermissionIds.value.filter((id) =>
      allButtonPermissionIds.value.has(id),
    ).length,
);

// 打开新增弹窗
const handleCreate = async () => {
  await loadPermissionData();
  openCreateModal({
    name: '',
    code: '',
    remark: '',
    status: 1,
    sort: 0,
    menu_permission_ids: [], // 菜单权限 ID
    button_permission_ids: [], // 按钮权限 ID
  });
  // 默认不选任何权限
  selectedPermissionIds.value = [];
};

// 打开编辑弹窗
const handleEdit = async (row: any) => {
  await loadPermissionData();
  await openEditModal(row, getRoleInfoApi);

  const menuIds = Array.isArray(formData.value.menu_permission_ids)
    ? formData.value.menu_permission_ids.filter((id: number) =>
        allVisibleMenuIds.value.has(id),
      )
    : [];
  const buttonIds = Array.isArray(formData.value.button_permission_ids)
    ? formData.value.button_permission_ids.filter((id: number) =>
        allButtonPermissionIds.value.has(id),
      )
    : [];

  selectedPermissionIds.value = [...new Set([...buttonIds, ...menuIds])];
};

// 提交表单
const handleFormSubmit = async () => {
  const { buttonIds, menuIds } = splitPermissionIds(
    selectedPermissionIds.value,
  );
  formData.value.menu_permission_ids = menuIds;
  formData.value.button_permission_ids = buttonIds;

  await handleSubmit(
    {
      create: createRoleApi,
      update: updateRoleApi,
    },
    () => {
      loadData(searchParams.value);
    },
  );
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
  pagination.current = 1;
  loadData(searchParams.value);
};

const handleSearch = () => {
  pagination.current = 1;
  loadData(searchParams.value);
};

const handleTableChange = (newPagination: {
  current?: number;
  pageSize?: number;
}) => {
  pagination.current = newPagination.current ?? pagination.current;
  pagination.pageSize = newPagination.pageSize ?? pagination.pageSize;
  loadData(searchParams.value);
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
      if (!hasAccessByCodes(['SystemRoleChangeStatus'])) {
        return record.status === 1 ? '启用' : '禁用';
      }
      return h(Switch, {
        checked: record.status === 1,
        onChange: async (checked: any) => {
          await updateRoleStatusApi(record.id, {
            status: checked ? 1 : 0,
          });
          message.success('更新成功');
          await loadData(searchParams.value);
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
if (hasAccessByCodes(['SystemRoleList'])) {
  loadData(searchParams.value);
}
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">角色管理</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SystemRoleCreate'"
        >
          新增角色
        </a-button>
        <a-button @click="refresh" v-access:code="'SystemRoleList'">
          刷新
        </a-button>
      </div>
    </div>

    <!-- 搜索表单 -->
    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
        v-access:code="'SystemRoleList'"
      >
        <a-form-item label="关键词" class="mb-0">
          <a-input
            v-model:value="searchParams.keyword"
            placeholder="角色名称/角色编码/备注"
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
            <a-button type="primary" @click="handleSearch"> 搜索 </a-button>
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
        :scroll="{ x: 900 }"
        @change="handleTableChange"
        row-key="id"
        v-access:code="'SystemRoleList'"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'action'">
            <a-space>
              <a-button
                type="link"
                size="small"
                @click="handleEdit(record)"
                v-access:code="'SystemRoleUpdate'"
              >
                编辑
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                @click="handleDelete(record, 'name')"
                v-access:code="'SystemRoleDelete'"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <!-- 新增/编辑弹窗 -->
    <a-modal
      v-model:open="modalVisible"
      :title="modalTitle"
      width="800px"
      :body-style="{
        maxHeight: '70vh',
        overflowY: 'auto',
        overflowX: 'hidden',
      }"
      @ok="handleFormSubmit"
    >
      <a-form
        ref="formRef"
        :model="formData"
        :label-col="{ style: { width: '100px' } }"
        class="pt-4"
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

        <a-form-item label="权限" name="permission_ids">
          <div class="permission-controls">
            <a-button size="small" @click="selectAllPermissions">
              全选
            </a-button>
            <a-button size="small" @click="clearAllPermissions">
              清空
            </a-button>
            <span class="permission-count">
              已选择 {{ selectedMenuCount }} 个菜单 /
              {{ selectedButtonCount }} 个按钮
            </span>
          </div>
          <a-tree
            :checked-keys="treeCheckedKeys"
            checkable
            check-strictly
            :tree-data="permissionTree"
            :field-names="{
              title: 'name',
              key: 'id',
              children: 'children',
            }"
            class="permission-tree w-full"
            @check="handlePermissionCheck"
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

<style scoped>
.permission-controls {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 0.75rem;
}

.permission-tree {
  max-height: 360px;
  overflow-y: auto;
  padding: 8px;
  border: 1px solid hsl(var(--border));
  border-radius: 4px;
}

.permission-count {
  font-size: 0.875rem;
  color: hsl(var(--muted-foreground));
}
</style>
