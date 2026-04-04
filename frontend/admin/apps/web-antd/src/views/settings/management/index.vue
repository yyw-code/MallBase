<script lang="ts" setup>
import type { TableColumnType } from 'ant-design-vue';

import type { SettingApi } from '#/api/setting';

import { computed, onMounted, ref } from 'vue';

import { ColPage } from '@vben/common-ui';
import { IconifyIcon } from '@vben/icons';

import { message, Modal } from 'ant-design-vue';

import {
  deleteSettingGroupApi,
  deleteSettingItemApi,
  getSettingFormConfigApi,
  getSettingGroupTreeApi,
  getSettingItemListApi,
} from '#/api/setting';

import GroupModal from './group-modal.vue';
import ItemModal from './item-modal.vue';

defineOptions({ name: 'SettingManagement' });

// ==================== 类型标签映射 ====================
const TYPE_LABEL_MAP: Record<string, { color: string; label: string }> = {
  checkbox: { color: 'purple', label: '多选' },
  editor: { color: 'magenta', label: '富文本' },
  file: { color: 'cyan', label: '文件' },
  files: { color: 'cyan', label: '多文件' },
  image: { color: 'geekblue', label: '图片' },
  images: { color: 'geekblue', label: '多图' },
  input: { color: 'blue', label: '文本' },
  json: { color: 'volcano', label: 'JSON' },
  number: { color: 'orange', label: '数字' },
  password: { color: 'red', label: '密码' },
  radio: { color: 'purple', label: '单选' },
  select: { color: 'lime', label: '下拉' },
  switch: { color: 'green', label: '开关' },
  textarea: { color: 'blue', label: '多行文本' },
};

// ==================== 表格列定义 ====================
const tableColumns: TableColumnType<SettingApi.SettingItem>[] = [
  { dataIndex: 'id', title: 'ID', width: 80 },
  { dataIndex: 'name', ellipsis: true, title: '名称', width: 160 },
  { dataIndex: 'code', ellipsis: true, title: '编码', width: 200 },
  { dataIndex: 'type', title: '类型', width: 120 },
  { dataIndex: 'value', ellipsis: true, title: '当前值' },
  { dataIndex: 'sort', title: '排序', width: 90 },
  { key: 'action', title: '操作', width: 140 },
];

// ==================== 表单配置数据（页面级缓存） ====================
const ruleTypesMap = ref<SettingApi.RuleTypesMap>({});
const typeOptions = ref<SettingApi.TypeOption[]>([]);

/** 加载表单配置（类型选项 + 验证规则，只调用一次） */
const loadFormConfig = async () => {
  try {
    const res = await getSettingFormConfigApi();
    typeOptions.value = res.type_options || [];
    ruleTypesMap.value = res.rule_types || {};
  } catch (error) {
    console.error('加载表单配置失败:', error);
  }
};

// ==================== 分组树相关 ====================
const groupTree = ref<SettingApi.SettingGroup[]>([]);
const groupTreeLoading = ref(false);
const selectedGroupId = ref<number | undefined>();
const selectedGroupName = ref<string>('');
const expandedKeys = ref<number[]>([]);
const searchValue = ref('');

/** 统计分组总数 */
const groupCount = computed(() => {
  let count = 0;
  const traverse = (items: SettingApi.SettingGroup[]) => {
    for (const item of items) {
      count++;
      if (item.children?.length) traverse(item.children);
    }
  };
  traverse(groupTree.value);
  return count;
});

/** 搜索过滤后的树 */
const filteredGroupTree = computed(() => {
  const keyword = searchValue.value.trim().toLowerCase();
  if (!keyword) return groupTree.value;

  const filterTree = (
    nodes: SettingApi.SettingGroup[],
  ): SettingApi.SettingGroup[] => {
    const result: SettingApi.SettingGroup[] = [];
    for (const node of nodes) {
      const childMatches = node.children?.length
        ? filterTree(node.children)
        : [];
      if (
        node.name.toLowerCase().includes(keyword) ||
        node.code.toLowerCase().includes(keyword) ||
        childMatches.length > 0
      ) {
        result.push({ ...node, children: childMatches });
      }
    }
    return result;
  };
  return filterTree(groupTree.value);
});

