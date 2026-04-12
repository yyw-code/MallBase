<script lang="ts" setup>
import { computed, h, ref, watch } from 'vue';

import { useAccess } from '@vben/access';

import { message, Switch, Tag } from 'ant-design-vue';

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

// 权限树数据（菜单）
const permissionTree = ref<any[]>([]);

// 菜单 ID 到名称的映射
const menuNameMap = ref<Record<number, string>>({});

// 菜单 ID 到父菜单 ID 的映射
const menuParentMap = ref<Record<number, number>>({});

// 所有菜单节点 ID（用于判断是否是叶子节点）
const allMenuIds = ref<Set<number>>(new Set());

// 有子菜单的父菜单 ID 集合
const parentMenuIds = ref<Set<number>>(new Set());

// 菜单 ID 到按钮权限 ID 列表的映射
const menuButtonPermissionMap = ref<Record<number, number[]>>({});

// 菜单 ID 到接口权限 ID 列表的映射
const menuApiPermissionMap = ref<Record<number, number[]>>({});

// 按钮权限列表
const buttonPermissions = ref<any[]>([]);

// 接口权限列表
const apiPermissions = ref<any[]>([]);

// 所有按钮权限 ID（用于全选）
const allButtonPermissionIds = ref<number[]>([]);

// 所有接口权限 ID（用于全选）
const allApiPermissionIds = ref<number[]>([]);

// 按钮权限搜索关键词
const buttonSearchKeyword = ref('');

// 接口权限搜索关键词
const apiSearchKeyword = ref('');

// 分组折叠状态（默认折叠）
const buttonGroupCollapsed = ref<Record<string, boolean>>({});

// 接口权限分组折叠状态（默认折叠）
const apiGroupCollapsed = ref<Record<string, boolean>>({});

// 初始化分组为折叠状态
function initGroupCollapsedState(
  groups: Record<string, any[]>,
  collapsed: Record<string, boolean>,
) {
  Object.keys(groups).forEach((menuName) => {
    if (collapsed[menuName] === undefined) {
      collapsed[menuName] = true; // 默认折叠
    }
  });
}

// 加载权限数据
const loadPermissionData = async () => {
  const result = await getPermissionTreeApi();
  // 过滤出菜单节点（用于树形选择）
  permissionTree.value = filterMenuTree(result);
  // 构建菜单 ID 到名称的映射
  menuNameMap.value = buildMenuNameMap(result);
  // 构建菜单 ID 到父菜单 ID 的映射
  menuParentMap.value = buildMenuParentMap(result);
  // 构建菜单到按钮权限的映射
  menuButtonPermissionMap.value = buildMenuPermissionMap(result, 2);
  // 构建菜单到接口权限的映射
  menuApiPermissionMap.value = buildMenuPermissionMap(result, 3);
  // 收集按钮权限
  buttonPermissions.value = collectPermissions(result, 2);
  // 收集接口权限
  apiPermissions.value = collectPermissions(result, 3);
  // 收集所有按钮权限 ID
  allButtonPermissionIds.value = buttonPermissions.value.map((p) => p.id);
  // 收集所有接口权限 ID
  allApiPermissionIds.value = apiPermissions.value.map((p) => p.id);
};

/**
 * 过滤权限树，只保留菜单节点（type: 1）
 */
function filterMenuTree(permissions: any[]): any[] {
  const menuIds = new Set<number>();
  const parentIds = new Set<number>();

  function filterAndCollect(nodes: any[]): any[] {
    return nodes
      .filter((item) => item.type === 1)
      .map((item) => {
        menuIds.add(item.id);
        const childMenus = item.children ? filterAndCollect(item.children) : [];
        if (childMenus.length > 0) {
          parentIds.add(item.id);
        }
        return { ...item, children: childMenus };
      });
  }

  const result = filterAndCollect(permissions);
  allMenuIds.value = menuIds;
  parentMenuIds.value = parentIds;
  return result;
}

/**
 * 过滤出叶子菜单节点 ID（没有子菜单的节点）
 * 用于传给 Tree 组件的 checkedKeys，避免父子联动导致歧义
 */
