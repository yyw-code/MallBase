<script lang="ts" setup>
import type { UploadFile, UploadProps } from 'ant-design-vue';
import type { FormInstance, Rule } from 'ant-design-vue/es/form';

import type { ClientPageApi } from '#/api/client';

import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { message, Modal, Switch } from 'ant-design-vue';

import {
  createClientPageApi,
  createClientPageCategoryApi,
  deleteClientPageApi,
  deleteClientPageCategoryApi,
  getAllClientPageCategoriesApi,
  getClientPageCategoryListApi,
  getClientPageInfoApi,
  getClientPageListApi,
  importClientPageApi,
  updateClientPageApi,
  updateClientPageCategoryApi,
  updateClientPageCategoryStatusApi,
} from '#/api/client';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'ClientPageManagement' });

const pagesJsonPlaceholder =
  '粘贴 pages.json 内容，例如：{"pages":[...],"subPackages":[...]}';
const CATEGORY_TAG_COLORS = [
  'blue',
  'green',
  'geekblue',
  'purple',
  'orange',
  'cyan',
  'magenta',
  'gold',
  'default',
];

type ImportMode = 'file' | 'json';
type PageManageTab = 'categories' | 'pages';
type PageCategoryRow = {
  category: ClientPageApi.PageCategory;
  children: PageTablePageRow[];
  id: string;
  isCategory: true;
  name: string;
  path: string;
  tableKey: string;
};
type PageTablePageRow = ClientPageApi.PageItem & {
  tableKey: string;
};
type PageTableRow = PageCategoryRow | PageTablePageRow;

const PAGE_TYPE_OPTIONS: Array<{
  color: string;
  label: string;
  value: ClientPageApi.PageType;
}> = [
  { label: '底部导航', value: 'tab', color: 'blue' },
  { label: '主包页面', value: 'page', color: 'green' },
  { label: '分包页面', value: 'subpackage', color: 'purple' },
];

const SOURCE_OPTIONS: Array<{
  color: string;
  label: string;
  value: ClientPageApi.PageSource;
}> = [
  { label: '自动导入', value: 'auto', color: 'cyan' },
  { label: '手动维护', value: 'manual', color: 'green' },
  { label: '系统内置', value: 'system', color: 'gold' },
];

const FORM_SOURCE_OPTIONS = SOURCE_OPTIONS.filter(
  (item) => item.value !== 'system',
);

const pageCategories = ref<ClientPageApi.PageCategoryItem[]>([]);
const pageCategoryOptions = computed(() =>
  pageCategories.value.map((item) => ({
    label: item.name,
    value: item.code,
  })),
);

const typeMap = computed(() =>
  Object.fromEntries(PAGE_TYPE_OPTIONS.map((item) => [item.value, item])),
);
const categoryMap = computed(() =>
  Object.fromEntries(
    pageCategories.value.map((item, index) => [
      item.code,
      {
        color: CATEGORY_TAG_COLORS[index % CATEGORY_TAG_COLORS.length],
        label: item.name,
      },
    ]),
  ),
);
const sourceMap = computed(() =>
  Object.fromEntries(SOURCE_OPTIONS.map((item) => [item.value, item])),
);

const { tableData, loading, pagination, loadData } = useTableCrud<
  ClientPageApi.PageItem,
  ClientPageApi.ListParams
>(
  {
    delete: deleteClientPageApi,
    list: getClientPageListApi,
  },
  { defaultPageSize: 500, immediateLoad: false },
);

const searchParams = ref<ClientPageApi.ListParams>({
  category: undefined,
  keyword: '',
  page_type: undefined,
  source: undefined,
  status: undefined,
});

const route = useRoute();

const getActiveTabByPath = (path: string): PageManageTab =>
  path.endsWith('/category') ? 'categories' : 'pages';

const activeTab = ref<PageManageTab>(getActiveTabByPath(route.path));

watch(
  () => route.path,
  (path) => {
    activeTab.value = getActiveTabByPath(path);
  },
);

