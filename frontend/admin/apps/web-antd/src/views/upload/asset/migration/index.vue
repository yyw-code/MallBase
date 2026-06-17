<script lang="ts" setup>
import type { UploadApi } from '#/api/core/upload';
import type { UploadAssetApi } from '#/api/upload/asset';

import { computed, h, onMounted, reactive, ref } from 'vue';

import { message, Modal, Tag } from 'ant-design-vue';

import { getUploadOptionsCached } from '#/api/core/upload-config-cache';
import {
  cleanupUploadAssetMigrationApi,
  createUploadAssetMigrationApi,
  getUploadAssetMigrationListApi,
  getUploadAssetMigrationLogsApi,
  retryUploadAssetMigrationApi,
} from '#/api/upload/asset';

defineOptions({ name: 'UploadAssetMigrationManagement' });

const loading = ref(false);
const modalOpen = ref(false);
const logOpen = ref(false);
const logLoading = ref(false);
const tableData = ref<UploadAssetApi.MigrationItem[]>([]);
const logData = ref<UploadAssetApi.MigrationLogItem[]>([]);
const currentMigration = ref<UploadAssetApi.MigrationItem>();
const uploadOptions = ref<null | UploadApi.UploadOptions>(null);
const uploadOptionsLoading = ref(false);
const pagination = reactive({ current: 1, pageSize: 20, total: 0 });
const logPagination = reactive({ current: 1, pageSize: 20, total: 0 });
const logFilters = reactive<{ keyword: string; status?: number }>({
  keyword: '',
  status: undefined,
});
const formRef = ref();
const formData = reactive({
  name: '',
  source_driver: 'legacy_local',
  target_driver: '',
  options: {
    delete_source_after_success: false,
  },
});

const legacyDriverOption = { label: '历史图片路径', value: 'legacy_local' };

const storageDriverOptions = computed(() =>
  (uploadOptions.value?.upload_drivers || []).map((item) => ({
    label: item.label,
    value: item.value,
  })),
);

const driverOptions = computed(() => [
  legacyDriverOption,
  ...storageDriverOptions.value,
]);

const driverLabelMap = computed(() =>
  Object.fromEntries(
    driverOptions.value.map((item) => [item.value, item.label]),
  ),
);

const statusText: Record<number, string> = {
  0: '待处理',
  1: '处理中',
  2: '完成',
  3: '失败',
  4: '取消',
};

const statusColor: Record<number, string> = {
  0: 'default',
  1: 'blue',
  2: 'green',
  3: 'red',
  4: 'orange',
};

const logStatusText: Record<number, string> = {
  0: '处理中',
  1: '成功',
  2: '失败',
};

const logStatusColor: Record<number, string> = {
  0: 'blue',
  1: 'green',
  2: 'red',
};

const canDeleteSource = computed(
  () => formData.source_driver !== 'legacy_local',
);

const isEmptyDone = (record: UploadAssetApi.MigrationItem) =>
  record.status === 2 &&
  Number(record.total || 0) === 0 &&
  Number(record.success_count || 0) === 0 &&
  Number(record.fail_count || 0) === 0;

const getStatusText = (record: UploadAssetApi.MigrationItem) =>
  isEmptyDone(record) ? '无数据' : statusText[record.status] || record.status;

const getStatusColor = (record: UploadAssetApi.MigrationItem) =>
  isEmptyDone(record) ? 'default' : statusColor[record.status] || 'default';

const formatDriver = (driver: string) => driverLabelMap.value[driver] || driver;

const formatYesNo = (value: number) => (value === 1 ? '是' : '否');

const loadUploadOptions = async () => {
  uploadOptionsLoading.value = true;
  try {
    uploadOptions.value = await getUploadOptionsCached();
    const targetOptions = storageDriverOptions.value;
    const enabledDriver =
      uploadOptions.value.upload_drivers?.find((item) => item.enabled)?.value ||
      '';
    if (
      targetOptions.length > 0 &&
      (!formData.target_driver ||
        !targetOptions.some((item) => item.value === formData.target_driver))
    ) {
      formData.target_driver = enabledDriver || targetOptions[0]?.value || '';
    }
  } catch (error) {
    console.warn('加载上传选项失败:', error);
    uploadOptions.value = null;
    message.error('上传配置加载失败');
  } finally {
    uploadOptionsLoading.value = false;
  }
};

const loadData = async () => {
  loading.value = true;
  try {
    const res = await getUploadAssetMigrationListApi({
      page: pagination.current,
      limit: pagination.pageSize,
    });
    tableData.value = res.list || [];
    pagination.total = res.total || 0;
  } finally {
    loading.value = false;
  }
};