function getLeafMenuIds(menuIds: number[]): number[] {
  return menuIds.filter((id) => !parentMenuIds.value.has(id));
}

/**
 * 收集指定类型的权限
 */
function collectPermissions(permissions: any[], type: number): any[] {
  const result: any[] = [];

  function traverse(nodes: any[]) {
    for (const node of nodes) {
      if (node.type === type) {
        result.push(node);
      }
      if (node.children?.length > 0) {
        traverse(node.children);
      }
    }
  }

  traverse(permissions);
  return result;
}

/**
 * 构建菜单 ID 到名称的映射
 */
function buildMenuNameMap(permissions: any[]): Record<number, string> {
  const map: Record<number, string> = {};

  function traverse(nodes: any[]) {
    for (const node of nodes) {
      if (node.type === 1) {
        // 只映射菜单节点
        map[node.id] = node.name;
      }
      if (node.children?.length > 0) {
        traverse(node.children);
      }
    }
  }

  traverse(permissions);
  return map;
}

/**
 * 构建菜单 ID 到父菜单 ID 的映射
 */
function buildMenuParentMap(permissions: any[]): Record<number, number> {
  const map: Record<number, number> = {};

  function traverse(nodes: any[]) {
    for (const node of nodes) {
      if (node.type === 1 && node.parent_id !== undefined) {
        // 只映射菜单节点
        map[node.id] = node.parent_id;
      }
      if (node.children?.length > 0) {
        traverse(node.children);
      }
    }
  }

  traverse(permissions);
  return map;
}

/**
 * 获取某个菜单的所有父级菜单 ID
 */
function getAllParentMenuIds(
  menuId: number,
  parentIds: number[] = [],
): number[] {
  const parentId = menuParentMap.value[menuId];
  if (parentId && parentId !== 0) {
    parentIds.push(parentId);
    return getAllParentMenuIds(parentId, parentIds);
  }
  return parentIds;
}

/**
 * 补全所有选中菜单的父级菜单
 */
function ensureParentMenus(menuIds: number[]): number[] {
  const allIds = new Set(menuIds);
  menuIds.forEach((menuId) => {
    const parentIds = getAllParentMenuIds(menuId);
    parentIds.forEach((parentId) => {
      allIds.add(parentId);
    });
  });
  return [...allIds];
}

/**
 * 构建菜单 ID 到权限 ID 列表的映射
 */
function buildMenuPermissionMap(
  permissions: any[],
  type: number,
): Record<number, number[]> {
  const map: Record<number, number[]> = {};

  function traverse(nodes: any[]) {
    for (const node of nodes) {
      if (node.type === 1) {
        // 为菜单节点初始化空数组
        if (!map[node.id]) {
          map[node.id] = [];
        }
        // 收集该菜单下的所有指定类型权限
        const permissions = collectPermissions([node], type);
        const permissionIds = permissions.map((p) => p.id);
        if (permissionIds.length > 0) {
          map[node.id].push(...permissionIds);
        }
      }
      if (node.children?.length > 0) {
        traverse(node.children);
      }
    }
  }

  traverse(permissions);
  return map;
}

/**
 * 根据菜单 ID 查找菜单名称
 */
function findMenuName(menuId: number): string {
  return menuNameMap.value[menuId] || '其他';
}

/**
 * 过滤后的按钮权限（根据搜索关键词）
 */
const filteredButtonPermissions = computed(() => {
  if (!buttonSearchKeyword.value) {
    return buttonPermissions.value;
  }
  const keyword = buttonSearchKeyword.value.toLowerCase();
  return buttonPermissions.value.filter(
    (p) =>
      p.name.toLowerCase().includes(keyword) ||
      p.code.toLowerCase().includes(keyword),
  );
});

/**
 * 按钮权限按菜单分组
 */
const buttonPermissionsGrouped = computed(() => {
  const groups: Record<string, any[]> = {};
  filteredButtonPermissions.value.forEach((btn) => {
    const menuName = findMenuName(btn.parent_id);
    const groupName = menuName || '其他';
    if (!groups[groupName]) {
      groups[groupName] = [];
    }
    groups[groupName].push(btn);
  });
  return groups;
});

