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

/* ---------------- 表单配置数据 ---------------- */
const ruleTypesMap = ref<SettingApi.RuleTypesMap>({});
const typeOptions = ref<SettingApi.TypeOption[]>([]);
const formWarnings = ref<string[]>([]);

const loadFormConfig = async () => {
  try {
    const res = await getSettingFormConfigApi();
    typeOptions.value = res.type_options || [];
    ruleTypesMap.value = res.rule_types || {};
    formWarnings.value = res.warnings || [];
  } catch (error) {
    console.error('加载表单配置失败:', error);
  }
};

/* ---------------- 分组下拉数据 ---------------- */
const groupOptions = ref<Array<{ label: string; value: number }>>([]);

const loadGroupOptions = async () => {
  try {
    const tree = await getSettingGroupAllApi();
    const flatten = (
      items: SettingApi.SettingGroup[],
    ): Array<{ label: string; value: number }> => {
      const result: Array<{ label: string; value: number }> = [];
      for (const item of items) {
        result.push({ label: item.name, value: item.id });
        if (item.children?.length) {
          result.push(...flatten(item.children));
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

const handleCreate = () => {
  editingItem.value = null;
  itemModalVisible.value = true;
};

const handleEdit = (record: SettingApi.SettingItem) => {
  editingItem.value = record;
  itemModalVisible.value = true;
};

const onModalSuccess = () => {
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
    title: '当前值',
    dataIndex: 'value',
    ellipsis: true,
  },
  { title: '排序', dataIndex: 'sort', width: 80 },
  { title: '操作', key: 'action', width: 160 },
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
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate"> 新增设置项 </a-button>
      <a-button class="ml-2" @click="() => loadData(searchParams)">
        刷新
      </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4">
      <a-form-item label="分组">
        <a-select
          v-model:value="searchParams.group_id"
          placeholder="请选择分组"
          allow-clear
          style="width: 200px"
          :options="groupOptions"
        />
      </a-form-item>
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="名称/编码"
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
          :options="
            typeOptions.map((t) => ({ label: t.label, value: t.value }))
          "
        />
      </a-form-item>
      <a-form-item>
        <a-button type="primary" @click="() => { pagination.current = 1; loadData(searchParams); }">
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
      :scroll="{ x: 1000 }"
      row-key="id"
      @change="(newPagination) => {
        pagination.current = newPagination.current;
        pagination.pageSize = newPagination.pageSize;
        loadData(searchParams);
      }"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.dataIndex === 'type'">
          <a-tag :color="TYPE_LABEL_MAP[record.type]?.color || 'default'">
            {{ TYPE_LABEL_MAP[record.type]?.label || record.type }}
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

    <!-- 设置项弹窗 -->
    <ItemModal
      v-model:visible="itemModalVisible"
      :group-options="groupOptions"
      :edit-data="editingItem"
      :rule-types-map="ruleTypesMap"
      :type-options="typeOptions"
      :form-warnings="formWarnings"
      @success="onModalSuccess"
    />
  </div>
</template>