const loadPageCategories = async () => {
  pageCategories.value = await getAllClientPageCategoriesApi();
};

const resetSearch = () => {
  searchParams.value = {
    category: undefined,
    keyword: '',
    page_type: undefined,
    source: undefined,
    status: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

const columns = [
  { title: 'ID', dataIndex: 'id', width: 100 },
  { title: '页面名称', dataIndex: 'name', width: 190 },
  { title: '页面路径', dataIndex: 'path', width: 260, ellipsis: true },
  { title: '页面分类', dataIndex: 'category', width: 120 },
  { title: '页面类型', dataIndex: 'page_type', width: 120 },
  { title: '分包 root', dataIndex: 'package_root', width: 130 },
  { title: '登录', dataIndex: 'need_login', width: 90 },
  { title: '来源', dataIndex: 'source', width: 110 },
  { title: '排序', dataIndex: 'sort', width: 90 },
  { title: '状态', dataIndex: 'status', width: 90 },
  { title: '更新时间', dataIndex: 'update_time', width: 170 },
  { title: '操作', key: 'action', fixed: 'right', width: 160 },
];

const treeTableData = computed<PageTableRow[]>(() => {
  const rows: PageTableRow[] = [];
  const groupedCodes = new Set<string>();

  const pushCategoryGroup = (category: {
    label: string;
    value: ClientPageApi.PageCategory;
  }) => {
    const children = tableData.value
      .filter((item) => (item.category || 'other') === category.value)
      .map((item) => ({
        ...item,
        tableKey: `page-${item.id}`,
      }));
    if (children.length === 0) return;

    groupedCodes.add(category.value);
    rows.push({
      category: category.value,
      children,
      id: '',
      isCategory: true,
      name: `${category.label}（${children.length}）`,
      path: '',
      tableKey: `category-${category.value}`,
    });
  };

  pageCategoryOptions.value.forEach((category) => pushCategoryGroup(category));

  [
    ...new Set(
      tableData.value
        .map((item) => item.category || 'other')
        .filter((category) => !groupedCodes.has(category)),
    ),
  ].forEach((category) => {
    pushCategoryGroup({
      label: category,
      value: category,
    });
  });

  return rows;
});

const modalVisible = ref(false);
const modalLoading = ref(false);
const formRef = ref<FormInstance>();
const editingId = ref<null | number>(null);

const formData = reactive<ClientPageApi.SaveParams>({
  name: '',
  path: '',
  page_type: 'page',
  category: 'other',
  package_root: null,
  need_login: 0,
  source: 'manual',
  remark: null,
  sort: 0,
  status: 1,
});

const enabledPageCategoryOptions = computed(() => {
  const options = pageCategories.value
    .filter((item) => item.status === 1)
    .map((item) => ({
      label: item.name,
      value: item.code,
    }));

  if (
    formData.category &&
    !options.some((item) => item.value === formData.category)
  ) {
    const current = pageCategories.value.find(
      (item) => item.code === formData.category,
    );
    options.push({
      label: current?.name ?? formData.category,
      value: formData.category,
    });
  }

  return options;
});

const formRules: Record<string, Rule[]> = {
  category: [{ required: true, message: '请选择页面分类' }],
  name: [{ required: true, message: '请输入页面名称', whitespace: true }],
  page_type: [{ required: true, message: '请选择页面类型' }],
  path: [{ required: true, message: '请输入页面路径', whitespace: true }],
};

const modalTitle = computed(() => (editingId.value ? '编辑页面' : '新增页面'));

const isCategoryRow = (record: PageTableRow): record is PageCategoryRow =>
  'isCategory' in record && record.isCategory === true;

const isSystemPage = (record: PageTableRow) =>
  !isCategoryRow(record) && record.source === 'system';

const getRowClassName = (record: PageTableRow) =>
  isCategoryRow(record) ? 'client-page-category-row' : '';

const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    name: '',
    path: '',
    page_type: 'page',
    category: 'other',
    package_root: null,
    need_login: 0,
    source: 'manual',
    remark: null,
    sort: 0,
    status: 1,
  });
};