/**
 * 过滤后的接口权限（根据搜索关键词）
 */
const filteredApiPermissions = computed(() => {
  if (!apiSearchKeyword.value) {
    return apiPermissions.value;
  }
  const keyword = apiSearchKeyword.value.toLowerCase();
  return apiPermissions.value.filter(
    (p) =>
      p.name.toLowerCase().includes(keyword) ||
      p.code.toLowerCase().includes(keyword),
  );
});

/**
 * 接口权限按菜单分组
 */
const apiPermissionsGrouped = computed(() => {
  const groups: Record<string, any[]> = {};
  filteredApiPermissions.value.forEach((api) => {
    const menuName = findMenuName(api.parent_id);
    const groupName = menuName || '其他';
    if (!groups[groupName]) {
      groups[groupName] = [];
    }
    groups[groupName].push(api);
  });
  return groups;
});

// 监听分组变化，初始化折叠状态
watch(
  () => buttonPermissionsGrouped.value,
  (groups) => {
    initGroupCollapsedState(groups, buttonGroupCollapsed.value);
  },
  { immediate: true },
);

watch(
  () => apiPermissionsGrouped.value,
  (groups) => {
    initGroupCollapsedState(groups, apiGroupCollapsed.value);
  },
  { immediate: true },
);

/**
 * 全选所有按钮权限
 */
function selectAllButtonPermissions() {
  formData.value.button_permission_ids = [...allButtonPermissionIds.value];
}

/**
 * 清空所有按钮权限
 */
function clearAllButtonPermissions() {
  formData.value.button_permission_ids = [];
}

/**
 * 全选所有接口权限
 */
function selectAllApiPermissions() {
  formData.value.api_permission_ids = [...allApiPermissionIds.value];
}

/**
 * 清空所有接口权限
 */
function clearAllApiPermissions() {
  formData.value.api_permission_ids = [];
}

/**
 * 切换按钮权限分组折叠状态
 */
function toggleButtonGroupCollapsed(menuName: string) {
  buttonGroupCollapsed.value[menuName] = !buttonGroupCollapsed.value[menuName];
}

/**
 * 切换接口权限分组折叠状态
 */
function toggleApiGroupCollapsed(menuName: string) {
  apiGroupCollapsed.value[menuName] = !apiGroupCollapsed.value[menuName];
}

/**
 * 全选某个菜单下的按钮权限
 */
function selectButtonPermissionsByMenu(menuName: string) {
  const buttons = buttonPermissionsGrouped.value[menuName];
  if (!buttons || !formData.value) return;

  const buttonIds = buttons.map((b) => b.id);
  const currentIds = formData.value.button_permission_ids || [];

  // 合并所有该菜单的按钮权限
  const merged = new Set([...buttonIds, ...currentIds]);
  formData.value.button_permission_ids = [...merged];
}

/**
 * 清空某个菜单下的按钮权限
 */
function clearButtonPermissionsByMenu(menuName: string) {
  const buttons = buttonPermissionsGrouped.value[menuName];
  if (!buttons || !formData.value) return;

  const buttonIds = new Set(buttons.map((b) => b.id));
  const currentIds = formData.value.button_permission_ids || [];

  // 移除所有该菜单的按钮权限
  formData.value.button_permission_ids = currentIds.filter(
    (id: number) => !buttonIds.has(id),
  );
}

/**
 * 全选某个菜单下的接口权限
 */
function selectApiPermissionsByMenu(menuName: string) {
  const apis = apiPermissionsGrouped.value[menuName];
  if (!apis || !formData.value) return;

  const apiIds = apis.map((a) => a.id);
  const currentIds = formData.value.api_permission_ids || [];

  // 合并所有该菜单的接口权限
  const merged = new Set([...apiIds, ...currentIds]);
  formData.value.api_permission_ids = [...merged];
}

/**
 * 清空某个菜单下的接口权限
 */
function clearApiPermissionsByMenu(menuName: string) {
  const apis = apiPermissionsGrouped.value[menuName];
  if (!apis || !formData.value) return;

  const apiIds = new Set(apis.map((a) => a.id));
  const currentIds = formData.value.api_permission_ids || [];

  // 移除所有该菜单的接口权限
  formData.value.api_permission_ids = currentIds.filter(
    (id: number) => !apiIds.has(id),
  );
}