const handleTableChange = (pager: { current?: number; pageSize?: number }) => {
  pagination.current = pager.current || pagination.current;
  pagination.pageSize = pager.pageSize || pagination.pageSize;
  loadData();
};

const handleSourceChange = () => {
  if (!canDeleteSource.value) {
    formData.options.delete_source_after_success = false;
  }
};

const createTask = async () => {
  await formRef.value?.validate();
  await createUploadAssetMigrationApi(formData);
  message.success('任务已创建');
  modalOpen.value = false;
  await loadData();
};

const retryTask = async (record: UploadAssetApi.MigrationItem) => {
  await retryUploadAssetMigrationApi(record.id);
  message.success('已重新入队');
  await loadData();
};

const loadLogs = async () => {
  if (!currentMigration.value) {
    return;
  }
  logLoading.value = true;
  try {
    const res = await getUploadAssetMigrationLogsApi(
      currentMigration.value.id,
      {
        page: logPagination.current,
        limit: logPagination.pageSize,
        keyword: logFilters.keyword || undefined,
        status: logFilters.status,
      },
    );
    logData.value = res.list || [];
    logPagination.total = res.total || 0;
  } finally {
    logLoading.value = false;
  }
};

const openLogs = async (record: UploadAssetApi.MigrationItem) => {
  currentMigration.value = record;
  logPagination.current = 1;
  logFilters.keyword = '';
  logFilters.status = undefined;
  logOpen.value = true;
  await loadLogs();
};

const handleLogTableChange = (pager: {
  current?: number;
  pageSize?: number;
}) => {
  logPagination.current = pager.current || logPagination.current;
  logPagination.pageSize = pager.pageSize || logPagination.pageSize;
  loadLogs();
};

const resetLogFilters = () => {
  logFilters.keyword = '';
  logFilters.status = undefined;
  logPagination.current = 1;
  loadLogs();
};

const cleanup = () => {
  Modal.confirm({
    title: '清理迁移任务',
    content: '确认清理 30 天前已完成的迁移任务吗？',
    async onOk() {
      const res: any = await cleanupUploadAssetMigrationApi(30);
      message.success(`已清理 ${res?.count || 0} 条`);
      await loadData();
    },
  });
};

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '名称', dataIndex: 'name', width: 180, ellipsis: true },
  {
    title: '来源',
    dataIndex: 'source_driver',
    width: 120,
    customRender: ({ text }: any) => formatDriver(text),
  },
  {
    title: '目标',
    dataIndex: 'target_driver',
    width: 120,
    customRender: ({ text }: any) => formatDriver(text),
  },
  {
    title: '状态',
    dataIndex: 'status',
    width: 100,
    customRender: ({ record }: any) =>
      h(Tag, { color: getStatusColor(record) }, () => getStatusText(record)),
  },
  { title: '总数', dataIndex: 'total', width: 90 },
  {
    title: '成功/失败/总数',
    key: 'count',
    width: 140,
    customRender: ({ record }: any) =>
      `${record.success_count}/${record.fail_count}/${record.total}`,
  },
  { title: '错误', dataIndex: 'last_error', width: 320, ellipsis: true },
  { title: '更新时间', dataIndex: 'update_time', width: 170 },
  { title: '操作', key: 'action', width: 150 },
];
const tableScroll = { x: 1390 };

const logColumns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '素材ID', dataIndex: 'asset_id', width: 90 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: any) =>
      h(
        Tag,
        { color: logStatusColor[record.status] || 'default' },
        () => logStatusText[record.status] || record.status,
      ),
  },
  { title: '阶段', dataIndex: 'stage', width: 110 },
  {
    title: '来源',
    dataIndex: 'source_driver',
    width: 110,
    customRender: ({ text }: any) => formatDriver(text),
  },
  {
    title: '目标',
    dataIndex: 'target_driver',
    width: 110,
    customRender: ({ text }: any) => formatDriver(text),
  },
  { title: '源路径', dataIndex: 'source_path', width: 220, ellipsis: true },
  { title: '目标路径', dataIndex: 'target_path', width: 220, ellipsis: true },
  {
    title: '删除源',
    dataIndex: 'delete_source',
    width: 90,
    customRender: ({ text }: any) => formatYesNo(Number(text)),
  },
  {
    title: '已删除',
    dataIndex: 'source_deleted',
    width: 90,
    customRender: ({ text }: any) => formatYesNo(Number(text)),
  },
  { title: '说明', dataIndex: 'message', width: 240, ellipsis: true },
  { title: '错误', dataIndex: 'error_message', width: 300, ellipsis: true },
  {
    title: '耗时',
    dataIndex: 'duration_ms',
    width: 90,
    customRender: ({ text }: any) => `${text || 0}ms`,
  },
  { title: '时间', dataIndex: 'update_time', width: 170 },
];
const logTableScroll = { x: 2010 };