const handleCreate = () => {
  editingId.value = null;
  resetForm();
  modalVisible.value = true;
};

const handleEdit = async (record: PageTableRow) => {
  if (isCategoryRow(record)) return;
  if (isSystemPage(record)) {
    message.info('系统内置页面不支持编辑');
    return;
  }
  try {
    const detail = await getClientPageInfoApi(record.id);
    editingId.value = detail.id;
    resetForm();
    Object.assign(formData, {
      name: detail.name,
      path: detail.path,
      page_type: detail.page_type,
      category: detail.category || 'other',
      package_root: detail.package_root ?? null,
      need_login: detail.need_login ?? 0,
      source: detail.source || 'manual',
      remark: detail.remark ?? null,
      sort: detail.sort ?? 0,
      status: detail.status ?? 1,
    });
    modalVisible.value = true;
  } catch (error) {
    console.error('获取页面详情失败:', error);
    message.error('获取页面详情失败');
  }
};

const buildSubmitData = (): ClientPageApi.SaveParams => ({
  ...formData,
  package_root: formData.package_root || null,
  remark: formData.remark || null,
});

const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    modalLoading.value = true;
    const data = buildSubmitData();
    if (editingId.value) {
      await updateClientPageApi(editingId.value, data);
      message.success('更新成功');
    } else {
      await createClientPageApi(data);
      message.success('创建成功');
    }
    modalVisible.value = false;
    await loadData(searchParams.value);
  } catch (error: any) {
    if (!error?.errorFields) {
      console.error('保存页面失败:', error);
    }
  } finally {
    modalLoading.value = false;
  }
};

const handleDelete = (record: PageTableRow) => {
  if (isCategoryRow(record)) return;
  if (isSystemPage(record)) {
    message.info('系统内置页面不支持删除');
    return;
  }
  Modal.confirm({
    content: `确定要删除页面"${record.name}"吗？`,
    onOk: async () => {
      await deleteClientPageApi(record.id);
      message.success('删除成功');
      await loadData(searchParams.value);
    },
  });
};

const importVisible = ref(false);
const importLoading = ref(false);
const importMode = ref<ImportMode>('file');
const importForm = reactive<ClientPageApi.ImportParams>({
  pages_json: '',
});
const importFile = ref<File>();
const importFileList = ref<UploadFile[]>([]);

const categoryLoading = ref(false);
const categoryTableData = ref<ClientPageApi.PageCategoryItem[]>([]);
const categoryPagination = reactive({ current: 1, pageSize: 15, total: 0 });
const categorySearchParams = ref<ClientPageApi.CategoryListParams>({
  keyword: '',
  status: undefined,
});
const categoryModalVisible = ref(false);
const categoryModalLoading = ref(false);
const categoryFormRef = ref<FormInstance>();
const editingCategoryId = ref<null | number>(null);
const categoryFormData = reactive<ClientPageApi.CategorySaveParams>({
  code: '',
  description: null,
  name: '',
  sort: 0,
  status: 1,
});

const categoryColumns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '分类名称', dataIndex: 'name', width: 180 },
  { title: '编码', dataIndex: 'code', width: 160 },
  { title: '描述', dataIndex: 'description', ellipsis: true },
  { title: '系统', dataIndex: 'is_system', width: 90 },
  { title: '排序', dataIndex: 'sort', width: 90 },
  { title: '状态', dataIndex: 'status', width: 110 },
  { title: '更新时间', dataIndex: 'update_time', width: 170 },
  { title: '操作', key: 'action', fixed: 'right', width: 160 },
];

const categoryFormRules: Record<string, Rule[]> = {
  code: [
    { required: true, message: '请输入分类编码', whitespace: true },
    {
      pattern: /^[a-z][a-z0-9_]{0,29}$/,
      message: '只能使用小写字母、数字和下划线，且必须以字母开头',
    },
  ],
  name: [{ required: true, message: '请输入分类名称', whitespace: true }],
};

