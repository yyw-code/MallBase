<script lang="ts" setup>
import type { TablePaginationConfig } from 'ant-design-vue';

import type { LogisticsApi } from '#/api/logistics';

import { computed, onMounted, reactive, ref } from 'vue';

import { message, Modal } from 'ant-design-vue';

import {
  clearLogisticsPlatformCacheApi,
  getLogisticsPlatformListApi,
  saveLogisticsPlatformApi,
} from '#/api/logistics';

defineOptions({ name: 'SystemLogisticsPlatform' });

const driverOptions = [{ label: '快递鸟', value: 'kdniao' }];
const statusOptions = [
  { label: '启用', value: 1 },
  { label: '禁用', value: 0 },
];
const searchParams = reactive<LogisticsApi.PlatformListParams>({
  driver: undefined,
  keyword: '',
  status: undefined,
});
const loading = ref(false);
const clearing = ref(false);
const tableData = ref<LogisticsApi.PlatformItem[]>([]);
const selectedRowKeys = ref<number[]>([]);
const pagination = reactive({
  current: 1,
  pageSize: 15,
  showSizeChanger: true,
  total: 0,
});
const modalVisible = ref(false);
const saving = ref(false);
const formRef = ref();
const form = reactive<LogisticsApi.PlatformSaveParams>({
  cache_minutes: 30,
  code: '',
  config: {},
  driver: 'kdniao',
  is_default: 0,
  name: '',
  sort: 0,
  status: 1,
});