/** 获取所有树节点的 key */
const getAllKeys = (data: SettingApi.SettingGroup[]): number[] => {
  const keys: number[] = [];
  const traverse = (items: SettingApi.SettingGroup[]) => {
    for (const item of items) {
      keys.push(item.id);
      if (item.children?.length) traverse(item.children);
    }
  };
  traverse(data);
  return keys;
};

/** 加载分组树 */
const loadGroupTree = async () => {
  groupTreeLoading.value = true;
  try {
    groupTree.value = await getSettingGroupTreeApi();
    expandedKeys.value = getAllKeys(groupTree.value);

    // 默认选中第一个节点
    if (!selectedGroupId.value && groupTree.value.length > 0) {
      const first = groupTree.value[0]!;
      selectedGroupId.value = first.id;
      selectedGroupName.value = first.name;
      loadItems(first.id);
    }
  } catch (error) {
    console.error('加载分组树失败:', error);
    message.error('加载分组树失败');
  } finally {
    groupTreeLoading.value = false;
  }
};

/** 树节点选中 */
const handleGroupSelect = (selectedKeys: number[], info: any) => {
  if (selectedKeys.length > 0) {
    selectedGroupId.value = selectedKeys[0];
    selectedGroupName.value = info?.node?.name ?? '';
    if (selectedGroupId.value) loadItems(selectedGroupId.value);
  } else {
    selectedGroupId.value = undefined;
    selectedGroupName.value = '';
    settingItems.value = [];
  }
};

// ==================== 分组弹窗 ====================
const groupModalVisible = ref(false);
const editingGroup = ref<null | SettingApi.SettingGroup>(null);

const handleCreateGroup = () => {
  editingGroup.value = null;
  groupModalVisible.value = true;
};

const handleEditGroup = (node: SettingApi.SettingGroup) => {
  editingGroup.value = node;
  groupModalVisible.value = true;
};

const handleDeleteGroup = (node: SettingApi.SettingGroup) => {
  Modal.confirm({
    content: `确定要删除分组「${node.name}」吗？将同时删除子分组和所有设置项。`,
    okType: 'danger',
    title: '删除分组',
    async onOk() {
      await deleteSettingGroupApi(node.id);
      message.success('删除成功');
      if (selectedGroupId.value === node.id) {
        selectedGroupId.value = undefined;
        selectedGroupName.value = '';
        settingItems.value = [];
      }
      await loadGroupTree();
    },
  });
};

const onGroupModalSuccess = () => loadGroupTree();

// ==================== 设置项列表 ====================
const settingItems = ref<SettingApi.SettingItem[]>([]);
const itemsLoading = ref(false);

/** 加载设置项 */
const loadItems = async (groupId: number) => {
  itemsLoading.value = true;
  try {
    const res = await getSettingItemListApi({ group_id: groupId });
    settingItems.value = res.list;
  } catch (error) {
    console.error('加载设置项失败:', error);
    message.error('加载设置项失败');
  } finally {
    itemsLoading.value = false;
  }
};

// ==================== 设置项弹窗 ====================
const itemModalVisible = ref(false);
const editingItem = ref<null | SettingApi.SettingItem>(null);

const handleCreateItem = () => {
  if (!selectedGroupId.value) {
    message.warning('请先选择一个分组');
    return;
  }
  editingItem.value = null;
  itemModalVisible.value = true;
};

const handleEditItem = (item: SettingApi.SettingItem) => {
  editingItem.value = item;
  itemModalVisible.value = true;
};

const handleDeleteItem = (item: SettingApi.SettingItem) => {
  Modal.confirm({
    content: `确定要删除设置项「${item.name}」吗？`,
    okType: 'danger',
    title: '删除设置项',
    async onOk() {
      await deleteSettingItemApi(item.id);
      message.success('删除成功');
      if (selectedGroupId.value) {
        await loadItems(selectedGroupId.value);
      }
    },
  });
};