const categoryModalTitle = computed(() =>
  editingCategoryId.value ? '编辑页面分类' : '新增页面分类',
);

const loadCategoryData = async () => {
  categoryLoading.value = true;
  try {
    const result = await getClientPageCategoryListApi({
      ...categorySearchParams.value,
      limit: categoryPagination.pageSize,
      page: categoryPagination.current,
    });
    categoryTableData.value = result.list || [];
    categoryPagination.total = result.total || 0;
  } finally {
    categoryLoading.value = false;
  }
};

const resetCategorySearch = () => {
  categorySearchParams.value = {
    keyword: '',
    status: undefined,
  };
  categoryPagination.current = 1;
  loadCategoryData();
};

const submitCategorySearch = () => {
  categoryPagination.current = 1;
  loadCategoryData();
};

const handleCategoryTableChange = (pager: {
  current?: number;
  pageSize?: number;
}) => {
  categoryPagination.current = pager.current ?? categoryPagination.current;
  categoryPagination.pageSize = pager.pageSize ?? categoryPagination.pageSize;
  loadCategoryData();
};

const resetCategoryForm = () => {
  categoryFormRef.value?.resetFields();
  Object.assign(categoryFormData, {
    code: '',
    description: null,
    name: '',
    sort: 0,
    status: 1,
  });
};

const handleCreateCategory = () => {
  editingCategoryId.value = null;
  resetCategoryForm();
  categoryModalVisible.value = true;
};

const handleEditCategory = (record: ClientPageApi.PageCategoryItem) => {
  editingCategoryId.value = record.id;
  resetCategoryForm();
  Object.assign(categoryFormData, {
    code: record.code,
    description: record.description ?? null,
    name: record.name,
    sort: record.sort ?? 0,
    status: record.status ?? 1,
  });
  categoryModalVisible.value = true;
};

const buildCategorySubmitData = (): ClientPageApi.CategorySaveParams => ({
  ...categoryFormData,
  description: categoryFormData.description || null,
});

const refreshCategories = async () => {
  await Promise.all([loadPageCategories(), loadCategoryData()]);
};

const handleSubmitCategory = async () => {
  try {
    await categoryFormRef.value?.validate();
    categoryModalLoading.value = true;
    const data = buildCategorySubmitData();
    if (editingCategoryId.value) {
      await updateClientPageCategoryApi(editingCategoryId.value, data);
      message.success('更新成功');
    } else {
      await createClientPageCategoryApi(data);
      message.success('创建成功');
    }
    categoryModalVisible.value = false;
    await refreshCategories();
  } catch (error: any) {
    if (!error?.errorFields) {
      console.error('保存页面分类失败:', error);
    }
  } finally {
    categoryModalLoading.value = false;
  }
};

const handleDeleteCategory = (record: ClientPageApi.PageCategoryItem) => {
  Modal.confirm({
    content: `确定要删除页面分类"${record.name}"吗？`,
    title: '删除页面分类',
    onOk: async () => {
      await deleteClientPageCategoryApi(record.id);
      message.success('删除成功');
      await refreshCategories();
      await loadData(searchParams.value);
    },
  });
};

const handleCategoryStatusChange = async (
  record: ClientPageApi.PageCategoryItem,
  checked: boolean | number | string,
) => {
  const nextStatus = checked === true ? 1 : 0;
  try {
    await updateClientPageCategoryStatusApi(record.id, nextStatus);
    message.success('状态更新成功');
    await refreshCategories();
  } catch (error) {
    console.error('更新页面分类状态失败:', error);
    await refreshCategories();
  }
};

const resetImportForm = () => {
  importMode.value = 'file';
  importForm.pages_json = '';
  importFile.value = undefined;
  importFileList.value = [];
};

const openImportModal = () => {
  resetImportForm();
  importVisible.value = true;
};

const handleImportBeforeUpload: UploadProps['beforeUpload'] = (file) => {
  if (!file.name.toLowerCase().endsWith('.json')) {
    message.warning('请选择 pages.json 文件');
    return false;
  }

  importFile.value = file;
  importFileList.value = [file as unknown as UploadFile];
  return false;
};

