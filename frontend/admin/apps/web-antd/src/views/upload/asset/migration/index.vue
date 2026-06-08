<script lang="ts" setup>
import type { UploadAssetApi } from '#/api/upload/asset';

import { h, onMounted, reactive, ref } from 'vue';

import { message, Modal, Tag } from 'ant-design-vue';

import {
  cleanupUploadAssetMigrationApi,
  createUploadAssetMigrationApi,
  getUploadAssetMigrationListApi,
  retryUploadAssetMigrationApi,
} from '#/api/upload/asset';

defineOptions({ name: 'UploadAssetMigrationManagement' });

const loading = ref(false);
const modalOpen = ref(false);
const tableData = ref<UploadAssetApi.MigrationItem[]>([]);
const pagination = reactive({ current: 1, pageSize: 20, total: 0 });
const formRef = ref();
const formData = reactive({
  name: '',
  source_driver: 'legacy_local',
  target_driver: 'oss',
});

const driverOptions = [
  { label: '旧本地路径', value: 'legacy_local' },
  { label: '本地', value: 'local' },
  { label: '阿里云 OSS', value: 'oss' },
  { label: '腾讯云 COS', value: 'cos' },
];

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
  { title: '来源', dataIndex: 'source_driver', width: 110 },
  { title: '目标', dataIndex: 'target_driver', width: 110 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 100,
    customRender: ({ record }: any) =>
      h(Tag, { color: statusColor[record.status] || 'default' }, () => statusText[record.status] || record.status),
  },
  { title: '成功/失败', key: 'count', width: 120, customRender: ({ record }: any) => `${record.success_count}/${record.fail_count}` },
  { title: '错误', dataIndex: 'last_error', width: 320, ellipsis: true },
  { title: '更新时间', dataIndex: 'update_time', width: 170 },
  { title: '操作', key: 'action', width: 100 },
];
const tableScroll = { x: 1220 };

onMounted(loadData);
</script>

<template>
  <div class="migration-page">
    <div class="migration-toolbar">
      <a-button type="primary" @click="modalOpen = true" v-access:code="'SystemUploadAssetMigrationCreate'">
        新建迁移
      </a-button>
      <a-button @click="cleanup" v-access:code="'SystemUploadAssetMigrationCleanup'">
        清理任务
      </a-button>
      <a-button @click="loadData" v-access:code="'SystemUploadAssetMigrationList'">刷新</a-button>
    </div>

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
          <a-button
            v-if="record.status === 0 || record.status === 3"
            type="link"
            size="small"
            @click="retryTask(record)"
            v-access:code="'SystemUploadAssetMigrationRetry'"
          >
            重试
          </a-button>
        </template>
      </template>
    </a-table>

    <a-modal v-model:open="modalOpen" title="新建迁移任务" @ok="createTask">
      <a-form ref="formRef" :model="formData" :label-col="{ style: { width: '100px' } }" class="pt-4">
        <a-form-item label="名称" name="name">
          <a-input v-model:value="formData.name" placeholder="留空自动生成" />
        </a-form-item>
        <a-form-item label="来源" name="source_driver" :rules="[{ required: true, message: '请选择来源' }]">
          <a-select v-model:value="formData.source_driver" :options="driverOptions" />
        </a-form-item>
        <a-form-item label="目标" name="target_driver" :rules="[{ required: true, message: '请选择目标' }]">
          <a-select
            v-model:value="formData.target_driver"
            :options="driverOptions.filter((item) => item.value !== 'legacy_local')"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<style scoped>
.migration-page {
  padding: 16px;
}

.migration-toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
}
</style>
