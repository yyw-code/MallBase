<script lang="ts" setup>
import type { TablePaginationConfig } from 'ant-design-vue';

import type { LogisticsApi } from '#/api/logistics';

import { computed, onMounted, reactive, ref } from 'vue';

import { message, Modal } from 'ant-design-vue';

import {
  deleteLogisticsCompanyApi,
  getLogisticsCompanyListApi,
  getLogisticsPlatformListApi,
  saveLogisticsCompanyApi,
  updateLogisticsCompanyStatusApi,
} from '#/api/logistics';

defineOptions({ name: 'SystemLogisticsCompany' });

const statusOptions = [
  { label: '启用', value: 1 },
  { label: '禁用', value: 0 },
];

const searchParams = reactive<LogisticsApi.CompanyListParams>({
  keyword: '',
  platform: '',
  status: undefined,
});
const loading = ref(false);
const saving = ref(false);
const modalVisible = ref(false);
const formRef = ref();
const tableData = ref<LogisticsApi.CompanyItem[]>([]);
const platformData = ref<LogisticsApi.PlatformItem[]>([]);
const pagination = reactive({
  current: 1,
  pageSize: 15,
  showSizeChanger: true,
  total: 0,
});
const form = reactive<LogisticsApi.CompanySaveParams>({
  code: '',
  name: '',
  platform: '',
  remark: '',
  sort: 0,
  status: 1,
});

const platformOptions = computed(() =>
  platformData.value.map((item) => ({ label: item.name, value: item.code })),
);

const currentPlatform = computed(
  () =>
    searchParams.platform ||
    platformData.value.find((item) => item.is_default === 1)?.code ||
    platformData.value[0]?.code ||
    '',
);

const platformName = (platform: string) =>
  platformData.value.find((item) => item.code === platform)?.name || platform;

const loadPlatforms = async () => {
  const res = await getLogisticsPlatformListApi({ limit: 100, page: 1 });
  platformData.value = res.list || [];
  if (!searchParams.platform && platformData.value.length > 0) {
    searchParams.platform = currentPlatform.value;
  }
};

const loadData = async () => {
  loading.value = true;
  try {
    const res = await getLogisticsCompanyListApi({
      ...searchParams,
      limit: pagination.pageSize,
      page: pagination.current,
      platform: currentPlatform.value,
    });
    tableData.value = res.list || [];
    pagination.total = res.total || 0;
  } finally {
    loading.value = false;
  }
};

const handleTableChange = (pager: TablePaginationConfig) => {
  pagination.current = pager.current || 1;
  pagination.pageSize = pager.pageSize || pagination.pageSize;
  void loadData();
};

const handleSearch = () => {
  pagination.current = 1;
  void loadData();
};

const handleReset = () => {
  searchParams.keyword = '';
  searchParams.platform = currentPlatform.value;
  searchParams.status = undefined;
  pagination.current = 1;
  void loadData();
};

const openModal = (row?: LogisticsApi.CompanyItem) => {
  Object.assign(form, {
    code: row?.code ?? '',
    id: row?.id,
    name: row?.name ?? '',
    platform: row?.platform ?? currentPlatform.value,
    remark: row?.remark ?? '',
    sort: row?.sort ?? 0,
    status: row?.status ?? 1,
  });
  modalVisible.value = true;
};

const handleSave = async () => {
  try {
    await formRef.value?.validate();
  } catch {
    return;
  }

  saving.value = true;
  try {
    await saveLogisticsCompanyApi({ ...form });
    message.success('保存成功');
    modalVisible.value = false;
    await loadData();
  } finally {
    saving.value = false;
  }
};

const handleStatus = async (
  checked: boolean,
  row: LogisticsApi.CompanyItem,
) => {
  const status = checked ? 1 : 0;
  await updateLogisticsCompanyStatusApi(row.id, status);
  message.success(status === 1 ? '已启用' : '已停用');
  await loadData();
};

const handleDelete = (row: LogisticsApi.CompanyItem) => {
  Modal.confirm({
    title: '删除物流公司',
    content: `确认删除 ${row.name}？`,
    okText: '删除',
    okType: 'danger',
    cancelText: '取消',
    async onOk() {
      await deleteLogisticsCompanyApi(row.id);
      message.success('删除成功');
      await loadData();
    },
  });
};