const onItemModalSuccess = () => {
  if (selectedGroupId.value) loadItems(selectedGroupId.value);
};

// ==================== 初始化 ====================
onMounted(() => {
  loadFormConfig();
  loadGroupTree();
});
</script>

<template>
  <ColPage
    auto-content-height
    :left-width="25"
    :left-min-width="15"
    :left-max-width="40"
    :left-collapsible="true"
    :left-collapsed-width="5"
    :resizable="true"
    :split-line="true"
    :split-handle="true"
  >
    <!-- 左侧：分组树 -->
    <template #left="{ isCollapsed, expand }">
      <div v-if="isCollapsed" class="flex items-center justify-center p-2">
        <a-tooltip title="展开分组导航" placement="right">
          <a-button shape="circle" type="primary" size="small" @click="expand">
            <template #icon>
              <span class="i-ant-design:menu-unfold-outlined"></span>
            </template>
          </a-button>
        </a-tooltip>
      </div>
      <div
        v-else
        class="flex h-full flex-col overflow-hidden rounded-xl border border-gray-200/70 bg-white shadow-sm"
      >
        <!-- 头部区域 -->
        <div
          class="border-b border-gray-100 bg-gradient-to-b from-gray-50/80 to-white px-5 pb-4 pt-5"
        >
          <div class="mb-4 flex items-center justify-between">
            <div>
              <h3
                class="m-0 text-base font-semibold leading-tight text-gray-800"
              >
                设置分组
              </h3>
              <p class="m-0 mt-0.5 text-xs text-gray-400">
                共 {{ groupCount }} 个分组
              </p>
            </div>
            <a-button type="primary" @click="handleCreateGroup">
              <template #icon>
                <span class="i-ant-design:plus-outlined"></span>
              </template>
              新增分组
            </a-button>
          </div>
          <!-- 搜索框 -->
          <a-input
            v-model:value="searchValue"
            placeholder="搜索分组..."
            allow-clear
            class="!rounded-md"
          >
            <template #prefix>
              <span class="i-ant-design:search-outlined text-gray-400"></span>
            </template>
          </a-input>
        </div>

        <!-- 分组树内容 -->
        <div class="setting-tree flex-1 overflow-y-auto px-3 py-3">
          <a-spin :spinning="groupTreeLoading">
            <a-tree
              v-if="filteredGroupTree.length > 0"
              :tree-data="filteredGroupTree"
              :field-names="{ title: 'name', key: 'id', children: 'children' }"
              :expanded-keys="expandedKeys"
              :selected-keys="selectedGroupId ? [selectedGroupId] : []"
              block-node
              @expand="(keys: any[]) => (expandedKeys = keys)"
              @select="handleGroupSelect"
            >
              <template #title="{ data }">
                <div class="tree-node flex items-center justify-between gap-2">
                  <IconifyIcon
                    v-if="data.icon"
                    :icon="data.icon"
                    class="shrink-0 text-base"
                  />
                  <span
                    class="flex-1 truncate"
                    :class="{ 'text-gray-400': data.status === 0 }"
                  >
                    {{ data.name }}
                  </span>
                  <span class="inline-flex">
                    <a-button
                      type="link"
                      size="small"
                      @click.stop="handleEditGroup(data)"
                    >
                      编辑
                    </a-button>
                    <a-button
                      type="link"
                      danger
                      size="small"
                      @click.stop="handleDeleteGroup(data)"
                    >
                      删除
                    </a-button>
                  </span>
                </div>
              </template>
            </a-tree>

            <a-empty v-else description="暂无分组" class="mt-8" />
          </a-spin>
        </div>
      </div>
    </template>

    <!-- 右侧：设置项列表 -->
    <div
      class="ml-3 flex h-full min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-gray-200/70 bg-white shadow-sm"
    >
      <template v-if="selectedGroupId">
        <!-- 右侧头部 -->
        <div
          class="flex items-center justify-between border-b border-gray-100 bg-gradient-to-b from-gray-50/80 to-white px-6 py-5"
        >
          <div class="flex items-center gap-3">
            <div>
              <h3
                class="m-0 text-base font-semibold leading-tight text-gray-800"
              >
                设置项
              </h3>
              <p class="m-0 mt-0.5 text-xs text-gray-400">
                {{ selectedGroupName }} · {{ settingItems.length }} 项
              </p>
            </div>
          </div>
          <a-button type="primary" @click="handleCreateItem">
            <template #icon>
              <span class="i-ant-design:plus-outlined"></span>
            </template>
            新增设置项
          </a-button>
        </div>

        <!-- 表格区域 -->
        <div class="flex-1 overflow-auto p-5">
          <a-spin :spinning="itemsLoading">
            <a-table
              :columns="tableColumns"
              :data-source="settingItems"
              :pagination="false"
              :locale="{ emptyText: '该分组暂无设置项' }"
              row-key="id"
              size="middle"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.dataIndex === 'type'">
                  <a-tag
                    :color="TYPE_LABEL_MAP[record.type]?.color || 'default'"
                  >
                    {{ TYPE_LABEL_MAP[record.type]?.label || record.type }}
                  </a-tag>
                </template>

                <template v-else-if="column.dataIndex === 'value'">
                  <span
                    class="max-w-xs truncate text-xs text-gray-500"
                    :title="record.value"
                  >
                    {{ record.value || '-' }}
                  </span>
                </template>

                <template v-else-if="column.key === 'action'">
                  <a-space :size="8">
                    <a-tooltip title="编辑">
                      <a-button
                        type="link"
                        size="small"
                        @click="handleEditItem(record)"
                      >
                        编辑
                      </a-button>
                    </a-tooltip>
                    <a-divider type="vertical" class="!mx-0" />
                    <a-tooltip title="删除">
                      <a-button
                        type="link"
                        danger
                        size="small"
                        @click="handleDeleteItem(record)"
                      >
                        删除
                      </a-button>
                    </a-tooltip>
                  </a-space>
                </template>
              </template>
            </a-table>
          </a-spin>
        </div>
      </template>

      <!-- 未选中分组时的引导 -->
      <template v-else>
        <div class="flex flex-1 flex-col items-center justify-center gap-4">
          <div
            class="flex h-20 w-20 items-center justify-center rounded-2xl bg-gray-50"
          >
            <span
              class="i-ant-design:apartment-outlined text-4xl text-gray-300"
            ></span>
          </div>
          <div class="text-center">
            <p class="m-0 text-base font-medium text-gray-500">
              选择分组查看设置项
            </p>
            <p class="m-0 mt-1 text-sm text-gray-400">
              请在左侧分组树中选择一个分组
            </p>
          </div>
        </div>
      </template>
    </div>

    <!-- 分组弹窗 -->
    <GroupModal
      v-model:visible="groupModalVisible"
      :edit-data="editingGroup"
      @success="onGroupModalSuccess"
    />

    <!-- 设置项弹窗 -->
    <ItemModal
      v-model:visible="itemModalVisible"
      :group-id="selectedGroupId || 0"
      :edit-data="editingItem"
      :rule-types-map="ruleTypesMap"
      :type-options="typeOptions"
      @success="onItemModalSuccess"
    />
  </ColPage>
</template>

<style lang="css" scoped>
/* 覆盖 Ant Design Vue 树节点样式 */
.setting-tree :deep(.ant-tree-node-content-wrapper) {
  line-height: 42px;
  height: auto !important;
}

/* 隐藏叶子节点前的空 switcher */
.setting-tree :deep(.ant-tree-switcher-noop) {
  display: none;
}

/* 选中节点高亮 — 跟随主题色 */
.setting-tree :deep(.ant-tree-node-content-wrapper.ant-tree-node-selected) {
  color: hsl(var(--primary)) !important;
}
</style>