const handleImportRemove = () => {
  importFile.value = undefined;
  importFileList.value = [];
  return true;
};

const handleImport = async () => {
  try {
    const payload: ClientPageApi.ImportParams = {};
    if (importMode.value === 'file') {
      if (!importFile.value) {
        message.warning('请先选择 pages.json 文件');
        return;
      }
      payload.file = importFile.value;
    } else {
      const pagesJson = importForm.pages_json?.trim() || '';
      if (!pagesJson) {
        message.warning('请粘贴 pages.json 内容');
        return;
      }
      payload.pages_json = pagesJson;
    }

    importLoading.value = true;
    const result = await importClientPageApi(payload);
    message.success(
      `导入完成：新增 ${result.created}，更新 ${result.updated}，跳过 ${result.skipped}`,
    );
    importVisible.value = false;
    await loadData(searchParams.value);
  } catch (error) {
    console.error('导入页面失败:', error);
  } finally {
    importLoading.value = false;
  }
};

onMounted(async () => {
  await Promise.all([loadPageCategories(), loadCategoryData()]);
  loadData(searchParams.value);
});
</script>

<template>
  <div class="client-page p-4">
    <div class="client-page__header">
      <div>
        <h2 class="client-page__title">页面库</h2>
      </div>
      <div class="client-page__actions">
        <a-button type="primary" @click="handleCreate">新增页面</a-button>
        <a-button @click="openImportModal">导入 pages.json</a-button>
        <a-button @click="() => loadData(searchParams)">刷新</a-button>
      </div>
    </div>

    <a-tabs v-model:active-key="activeTab">
      <a-tab-pane key="pages" tab="页面列表">
        <div class="client-page__filter">
          <a-form
            class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
          >
            <a-form-item class="mb-0" label="关键词">
              <a-input
                v-model:value="searchParams.keyword"
                allow-clear
                class="w-full"
                placeholder="名称/路径/备注"
              />
            </a-form-item>
            <a-form-item class="mb-0" label="页面分类">
              <a-select
                v-model:value="searchParams.category"
                :options="pageCategoryOptions"
                allow-clear
                class="w-full"
                placeholder="请选择"
              />
            </a-form-item>
            <a-form-item class="mb-0" label="页面类型">
              <a-select
                v-model:value="searchParams.page_type"
                :options="PAGE_TYPE_OPTIONS"
                allow-clear
                class="w-full"
                placeholder="请选择"
              />
            </a-form-item>
            <a-form-item class="mb-0" label="来源">
              <a-select
                v-model:value="searchParams.source"
                :options="SOURCE_OPTIONS"
                allow-clear
                class="w-full"
                placeholder="请选择"
              />
            </a-form-item>
            <a-form-item class="mb-0" label="状态">
              <a-select
                v-model:value="searchParams.status"
                allow-clear
                class="w-full"
                placeholder="请选择"
              >
                <a-select-option :value="1">启用</a-select-option>
                <a-select-option :value="0">禁用</a-select-option>
              </a-select>
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
                <a-button @click="resetSearch">重置</a-button>
              </div>
            </a-form-item>
          </a-form>
        </div>

        <div class="client-page__table">
          <div class="client-page__table-header">
            <div>
              <h3 class="client-page__table-title">页面列表</h3>
            </div>
            <div class="client-page__table-meta">
              {{ treeTableData.length }} 个分组 / {{ tableData.length }} 个页面
            </div>
          </div>

          <a-table
            :columns="columns"
            :data-source="treeTableData"
            :loading="loading"
            :pagination="false"
            :row-class-name="getRowClassName"
            :scroll="{ x: 1450 }"
            row-key="tableKey"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.dataIndex === 'id'">
                <span v-if="!isCategoryRow(record)">{{ record.id }}</span>
              </template>

              <template v-if="column.dataIndex === 'name'">
                <strong v-if="isCategoryRow(record)">{{ record.name }}</strong>
                <span v-else>{{ record.name }}</span>
              </template>

              <template v-if="column.dataIndex === 'path'">
                <span v-if="isCategoryRow(record)">分类分组</span>
                <span v-else>{{ record.path }}</span>
              </template>

              <template v-if="column.dataIndex === 'category'">
                <a-tag
                  :color="categoryMap[record.category]?.color || 'default'"
                >
                  {{ categoryMap[record.category]?.label || record.category }}
                </a-tag>
              </template>

              <template v-if="column.dataIndex === 'page_type'">
                <a-tag
                  v-if="!isCategoryRow(record)"
                  :color="typeMap[record.page_type]?.color || 'default'"
                >
                  {{ typeMap[record.page_type]?.label || record.page_type }}
                </a-tag>
              </template>

              <template v-if="column.dataIndex === 'package_root'">
                <span v-if="!isCategoryRow(record)">
                  {{ record.package_root || '-' }}
                </span>
              </template>

              <template v-if="column.dataIndex === 'source'">
                <a-tag
                  v-if="!isCategoryRow(record)"
                  :color="sourceMap[record.source]?.color || 'default'"
                >
                  {{ sourceMap[record.source]?.label || record.source }}
                </a-tag>
              </template>

              <template v-if="column.dataIndex === 'need_login'">
                <a-tag
                  v-if="!isCategoryRow(record)"
                  :color="record.need_login ? 'orange' : 'green'"
                >
                  {{ record.need_login ? '需要' : '不需要' }}
                </a-tag>
              </template>

              <template v-if="column.dataIndex === 'sort'">
                <span v-if="!isCategoryRow(record)">{{ record.sort }}</span>
              </template>

              <template v-if="column.dataIndex === 'status'">
                <a-tag
                  v-if="!isCategoryRow(record)"
                  :color="record.status === 1 ? 'green' : 'default'"
                >
                  {{ record.status === 1 ? '启用' : '禁用' }}
                </a-tag>
              </template>

              <template v-if="column.dataIndex === 'update_time'">
                <span v-if="!isCategoryRow(record)">
                  {{ record.update_time }}
                </span>
              </template>

              <template v-if="column.key === 'action'">
                <span
                  v-if="isCategoryRow(record) || isSystemPage(record)"
                ></span>
                <a-space v-else>
                  <a-button
                    type="link"
                    size="small"
                    @click="handleEdit(record)"
                  >
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
        </div>
      </a-tab-pane>

      <a-tab-pane key="categories" tab="页面分类">
        <div class="client-page__filter">
          <a-form class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3">
            <a-form-item class="mb-0" label="关键词">
              <a-input
                v-model:value="categorySearchParams.keyword"
                allow-clear
                class="w-full"
                placeholder="名称/编码/描述"
                @press-enter="submitCategorySearch"
              />
            </a-form-item>
            <a-form-item class="mb-0" label="状态">
              <a-select
                v-model:value="categorySearchParams.status"
                allow-clear
                class="w-full"
                placeholder="请选择"
              >
                <a-select-option :value="1">启用</a-select-option>
                <a-select-option :value="0">禁用</a-select-option>
              </a-select>
            </a-form-item>
            <a-form-item class="mb-0">
              <div class="flex justify-end gap-2">
                <a-button type="primary" @click="submitCategorySearch">
                  搜索
                </a-button>
                <a-button @click="resetCategorySearch">重置</a-button>
              </div>
            </a-form-item>
          </a-form>
        </div>

        <div class="client-page__table">
          <div class="client-page__table-header">
            <div>
              <h3 class="client-page__table-title">页面分类</h3>
            </div>
            <div class="client-page__actions">
              <a-button type="primary" @click="handleCreateCategory">
                新增分类
              </a-button>
              <a-button @click="refreshCategories">刷新</a-button>
            </div>
          </div>

          <a-table
            :columns="categoryColumns"
            :data-source="categoryTableData"
            :loading="categoryLoading"
            :pagination="categoryPagination"
            :scroll="{ x: 1050 }"
            row-key="id"
            @change="handleCategoryTableChange"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.dataIndex === 'description'">
                {{ record.description || '-' }}
              </template>

              <template v-if="column.dataIndex === 'is_system'">
                <a-tag :color="record.is_system === 1 ? 'gold' : 'default'">
                  {{ record.is_system === 1 ? '系统' : '自定义' }}
                </a-tag>
              </template>

              <template v-if="column.dataIndex === 'status'">
                <Switch
                  :checked="record.status === 1"
                  checked-children="启用"
                  un-checked-children="禁用"
                  @change="
                    (checked) => handleCategoryStatusChange(record, checked)
                  "
                />
              </template>

              <template v-if="column.key === 'action'">
                <a-space>
                  <a-button
                    type="link"
                    size="small"
                    @click="handleEditCategory(record)"
                  >
                    编辑
                  </a-button>
                  <a-button
                    type="link"
                    danger
                    size="small"
                    :disabled="record.is_system === 1"
                    @click="handleDeleteCategory(record)"
                  >
                    删除
                  </a-button>
                </a-space>
              </template>
            </template>
          </a-table>
        </div>
      </a-tab-pane>
    </a-tabs>

    <a-modal
      v-model:open="modalVisible"
      :confirm-loading="modalLoading"
      :title="modalTitle"
      width="640px"
      @ok="handleSubmit"
    >
      <a-form
        ref="formRef"
        :label-col="{ style: { width: '100px' } }"
        :model="formData"
        :rules="formRules"
        class="pt-4"
      >
        <a-form-item label="页面名称" name="name">
          <a-input
            v-model:value="formData.name"
            allow-clear
            placeholder="请输入页面名称"
          />
        </a-form-item>
        <a-form-item label="页面路径" name="path">
          <a-input
            v-model:value="formData.path"
            allow-clear
            placeholder="/pages/index/index"
          />
        </a-form-item>
        <a-form-item label="页面类型" name="page_type">
          <a-select
            v-model:value="formData.page_type"
            :options="PAGE_TYPE_OPTIONS"
            placeholder="请选择页面类型"
          />
        </a-form-item>
        <a-form-item label="页面分类" name="category">
          <a-select
            v-model:value="formData.category"
            :options="enabledPageCategoryOptions"
            placeholder="请选择页面分类"
          />
        </a-form-item>
        <a-form-item label="分包 root" name="package_root">
          <a-input
            v-model:value="formData.package_root"
            allow-clear
            placeholder="如 pages-sub/goods"
          />
        </a-form-item>
        <a-form-item label="登录要求" name="need_login">
          <Switch
            v-model:checked="formData.need_login"
            :checked-value="1"
            :un-checked-value="0"
            checked-children="需要"
            un-checked-children="不需要"
          />
        </a-form-item>
        <a-form-item label="来源" name="source">
          <a-select
            v-model:value="formData.source"
            :options="FORM_SOURCE_OPTIONS"
            placeholder="请选择来源"
          />
        </a-form-item>
        <a-form-item label="排序" name="sort">
          <a-input-number
            v-model:value="formData.sort"
            :min="0"
            :max="9999"
            class="w-full"
            placeholder="数字越小越靠前"
          />
        </a-form-item>
        <a-form-item label="状态" name="status">
          <a-radio-group v-model:value="formData.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="备注" name="remark">
          <a-textarea
            v-model:value="formData.remark"
            :rows="3"
            allow-clear
            placeholder="可选"
          />
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal
      v-model:open="categoryModalVisible"
      :confirm-loading="categoryModalLoading"
      :title="categoryModalTitle"
      width="620px"
      @ok="handleSubmitCategory"
    >
      <a-form
        ref="categoryFormRef"
        :label-col="{ style: { width: '100px' } }"
        :model="categoryFormData"
        :rules="categoryFormRules"
        class="pt-4"
      >
        <a-form-item label="分类名称" name="name">
          <a-input
            v-model:value="categoryFormData.name"
            allow-clear
            :maxlength="80"
            placeholder="请输入分类名称"
            show-count
          />
        </a-form-item>
        <a-form-item label="分类编码" name="code">
          <a-input
            v-model:value="categoryFormData.code"
            allow-clear
            :disabled="!!editingCategoryId"
            :maxlength="30"
            placeholder="如 marketing"
            show-count
          />
        </a-form-item>
        <a-form-item label="描述" name="description">
          <a-textarea
            v-model:value="categoryFormData.description"
            :maxlength="255"
            :rows="3"
            allow-clear
            placeholder="可选"
            show-count
          />
        </a-form-item>
        <a-form-item label="排序" name="sort">
          <a-input-number
            v-model:value="categoryFormData.sort"
            :min="0"
            :max="9999"
            class="w-full"
            placeholder="数字越小越靠前"
          />
        </a-form-item>
        <a-form-item label="状态" name="status">
          <a-radio-group v-model:value="categoryFormData.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal
      v-model:open="importVisible"
      :confirm-loading="importLoading"
      title="导入 pages.json"
      width="620px"
      @cancel="resetImportForm"
      @ok="handleImport"
    >
      <a-alert
        class="mb-4"
        message="页面库以数据库为准，pages.json 导入后按系统内置页面维护。"
        show-icon
        type="info"
      />
      <a-form
        :label-col="{ style: { width: '100px' } }"
        :model="importForm"
        class="pt-4"
      >
        <a-tabs v-model:active-key="importMode">
          <a-tab-pane key="file" tab="上传文件">
            <a-form-item label="JSON 文件">
              <a-upload
                :before-upload="handleImportBeforeUpload"
                :file-list="importFileList"
                :max-count="1"
                accept=".json,application/json"
                @remove="handleImportRemove"
              >
                <a-button>选择 pages.json</a-button>
              </a-upload>
            </a-form-item>
          </a-tab-pane>

          <a-tab-pane key="json" tab="粘贴 JSON">
            <a-form-item label="JSON 内容" name="pages_json">
              <a-textarea
                v-model:value="importForm.pages_json"
                :rows="10"
                allow-clear
                :placeholder="pagesJsonPlaceholder"
              />
            </a-form-item>
          </a-tab-pane>
        </a-tabs>
      </a-form>
    </a-modal>
  </div>
