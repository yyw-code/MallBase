<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import { onMounted, ref } from 'vue';

import {
  deleteSettingItemApi,
  getSettingFormConfigApi,
  getSettingGroupAllApi,
  getSettingItemListApi,
} from '#/api/setting';
import { useTableCrud } from '#/composables/useTableCrud';

import ItemDetailDrawer from './item-detail-drawer.vue';
import ItemModal from './item-modal.vue';

defineOptions({ name: 'SettingItem' });

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

const DISPLAY_TYPE_LABEL_MAP: Record<string, string> = {
  category: '目录',
  page: '独立页面',
  tab: '选项卡',
};

type GroupOption = {
  code: string;
  disabled?: boolean;
  display_type?: SettingApi.SettingGroup['display_type'];
  label: string;
  value: number;
};

/* ---------------- 表单配置数据 ---------------- */
const ruleTypesMap = ref<SettingApi.RuleTypesMap>({});
const typeOptions = ref<SettingApi.TypeOption[]>([]);
const formWarnings = ref<string[]>([]);
const uiComponentOptions = ref<SettingApi.UiComponentOption[]>([]);
const uiOptionSourceOptions = ref<SettingApi.UiOptionSourceOption[]>([]);

const loadFormConfig = async () => {
  try {
    const res = await getSettingFormConfigApi();
    typeOptions.value = res.type_options || [];
    ruleTypesMap.value = res.rule_types || {};
    formWarnings.value = res.warnings || [];
    uiComponentOptions.value = res.ui_components || [];
    uiOptionSourceOptions.value = res.option_sources || [];
  } catch (error) {
    console.error('加载表单配置失败:', error);
  }
};

/* ---------------- 分组下拉数据 ---------------- */
const groupOptions = ref<GroupOption[]>([]);

const loadGroupOptions = async () => {
  try {
    const tree = await getSettingGroupAllApi();
    const flatten = (
      items: SettingApi.SettingGroup[],
      parents: string[] = [],
    ): GroupOption[] => {
      const result: GroupOption[] = [];
      for (const item of items) {
        const pathNames = [...parents, item.name];
        const displayTypeLabel =
          DISPLAY_TYPE_LABEL_MAP[item.display_type || 'page'] || '独立页面';
        result.push({
          code: item.code,
          disabled: item.display_type !== 'page',
          display_type: item.display_type,
          label: `${pathNames.join(' / ')}（${displayTypeLabel}）`,
          value: item.id,
        });
        if (item.children?.length) {
          result.push(...flatten(item.children, pathNames));
        }
      }
      return result;
    };
    groupOptions.value = flatten(tree);
  } catch (error) {
    console.error('加载分组列表失败:', error);
  }
};

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  SettingApi.SettingItem,
  SettingApi.ItemListParams
>(
  {
    delete: deleteSettingItemApi,
    list: getSettingItemListApi,
  },
  { immediateLoad: false },
);

/* ---------------- 搜索参数 ---------------- */
const searchParams = ref({
  group_id: undefined as number | undefined,
  keyword: '',
  type: undefined as string | undefined,
});

const resetSearch = () => {
  searchParams.value = {
    group_id: undefined,
    keyword: '',
    type: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

/* ---------------- 弹窗 ---------------- */
const itemModalVisible = ref(false);
const editingItem = ref<null | SettingApi.SettingItem>(null);
const detailDrawerVisible = ref(false);
const detailItem = ref<null | SettingApi.SettingItem>(null);

const handleCreate = () => {
  editingItem.value = null;
  itemModalVisible.value = true;
};

const handleDetail = (record: SettingApi.SettingItem) => {
  detailItem.value = record;
  detailDrawerVisible.value = true;
};

const handleEdit = (record: SettingApi.SettingItem) => {
  if (record.is_system === 1) return;
  editingItem.value = record;
  itemModalVisible.value = true;
};

const onModalSuccess = () => {
  loadData(searchParams.value);
};

const handleTableChange = (newPagination: {
  current?: number;
  pageSize?: number;
}) => {
  pagination.current = newPagination.current || 1;
  pagination.pageSize = newPagination.pageSize || pagination.pageSize;
  loadData(searchParams.value);
};

/* ---------------- 表格列 ---------------- */
const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '名称', dataIndex: 'name', width: 140 },
  { title: '编码', dataIndex: 'code', width: 180 },
  {
    title: '类型',
    dataIndex: 'type',
    width: 100,
  },
  {
    title: '页内分组',
    dataIndex: 'section',
    width: 120,
  },
  { title: '来源', dataIndex: 'is_system', width: 100 },
  {
    title: '当前值',
    dataIndex: 'value',
    ellipsis: true,
  },
  { title: '排序', dataIndex: 'sort', width: 80 },
  { title: '操作', key: 'action', width: 220 },
];