const loadData = async () => {
  loading.value = true;
  try {
    const res = await getLogisticsPlatformListApi({
      ...searchParams,
      limit: pagination.pageSize,
      page: pagination.current,
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
  searchParams.driver = undefined;
  searchParams.keyword = '';
  searchParams.status = undefined;
  pagination.current = 1;
  void loadData();
};

const rowSelection = computed(() => ({
  selectedRowKeys: selectedRowKeys.value,
  onChange: (keys: (number | string)[]) => {
    selectedRowKeys.value = keys.map(Number).filter((key) => key > 0);
  },
}));

const openModal = (row?: LogisticsApi.PlatformItem) => {
  const config = row?.config ? { ...row.config, key: '' } : { key: '' };
  config.request_type = config.request_type || '8002';

  Object.assign(form, {
    cache_minutes: row?.cache_minutes ?? 30,
    code: row?.code ?? '',
    config,
    driver: row?.driver ?? 'kdniao',
    id: row?.id,
    is_default: row?.is_default ?? 0,
    name: row?.name ?? '',
    sort: row?.sort ?? 0,
    status: row?.status ?? 1,
  });
  modalVisible.value = true;
};

const accountFieldExtra = '快递鸟分配的用户 ID，用于接口身份识别。';
const keyFieldExtra =
  '快递鸟分配的 AppKey，用于接口签名；编辑时留空则保留原密钥。';
const queryTypeLabel = (row: LogisticsApi.PlatformItem) => {
  if (row.driver !== 'kdniao') return '-';
  return '普通轨迹查询（8002）';
};

const handleSave = async () => {
  try {
    await formRef.value?.validate();
  } catch {
    return;
  }

  saving.value = true;
  try {
    form.driver = 'kdniao';
    form.config.request_type = '8002';
    await saveLogisticsPlatformApi({ ...form });
    message.success('保存成功');
    modalVisible.value = false;
    await loadData();
  } finally {
    saving.value = false;
  }
};

const handleClearCache = () => {
  if (selectedRowKeys.value.length === 0) {
    message.warning('请选择物流平台');
    return;
  }

  Modal.confirm({
    title: '清理物流缓存',
    content: `确认清理已选 ${selectedRowKeys.value.length} 个平台的物流查询缓存？清理后，买家下次查看物流会重新请求平台接口。`,
    okText: '清理',
    okType: 'danger',
    cancelText: '取消',
    async onOk() {
      clearing.value = true;
      try {
        const res = await clearLogisticsPlatformCacheApi(selectedRowKeys.value);
        selectedRowKeys.value = [];
        message.success(`已清理 ${res.count || 0} 条轨迹缓存`);
        await loadData();
      } finally {
        clearing.value = false;
      }
    },
  });
};

const driverLabel = (driver: string) =>
  driverOptions.find((item) => item.value === driver)?.label || driver;

const columns = [
  { dataIndex: 'name', title: '平台名称', width: 150 },
  { dataIndex: 'code', title: '平台编码', width: 140 },
  { dataIndex: 'driver', title: '驱动', width: 120 },
  { dataIndex: 'query_type', title: '查询模式', width: 180 },
  { dataIndex: 'is_default', title: '默认', width: 80 },
  { dataIndex: 'status', title: '状态', width: 80 },
  { dataIndex: 'cache_minutes', title: '缓存分钟', width: 100 },
  { dataIndex: 'sort', title: '排序', width: 80 },
  { dataIndex: 'update_time', title: '更新时间', width: 180 },
  { key: 'action', title: '操作', width: 120 },
];

onMounted(() => {
  void loadData();
});
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button
        type="primary"
        @click="openModal()"
        v-access:code="'SystemLogisticsPlatformSave'"
      >
        新增平台
      </a-button>
      <a-button
        class="ml-2"
        @click="loadData"
        v-access:code="'SystemLogisticsPlatformList'"
      >
        刷新
      </a-button>
      <a-button
        class="ml-2"
        danger
        :disabled="selectedRowKeys.length === 0"
        :loading="clearing"
        @click="handleClearCache"
        v-access:code="'SystemLogisticsPlatformClearCache'"
      >
        清缓存{{
          selectedRowKeys.length > 0 ? `（${selectedRowKeys.length}）` : ''
        }}
      </a-button>
    </div>

    <a-form
      layout="inline"
      class="mb-4"
      v-access:code="'SystemLogisticsPlatformList'"
    >
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="平台名称/编码"
          allow-clear
          style="width: 200px"
        />
      </a-form-item>
      <a-form-item label="接口驱动">
        <a-select
          v-model:value="searchParams.driver"
          :options="driverOptions"
          allow-clear
          placeholder="全部"
          style="width: 140px"
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
      :row-selection="rowSelection"
      :scroll="{ x: 1050 }"
      row-key="id"
      @change="handleTableChange"
      v-access:code="'SystemLogisticsPlatformList'"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.dataIndex === 'driver'">
          <a-tag color="blue">{{ driverLabel(record.driver) }}</a-tag>
        </template>
        <template v-if="column.dataIndex === 'query_type'">
          {{ queryTypeLabel(record) }}
        </template>
        <template v-if="column.dataIndex === 'is_default'">
          <a-tag v-if="record.is_default === 1" color="green">默认</a-tag>
          <span v-else>-</span>
        </template>
        <template v-if="column.dataIndex === 'status'">
          <a-tag :color="record.status === 1 ? 'green' : 'default'">
            {{ record.status === 1 ? '启用' : '禁用' }}
          </a-tag>
        </template>
        <template v-if="column.key === 'action'">
          <a-button
            type="link"
            size="small"
            @click="openModal(record)"
            v-access:code="'SystemLogisticsPlatformSave'"
          >
            编辑
          </a-button>
        </template>
      </template>
    </a-table>

    <a-modal
      v-model:open="modalVisible"
      title="物流平台"
      width="640px"
      :confirm-loading="saving"
      @ok="handleSave"
    >
      <a-form
        ref="formRef"
        :model="form"
        :label-col="{ style: { width: '100px' } }"
        class="pt-4"
      >
        <a-form-item
          label="平台名称"
          name="name"
          :rules="[{ required: true, message: '请输入平台名称' }]"
        >
          <a-input v-model:value="form.name" placeholder="如：快递鸟" />
        </a-form-item>
        <a-form-item
          label="平台编码"
          name="code"
          :rules="[{ required: true, message: '请输入平台编码' }]"
        >
          <a-input
            v-model:value="form.code"
            placeholder="如：kdniao"
            :disabled="!!form.id"
          />
        </a-form-item>
        <a-form-item
          label="接口驱动"
          name="driver"
          :rules="[{ required: true, message: '请选择驱动' }]"
          extra="决定系统使用哪套物流平台接口、签名规则和请求参数。"
        >
          <a-select v-model:value="form.driver" :options="driverOptions" />
        </a-form-item>
        <a-form-item label="商户ID" :extra="accountFieldExtra">
          <a-input
            v-model:value="form.config.business_id"
            placeholder="请输入快递鸟 EBusinessID"
          />
        </a-form-item>
        <a-form-item label="接口密钥" :extra="keyFieldExtra">
          <a-input-password
            v-model:value="form.config.key"
            :placeholder="form.id ? '留空则保留原值' : '请输入快递鸟 AppKey'"
            autocomplete="new-password"
          />
        </a-form-item>
        <a-form-item label="设为默认">
          <a-radio-group v-model:value="form.is_default">
            <a-radio :value="1">是</a-radio>
            <a-radio :value="0">否</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="状态">
          <a-radio-group v-model:value="form.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item
          label="缓存分钟"
          name="cache_minutes"
          :rules="[{ required: true, message: '请输入缓存分钟' }]"
        >
          <a-input-number
            v-model:value="form.cache_minutes"
            :min="1"
            :precision="0"
          />
        </a-form-item>
        <a-form-item label="排序">
          <a-input-number v-model:value="form.sort" :min="0" :precision="0" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