</template>

<style scoped>
.client-page {
  min-height: 100%;
}

.client-page__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 12px;
}

.client-page__title {
  margin: 0;
  color: hsl(var(--foreground));
  font-size: 18px;
  font-weight: 600;
  line-height: 32px;
}

.client-page__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}

.client-page__filter,
.client-page__table {
  background: hsl(var(--card));
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.client-page__filter {
  padding: 16px;
  margin-bottom: 12px;
}

.client-page__table {
  overflow: hidden;
}

.client-page__table-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid hsl(var(--border));
}

.client-page__table-title {
  margin: 0;
  color: hsl(var(--foreground));
  font-size: 15px;
  font-weight: 600;
  line-height: 24px;
}

.client-page__table-meta {
  color: hsl(var(--muted-foreground));
  font-size: 13px;
  white-space: nowrap;
}

.client-page__table :deep(.ant-table) {
  background: hsl(var(--card));
}

.client-page__table :deep(.client-page-category-row > td) {
  background: hsl(var(--background));
  border-top: 8px solid hsl(var(--card));
  border-bottom-color: hsl(var(--border));
  font-weight: 600;
}

.client-page__table :deep(.client-page-category-row:first-child > td) {
  border-top-width: 0;
}

.client-page__table :deep(.ant-table-row-level-1 > td:first-child) {
  padding-left: 40px;
}

@media (max-width: 768px) {
  .client-page__header,
  .client-page__table-header {
    align-items: stretch;
    flex-direction: column;
  }

  .client-page__actions {
    justify-content: flex-start;
  }

  .client-page__table-meta {
    white-space: normal;
  }
}
</style>