/* ---------------- 初始化 ---------------- */
onMounted(() => {
  loadFormConfig();
  loadGroupOptions();
  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">设置项管理</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SettingItemCreate'"
        >
          新增设置项
        </a-button>
        <a-button
          @click="() => loadData(searchParams)"
          v-access:code="'SettingItemList'"
        >
          刷新
        </a-button>
      </div>
    </div>

    <!-- 搜索表单 -->
    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item label="分组" class="mb-0 md:col-span-2 xl:col-span-2">
          <a-select
            v-model:value="searchParams.group_id"
            placeholder="请选择分组"
            allow-clear
            show-search
            option-filter-prop="label"
            class="w-full"
            :options="groupOptions"
          />
        </a-form-item>
        <a-form-item label="关键词" class="mb-0">
          <a-input
            v-model:value="searchParams.keyword"
            placeholder="名称/编码"
            allow-clear
            class="w-full"
          />
        </a-form-item>
        <a-form-item label="类型" class="mb-0">
          <a-select
            v-model:value="searchParams.type"
            placeholder="请选择"
            allow-clear
            class="w-full"
            :options="
              typeOptions.map((t) => ({ label: t.label, value: t.value }))
            "
          />
        </a-form-item>
        <a-form-item class="mb-0 md:col-span-3 xl:col-span-6">
          <div class="flex justify-end gap-2">
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
        :scroll="{ x: 1120 }"
        row-key="id"
        @change="handleTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.dataIndex === 'type'">
            <a-tag :color="TYPE_LABEL_MAP[record.type]?.color || 'default'">
              {{ TYPE_LABEL_MAP[record.type]?.label || record.type }}
            </a-tag>
          </template>

          <template v-if="column.dataIndex === 'section'">
            <a-tag v-if="record.resolved_ui?.section || record.ui?.section">
              {{ record.resolved_ui?.section || record.ui?.section }}
            </a-tag>
            <span v-else class="text-xs text-gray-400">-</span>
          </template>

          <template v-if="column.dataIndex === 'is_system'">
            <a-tag :color="record.is_system === 1 ? 'blue' : 'default'">
              {{ record.is_system === 1 ? '系统内置' : '用户添加' }}
            </a-tag>
          </template>

          <template v-if="column.dataIndex === 'value'">
            <span
              class="max-w-xs truncate text-xs text-gray-500"
              :title="record.value"
            >
              {{ record.value || '-' }}
            </span>
          </template>

          <template v-if="column.key === 'action'">
            <a-space>
              <a-button
                type="link"
                size="small"
                @click="handleDetail(record)"
                v-access:code="'SettingItemList'"
              >
                详情
              </a-button>
              <a-button
                type="link"
                size="small"
                :disabled="record.is_system === 1"
                @click="handleEdit(record)"
                v-access:code="'SettingItemUpdate'"
              >
                编辑
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                :disabled="record.is_system === 1"
                @click="handleDelete(record, 'name')"
                v-access:code="'SettingItemDelete'"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <!-- 设置项弹窗 -->
    <ItemModal
      v-model:visible="itemModalVisible"
      :group-options="groupOptions"
      :edit-data="editingItem"
      :rule-types-map="ruleTypesMap"
      :type-options="typeOptions"
      :form-warnings="formWarnings"
      :ui-component-options="uiComponentOptions"
      :ui-option-source-options="uiOptionSourceOptions"
      @success="onModalSuccess"
    />

    <!-- 设置项详情 -->
    <ItemDetailDrawer
      v-model:visible="detailDrawerVisible"
      :detail-data="detailItem"
      :group-options="groupOptions"
      @success="onModalSuccess"
    />
  </div>
</template>