/**
 * 计算某个菜单下的按钮权限选中数量
 */
function getButtonPermissionSelectedCount(menuName: string): number {
  const buttons = buttonPermissionsGrouped.value[menuName];
  if (!buttons || !formData.value) return 0;

  const currentIds = new Set(formData.value.button_permission_ids || []);
  return buttons.filter((btn) => currentIds.has(btn.id)).length;
}

/**
 * 计算某个菜单下的接口权限选中数量
 */
function getApiPermissionSelectedCount(menuName: string): number {
  const apis = apiPermissionsGrouped.value[menuName];
  if (!apis || !formData.value) return 0;

  const currentIds = new Set(formData.value.api_permission_ids || []);
  return apis.filter((api) => currentIds.has(api.id)).length;
}

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
    api_permission_ids: [], // 接口权限 ID
  });
  // 默认不选任何权限
  formData.value.button_permission_ids = [];
  formData.value.api_permission_ids = [];
};

// 打开编辑弹窗
const handleEdit = async (row: any) => {
  await loadPermissionData();
  await openEditModal(row, getRoleInfoApi);
  // 只传叶子节点给树，避免父子联动歧义
  if (Array.isArray(formData.value.menu_permission_ids)) {
    formData.value.menu_permission_ids = getLeafMenuIds(
      formData.value.menu_permission_ids,
    );
  }
};

