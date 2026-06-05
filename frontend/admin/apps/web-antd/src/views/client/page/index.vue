<script lang="ts" setup>
import type { UploadFile, UploadProps } from 'ant-design-vue';
import type { FormInstance, Rule } from 'ant-design-vue/es/form';

import type { ClientPageApi } from '#/api/client';

import { computed, onMounted, reactive, ref } from 'vue';

import { message, Modal, Switch } from 'ant-design-vue';

import {
  createClientPageApi,
  deleteClientPageApi,
  getClientPageInfoApi,
  getClientPageListApi,
  importClientPageApi,
  updateClientPageApi,
} from '#/api/client';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'ClientPageManagement' });

type ImportMode = 'file' | 'json';
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

const CATEGORY_OPTIONS: Array<{
  color: string;
  label: string;
  value: ClientPageApi.PageCategory;
}> = [
  { label: '基础页面', value: 'basic', color: 'blue' },
  { label: '商品页面', value: 'goods', color: 'green' },
  { label: '订单页面', value: 'order', color: 'purple' },
  { label: '售后页面', value: 'aftersale', color: 'orange' },
  { label: '会员页面', value: 'user', color: 'cyan' },
  { label: '营销页面', value: 'marketing', color: 'magenta' },
  { label: '其他页面', value: 'other', color: 'default' },
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

const typeMap = computed(() =>
  Object.fromEntries(PAGE_TYPE_OPTIONS.map((item) => [item.value, item])),
);
const categoryMap = computed(() =>
  Object.fromEntries(CATEGORY_OPTIONS.map((item) => [item.value, item])),
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

  CATEGORY_OPTIONS.forEach((category) => {
    const children = tableData.value
      .filter((item) => (item.category || 'other') === category.value)
      .map((item) => ({
        ...item,
        tableKey: `page-${item.id}`,
      }));
    if (children.length === 0) return;

    rows.push({
      category: category.value,
      children,
      id: '',
      isCategory: true,
      name: `${category.label}（${children.length}）`,
      path: '',
      tableKey: `category-${category.value}`,
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

onMounted(() => {
  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-4 flex flex-wrap gap-2">
      <a-button type="primary" @click="handleCreate">新增页面</a-button>
      <a-button @click="openImportModal">导入 pages.json</a-button>
      <a-button @click="() => loadData(searchParams)">刷新</a-button>
    </div>

    <a-form layout="inline" class="mb-4">
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          allow-clear
          placeholder="名称/路径/备注"
          style="width: 220px"
        />
      </a-form-item>
      <a-form-item label="页面分类">
        <a-select
          v-model:value="searchParams.category"
          :options="CATEGORY_OPTIONS"
          allow-clear
          placeholder="请选择"
          style="width: 150px"
        />
      </a-form-item>
      <a-form-item label="页面类型">
        <a-select
          v-model:value="searchParams.page_type"
          :options="PAGE_TYPE_OPTIONS"
          allow-clear
          placeholder="请选择"
          style="width: 150px"
        />
      </a-form-item>
      <a-form-item label="来源">
        <a-select
          v-model:value="searchParams.source"
          :options="SOURCE_OPTIONS"
          allow-clear
          placeholder="请选择"
          style="width: 150px"
        />
      </a-form-item>
      <a-form-item label="状态">
        <a-select
          v-model:value="searchParams.status"
          allow-clear
          placeholder="请选择"
          style="width: 120px"
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
              loadData(searchParams);
            }
          "
        >
          搜索
        </a-button>
        <a-button class="ml-2" @click="resetSearch">重置</a-button>
      </a-form-item>
    </a-form>

    <a-table
      :columns="columns"
      :data-source="treeTableData"
      :loading="loading"
      :pagination="false"
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
          <a-tag :color="categoryMap[record.category]?.color || 'default'">
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
          <span v-if="!isCategoryRow(record)">{{ record.update_time }}</span>
        </template>

        <template v-if="column.key === 'action'">
          <span v-if="isCategoryRow(record) || isSystemPage(record)"></span>
          <a-space v-else>
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
            :options="CATEGORY_OPTIONS"
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
                placeholder='粘贴 pages.json 内容，例如：{"pages":[...],"subPackages":[...]}'
              />
            </a-form-item>
          </a-tab-pane>
        </a-tabs>
      </a-form>
    </a-modal>
  </div>
</template>