const columns = [
  { dataIndex: 'platform', title: '平台', width: 120 },
  { dataIndex: 'name', title: '物流公司', width: 160 },
  { dataIndex: 'code', title: '平台编码', width: 150 },
  { dataIndex: 'remark', title: '备注', width: 220 },
  { dataIndex: 'status', title: '状态', width: 100 },
  { dataIndex: 'sort', title: '排序', width: 80 },
  { key: 'action', title: '操作', width: 150 },
];

onMounted(async () => {
  await loadPlatforms();
  await loadData();
});
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button
        type="primary"
        @click="openModal()"
        v-access:code="'SystemLogisticsCompanySave'"
      >
        新增
      </a-button>
      <a-button
        class="ml-2"
        @click="loadData"
        v-access:code="'SystemLogisticsCompanyList'"
      >
        刷新
      </a-button>
    </div>

    <a-form
      layout="inline"
      class="mb-4"
      v-access:code="'SystemLogisticsCompanyList'"
    >
      <a-form-item label="平台">
        <a-select
          v-model:value="searchParams.platform"
          :options="platformOptions"
          placeholder="请选择"
          style="width: 160px"
        />
      </a-form-item>
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="公司名称/编码"
          allow-clear
          style="width: 200px"
        />
      </a-form-item>
      <a-form-item label="状态">
        <a-select
          v-model:value="searchParams.status"
          :options="statusOptions"
          allow-clear
          placeholder="全部"
          style="width: 120px"
        />
      </a-form-item>
      <a-form-item>
        <a-button type="primary" @click="handleSearch">搜索</a-button>
        <a-button class="ml-2" @click="handleReset">重置</a-button>
      </a-form-item>
    </a-form>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1100 }"
      row-key="id"
      @change="handleTableChange"
      v-access:code="'SystemLogisticsCompanyList'"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.dataIndex === 'platform'">
          {{ platformName(record.platform) }}
        </template>
        <template v-if="column.dataIndex === 'status'">
          <a-switch
            :checked="record.status === 1"
            checked-children="启"
            un-checked-children="停"
            @change="(checked) => handleStatus(Boolean(checked), record)"
            v-access:code="'SystemLogisticsCompanyStatus'"
          />
        </template>
        <template v-if="column.key === 'action'">
          <a-button
            type="link"
            size="small"
            @click="openModal(record)"
            v-access:code="'SystemLogisticsCompanySave'"
          >
            编辑
          </a-button>
          <a-button
            type="link"
            size="small"
            danger
            @click="handleDelete(record)"
            v-access:code="'SystemLogisticsCompanyDelete'"
          >
            删除
          </a-button>
        </template>
      </template>
    </a-table>

    <a-modal
      v-model:open="modalVisible"
      title="物流公司"
      width="640px"
      :confirm-loading="saving"
      @ok="handleSave"
    >
      <a-form
        ref="formRef"
        :model="form"
        :label-col="{ span: 6 }"
        :wrapper-col="{ span: 16 }"
      >
        <a-form-item
          label="平台"
          name="platform"
          :rules="[{ required: true, message: '请选择平台' }]"
        >
          <a-select
            v-model:value="form.platform"
            :options="platformOptions"
            placeholder="请选择平台"
          />
        </a-form-item>
        <a-form-item
          label="公司编码"
          name="code"
          :rules="[{ required: true, message: '请输入公司编码' }]"
        >
          <a-input v-model:value="form.code" placeholder="如：shunfeng" />
        </a-form-item>
        <a-form-item
          label="公司名称"
          name="name"
          :rules="[{ required: true, message: '请输入公司名称' }]"
        >
          <a-input v-model:value="form.name" placeholder="如：顺丰速运" />
        </a-form-item>
        <a-form-item label="备注">
          <a-input
            v-model:value="form.remark"
            placeholder="用于备注和搜索"
            :max-length="255"
            show-count
          />
        </a-form-item>
        <a-form-item label="状态">
          <a-radio-group v-model:value="form.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="排序">
          <a-input-number v-model:value="form.sort" :min="0" :precision="0" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