onMounted(async () => {
  await loadUploadOptions();
  await loadData();
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">素材迁移</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          type="primary"
          @click="modalOpen = true"
          v-access:code="'SystemUploadAssetMigrationCreate'"
        >
          新建迁移
        </a-button>
        <a-button
          @click="cleanup"
          v-access:code="'SystemUploadAssetMigrationCleanup'"
        >
          清理任务
        </a-button>
        <a-button
          @click="loadData"
          v-access:code="'SystemUploadAssetMigrationList'"
        >
          刷新
        </a-button>
      </div>
    </div>

    <div class="overflow-hidden rounded-lg border bg-[hsl(var(--card))]">
      <a-table
        row-key="id"
        :columns="columns"
        :data-source="tableData"
        :loading="loading"
        :pagination="pagination"
        :scroll="tableScroll"
        @change="handleTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'action'">
            <a-space>
              <a-button
                type="link"
                size="small"
                @click="openLogs(record)"
                v-access:code="'SystemUploadAssetMigrationLogs'"
              >
                日志
              </a-button>
              <a-button
                v-if="
                  record.status === 0 ||
                  record.status === 1 ||
                  record.status === 3
                "
                type="link"
                size="small"
                @click="retryTask(record)"
                v-access:code="'SystemUploadAssetMigrationRetry'"
              >
                重试
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <a-modal v-model:open="modalOpen" title="新建迁移任务" @ok="createTask">
      <a-form
        ref="formRef"
        :model="formData"
        :label-col="{ style: { width: '100px' } }"
        class="pt-4"
      >
        <a-form-item label="名称" name="name">
          <a-input v-model:value="formData.name" placeholder="留空自动生成" />
        </a-form-item>
        <a-form-item
          label="来源"
          name="source_driver"
          extra="扫描商品图、SKU 图、分类/品牌图、用户头像、评价图里的 /static 或 /uploads 路径"
          :rules="[{ required: true, message: '请选择来源' }]"
        >
          <a-select
            v-model:value="formData.source_driver"
            :options="driverOptions"
            :loading="uploadOptionsLoading"
            show-search
            option-filter-prop="label"
            @change="handleSourceChange"
          />
        </a-form-item>
        <a-form-item
          label="目标"
          name="target_driver"
          :rules="[{ required: true, message: '请选择目标' }]"
        >
          <a-select
            v-model:value="formData.target_driver"
            :options="storageDriverOptions"
            :loading="uploadOptionsLoading"
            show-search
            option-filter-prop="label"
          />
        </a-form-item>
        <a-form-item
          label="删除源文件"
          name="delete_source_after_success"
          extra="迁移成功并切换主位置后，删除源存储对象并禁用源存储位置"
        >
          <a-switch
            v-model:checked="formData.options.delete_source_after_success"
            :disabled="!canDeleteSource"
            checked-children="是"
            un-checked-children="否"
          />
        </a-form-item>
      </a-form>
    </a-modal>

    <a-drawer
      v-model:open="logOpen"
      :width="980"
      :title="`迁移日志${currentMigration ? ` #${currentMigration.id}` : ''}`"
      destroy-on-close
    >
      <div class="log-toolbar">
        <a-input-search
          v-model:value="logFilters.keyword"
          allow-clear
          placeholder="搜索 asset_id / 路径 / 错误"
          class="log-toolbar__search"
          @search="loadLogs"
        />
        <a-select
          v-model:value="logFilters.status"
          allow-clear
          placeholder="状态"
          class="log-toolbar__status"
          :options="[
            { label: '处理中', value: 0 },
            { label: '成功', value: 1 },
            { label: '失败', value: 2 },
          ]"
          @change="loadLogs"
        />
        <a-button @click="resetLogFilters">重置</a-button>
        <a-button @click="loadLogs">刷新</a-button>
      </div>
      <a-table
        row-key="id"
        size="small"
        :columns="logColumns"
        :data-source="logData"
        :loading="logLoading"
        :pagination="logPagination"
        :scroll="logTableScroll"
        @change="handleLogTableChange"
      />
    </a-drawer>
  </div>
</template>

<style scoped>
.log-toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}

.log-toolbar__search {
  width: 280px;
}

.log-toolbar__status {
  width: 120px;
}
</style>