// 提交表单
const handleFormSubmit = async () => {
  // 补全所有选中菜单的父级菜单
  const menuIds = formData.value.menu_permission_ids;
  const checkedIds = Array.isArray(menuIds) ? menuIds : [];
  formData.value.menu_permission_ids = ensureParentMenus(checkedIds);

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

/**
 * 获取菜单节点及其所有子菜单的 ID 列表
 */
function getMenuAndChildrenIds(menuId: number): number[] {
  const ids: number[] = [menuId];
  function findInChildren(nodes: any[]) {
    for (const node of nodes) {
      if (ids.includes(node.parent_id) || node.id === menuId) {
        if (!ids.includes(node.id)) {
          ids.push(node.id);
        }
        if (node.children?.length > 0) {
          findInChildren(node.children);
        }
      }
    }
  }
  findInChildren(permissionTree.value);
  return ids;
}

/**
 * 菜单权限勾选事件 - 联动按钮和接口权限
 * 勾选菜单 → 自动勾选该菜单及子菜单下所有按钮+接口权限
 * 取消菜单 → 自动取消该菜单及子菜单下所有按钮+接口权限
 * 按钮/接口单独操作不影响菜单
 */
function handleMenuCheck(
  checkedKeys: number[] | { checked: number[]; halfChecked: number[] },
  e: { checked: boolean; node: any },
) {
  const menuId = e.node.id;
  const isChecked = e.checked;

  // 获取该菜单及所有子菜单的 ID
  const affectedMenuIds = getMenuAndChildrenIds(menuId);

  // 收集受影响的所有按钮权限 ID
  const affectedButtonIds: number[] = [];
  for (const id of affectedMenuIds) {
    const ids = menuButtonPermissionMap.value[id] || [];
    affectedButtonIds.push(...ids);
  }

  // 收集受影响的所有接口权限 ID
  const affectedApiIds: number[] = [];
  for (const id of affectedMenuIds) {
    const ids = menuApiPermissionMap.value[id] || [];
    affectedApiIds.push(...ids);
  }

  // 更新按钮权限
  const currentButtons = new Set(formData.value.button_permission_ids || []);
  if (isChecked) {
    for (const id of affectedButtonIds) {
      currentButtons.add(id);
    }
  } else {
    for (const id of affectedButtonIds) {
      currentButtons.delete(id);
    }
  }
  formData.value.button_permission_ids = [...currentButtons];

  // 更新接口权限
  const currentApis = new Set(formData.value.api_permission_ids || []);
  if (isChecked) {
    for (const id of affectedApiIds) {
      currentApis.add(id);
    }
  } else {
    for (const id of affectedApiIds) {
      currentApis.delete(id);
    }
  }
  formData.value.api_permission_ids = [...currentApis];
}

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
    <div class="mb-4">
      <a-button
        type="primary"
        @click="handleCreate"
        v-access:code="'SystemRoleCreate'"
      >
        新增角色
      </a-button>
      <a-button class="ml-2" @click="refresh" v-access:code="'SystemRoleList'">
        刷新
      </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4" v-access:code="'SystemRoleList'">
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="角色名称/角色编码/备注"
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
        <a-button
          type="primary"
          @click="
            () => {
              pagination.current = 1;
              loadData(searchParams.value);
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
      :scroll="{ x: 900 }"
      @change="
        (newPagination) => {
          pagination.current = newPagination.current;
          pagination.pageSize = newPagination.pageSize;
          loadData(searchParams.value);
        }
      "
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

        <!-- 菜单权限 -->
        <a-form-item label="菜单权限" name="menu_permission_ids">
          <div class="permission-description">选择角色可以访问的菜单</div>
          <a-tree
            v-model:checked-keys="formData.menu_permission_ids"
            checkable
            :tree-data="permissionTree"
            :field-names="{
              title: 'name',
              key: 'id',
              children: 'children',
            }"
            :check-strictly="false"
            class="permission-tree w-full"
            @check="handleMenuCheck"
          />
        </a-form-item>

        <!-- 按钮权限 -->
        <a-form-item label="按钮权限" name="button_permission_ids">
          <template #extra>
            <div class="permission-description">选择角色可以使用的按钮功能</div>
            <div class="permission-controls">
              <a-input
                v-model:value="buttonSearchKeyword"
                placeholder="搜索按钮权限"
                allow-clear
                style="width: 200px"
              >
                <template #prefix>
                  <span class="text-gray-400">🔍</span>
                </template>
              </a-input>
              <a-button size="small" @click="selectAllButtonPermissions">
                全选
              </a-button>
              <a-button size="small" @click="clearAllButtonPermissions">
                清空
              </a-button>
              <span class="text-sm text-gray-500">
                已选择 {{ formData.button_permission_ids?.length || 0 }} /
                {{ filteredButtonPermissions.length }} 项
              </span>
            </div>
          </template>
          <a-form-item-rest>
            <a-checkbox-group v-model:value="formData.button_permission_ids">
              <div class="permission-list">
                <div
                  v-for="(buttons, menuName) in buttonPermissionsGrouped"
                  :key="menuName"
                  class="permission-group"
                >
                  <a-form-item no-style>
                    <div class="permission-group-header">
                      <div
                        class="permission-group-header-left"
                        @click="toggleButtonGroupCollapsed(menuName)"
                      >
                        <span class="collapse-icon">
                          {{ buttonGroupCollapsed[menuName] ? '▶' : '▼' }}
                        </span>
                        <span class="permission-group-title">
                          {{ menuName }}
                        </span>
                      </div>
                      <div class="permission-group-actions">
                        <a-space>
                          <a-button
                            size="small"
                            @click="selectButtonPermissionsByMenu(menuName)"
                          >
                            全选
                          </a-button>
                          <a-button
                            size="small"
                            @click="clearButtonPermissionsByMenu(menuName)"
                          >
                            清空
                          </a-button>
                        </a-space>
                        <span class="permission-count">
                          已选择
                          {{ getButtonPermissionSelectedCount(menuName) }} /
                          {{ buttons.length }} 项
                        </span>
                      </div>
                    </div>
                  </a-form-item>
                  <div
                    v-show="!buttonGroupCollapsed[menuName]"
                    class="permission-items"
                  >
                    <a-checkbox
                      v-for="btn in buttons"
                      :key="btn.id"
                      :value="btn.id"
                    >
                      <Tag color="blue" class="mr-1">{{ btn.code }}</Tag>
                      {{ btn.name }}
                    </a-checkbox>
                  </div>
                </div>
                <div
                  v-if="Object.keys(buttonPermissionsGrouped).length === 0"
                  class="w-full py-8 text-center text-gray-400"
                >
                  暂无按钮权限
                </div>
              </div>
            </a-checkbox-group>
          </a-form-item-rest>
        </a-form-item>

        <!-- 接口权限 -->
        <a-form-item label="接口权限" name="api_permission_ids">
          <template #extra>
            <div class="permission-description">
              选择角色可以调用的 API 接口
            </div>
            <div class="permission-controls">
              <a-input
                v-model:value="apiSearchKeyword"
                placeholder="搜索接口权限"
                allow-clear
                style="width: 200px"
              >
                <template #prefix>
                  <span class="text-gray-400">🔍</span>
                </template>
              </a-input>
              <a-button size="small" @click="selectAllApiPermissions">
                全选
              </a-button>
              <a-button size="small" @click="clearAllApiPermissions">
                清空
              </a-button>
              <span class="text-sm text-gray-500">
                已选择 {{ formData.api_permission_ids?.length || 0 }} /
                {{ filteredApiPermissions.length }} 项
              </span>
            </div>
          </template>
          <a-form-item-rest>
            <a-checkbox-group v-model:value="formData.api_permission_ids">
              <div class="permission-list">
                <div
                  v-for="(apis, menuName) in apiPermissionsGrouped"
                  :key="menuName"
                  class="permission-group"
                >
                  <a-form-item no-style>
                    <div class="permission-group-header">
                      <div
                        class="permission-group-header-left"
                        @click="toggleApiGroupCollapsed(menuName)"
                      >
                        <span class="collapse-icon">
                          {{ apiGroupCollapsed[menuName] ? '▶' : '▼' }}
                        </span>
                        <span class="permission-group-title">
                          {{ menuName }}
                        </span>
                      </div>
                      <div class="permission-group-actions">
                        <a-space>
                          <a-button
                            size="small"
                            @click="selectApiPermissionsByMenu(menuName)"
                          >
                            全选
                          </a-button>
                          <a-button
                            size="small"
                            @click="clearApiPermissionsByMenu(menuName)"
                          >
                            清空
                          </a-button>
                        </a-space>
                        <span class="permission-count">
                          已选择 {{ getApiPermissionSelectedCount(menuName) }} /
                          {{ apis.length }} 项
                        </span>
                      </div>
                    </div>
                  </a-form-item>
                  <div
                    v-show="!apiGroupCollapsed[menuName]"
                    class="permission-items"
                  >
                    <a-checkbox
                      v-for="api in apis"
                      :key="api.id"
                      :value="api.id"
                    >
                      <Tag color="green" class="mr-1">{{ api.code }}</Tag>
                      {{ api.name }}
                    </a-checkbox>
                  </div>
                </div>
                <div
                  v-if="Object.keys(apiPermissionsGrouped).length === 0"
                  class="w-full py-8 text-center text-gray-400"
                >
                  暂无接口权限
                </div>
              </div>
            </a-checkbox-group>
          </a-form-item-rest>
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

.permission-description {
  margin-bottom: 0.5rem;
  font-size: 0.875rem;
  color: rgb(107, 114, 128);
}

.permission-tree {
  max-height: 200px;
  overflow-y: auto;
  border: 1px solid rgb(217, 217, 217);
  padding: 8px;
  border-radius: 4px;
}

.permission-list {
  max-height: 300px;
  overflow-y: auto;
  overflow-x: hidden;
}

.permission-group {
  margin-bottom: 1rem;
}

.permission-group-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.5rem;
  padding: 0.5rem;
  background-color: rgb(249, 250, 251);
  border-radius: 4px;
}

.permission-group-header-left {
  display: flex;
  align-items: center;
  cursor: pointer;
  user-select: none;
}

.collapse-icon {
  display: inline-block;
  width: 16px;
  margin-right: 8px;
  font-size: 12px;
  color: rgb(107, 114, 128);
  transition: transform 0.2s;
}

.permission-group-title {
  font-weight: 700;
  color: rgb(75, 85, 99);
}

.permission-group-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.permission-count {
  font-size: 0.875rem;
  color: rgb(107, 114, 128);
}

.permission-items {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 0 0.5rem;
}
</style>
