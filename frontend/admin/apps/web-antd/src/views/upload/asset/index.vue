<script lang="ts" setup>
import type { UploadApi } from '#/api/core/upload';
import type { UploadAssetApi } from '#/api/upload/asset';

import { computed, h, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { Image, message, Modal, Tag } from 'ant-design-vue';

import { getUploadOptionsCached } from '#/api/core/upload-config-cache';
import {
  clearUploadAssetRecycleApi,
  deleteUploadAssetApi,
  getUploadAssetCategoryTreeApi,
  getUploadAssetInfoApi,
  getUploadAssetListApi,
  purgeUploadAssetApi,
  restoreUploadAssetApi,
} from '#/api/upload/asset';

defineOptions({ name: 'UploadAssetManagement' });

const loading = ref(false);
const route = useRoute();
const tableData = ref<UploadAssetApi.AssetItem[]>([]);
const categories = ref<UploadAssetApi.CategoryItem[]>([]);
const uploadOptions = ref<null | UploadApi.UploadOptions>(null);
const uploadOptionsLoading = ref(false);
const detailOpen = ref(false);
const detailLoading = ref(false);
const assetDetail = ref<null | UploadAssetApi.AssetDetail>(null);
const pagination = reactive({ current: 1, pageSize: 20, total: 0 });
const searchParams = reactive({
  keyword: '',
  category_id: undefined as number | undefined,
  driver: undefined as string | undefined,
  type: undefined as string | undefined,
  status: 1,
});

const isRecyclePage = computed(() =>
  route.path.includes('/upload/asset/recycle'),
);
const routeStatus = computed(() => (isRecyclePage.value ? 0 : 1));

const categoryOptions = () =>
  categories.value.map((item) => ({
    label: item.name,
    value: item.id,
    children: item.children?.map((child) => ({
      label: child.name,
      value: child.id,
    })),
  }));

const assetTypeOptions = computed(() =>
  (uploadOptions.value?.asset_types || []).map((item) => ({
    label: item.label,
    value: item.value,
  })),
);

const driverOptions = computed(() =>
  (uploadOptions.value?.upload_drivers || []).map((item) => ({
    label: item.label,
    value: item.value,
  })),
);

const currentDriverLabel = computed(
  () =>
    uploadOptions.value?.upload_drivers?.find((item) => item.enabled)?.label ||
    '',
);

const loadCategories = async () => {
  categories.value = await getUploadAssetCategoryTreeApi({ status: 1 });
};

const loadUploadOptions = async () => {
  uploadOptionsLoading.value = true;
  try {
    uploadOptions.value = await getUploadOptionsCached();
  } catch (error) {
    console.warn('加载上传选项失败:', error);
    uploadOptions.value = null;
    message.error('上传配置加载失败');
  } finally {
    uploadOptionsLoading.value = false;
  }
};

const loadData = async () => {
  searchParams.status = routeStatus.value;
  loading.value = true;
  try {
    const res = await getUploadAssetListApi({
      ...searchParams,
      page: pagination.current,
      limit: pagination.pageSize,
    });
    tableData.value = res.list || [];
    pagination.total = res.total || 0;
  } finally {
    loading.value = false;
  }
};

const resetSearch = () => {
  Object.assign(searchParams, {
    keyword: '',
    category_id: undefined,
    driver: undefined,
    type: undefined,
    status: routeStatus.value,
  });
  pagination.current = 1;
  loadData();
};

const handleTableChange = (pager: { current?: number; pageSize?: number }) => {
  pagination.current = pager.current || pagination.current;
  pagination.pageSize = pager.pageSize || pagination.pageSize;
  loadData();
};

const confirmAction = (
  title: string,
  content: string,
  action: () => Promise<any>,
) => {
  Modal.confirm({
    title,
    content,
    async onOk() {
      await action();
      message.success('操作成功');
      await loadData();
    },
  });
};

const handleDelete = (record: UploadAssetApi.AssetItem) =>
  confirmAction('删除素材', '无引用素材会进入回收站，确认删除吗？', () =>
    deleteUploadAssetApi(record.id),
  );

const handleRestore = (record: UploadAssetApi.AssetItem) =>
  confirmAction('恢复素材', '确认从回收站恢复该素材吗？', () =>
    restoreUploadAssetApi(record.id),
  );

const handlePurge = (record: UploadAssetApi.AssetItem) =>
  confirmAction('永久删除', '永久删除会清理存储对象，确认继续吗？', () =>
    purgeUploadAssetApi(record.id),
  );

const handleClearRecycle = () => {
  Modal.confirm({
    title: '清空回收站',
    content:
      '将永久删除回收站内全部素材和存储对象，删除后不可恢复，确认继续吗？',
    okText: '清空',
    okButtonProps: { danger: true },
    async onOk() {
      const res = await clearUploadAssetRecycleApi();
      message.success(`已清空 ${res?.count || 0} 个素材`);
      pagination.current = 1;
      await loadData();
    },
  });
};

const openDetail = async (record: UploadAssetApi.AssetItem) => {
  detailOpen.value = true;
  detailLoading.value = true;
  assetDetail.value = null;
  try {
    assetDetail.value = await getUploadAssetInfoApi(record.id);
  } finally {
    detailLoading.value = false;
  }
};

const renderPreview = (record: UploadAssetApi.AssetItem) => {
  if (record.type === 'image' && record.full_url) {
    return h(Image, {
      src: record.full_url,
      width: 48,
      height: 48,
      style: {
        borderRadius: '6px',
        objectFit: 'cover',
      },
    });
  }
  return formatAssetType(record.type);
};

const formatAssetType = (type: string) =>
  assetTypeOptions.value.find((item) => item.value === type)?.label || type;

const formatDriver = (driver?: string) =>
  driverOptions.value.find((item) => item.value === driver)?.label ||
  driver ||
  '-';

const formatFileSize = (size?: number) => {
  const bytes = Number(size || 0);
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';

  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let unitIndex = 0;
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex++;
  }

  const displayValue =
    unitIndex === 0 || value >= 10
      ? Math.round(value)
      : Number(value.toFixed(1));
  return `${displayValue} ${units[unitIndex]}`;
};

const formatImageSize = (record?: null | UploadAssetApi.AssetItem) => {
  const width = Number(record?.width || 0);
  const height = Number(record?.height || 0);
  return width > 0 && height > 0 ? `${width} × ${height}` : '-';
};

const formatHashShort = (hash?: string) => {
  const value = String(hash || '');
  return value === '' ? '-' : value.slice(0, 12);
};

const formatLocationStatus = (status?: number) =>
  Number(status) === 1 ? '可用' : '不可用';

const copyText = async (text?: string) => {
  const value = String(text || '');
  if (!value) return;
  try {
    await navigator.clipboard.writeText(value);
    message.success('已复制');
  } catch {
    message.error('复制失败，请手动选择文本复制');
  }
};

const columns = [
  {
    title: '预览',
    key: 'preview',
    width: 80,
    customRender: ({ record }: any) => renderPreview(record),
  },
  {
    title: '名称',
    dataIndex: 'original_name',
    width: 240,
    ellipsis: true,
    customRender: ({ record }: any) =>
      h('div', { class: 'asset-name-cell' }, [
        h(
          'div',
          { class: 'asset-name-cell__primary' },
          record.original_name || record.name,
        ),
        record.name && record.name !== record.original_name
          ? h('div', { class: 'asset-name-cell__secondary' }, record.name)
          : null,
      ]),
  },
  { title: '分类', dataIndex: 'category_name', width: 120 },
  {
    title: '类型',
    dataIndex: 'type',
    width: 90,
    customRender: ({ text }: any) => formatAssetType(text),
  },
  {
    title: '扩展名',
    dataIndex: 'ext',
    width: 90,
    customRender: ({ text }: any) => text || '-',
  },
  {
    title: '大小',
    dataIndex: 'size',
    width: 110,
    customRender: ({ text }: any) => formatFileSize(Number(text || 0)),
  },
  {
    title: '尺寸',
    key: 'dimension',
    width: 120,
    customRender: ({ record }: any) => formatImageSize(record),
  },
  {
    title: 'Hash',
    dataIndex: 'hash',
    width: 140,
    customRender: ({ record }: any) =>
      h('span', { title: record.hash || '' }, formatHashShort(record.hash)),
  },
  { title: '引用', dataIndex: 'usage_count', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: any) =>
      h(Tag, { color: record.status === 1 ? 'green' : 'orange' }, () =>
        record.status === 1 ? '正常' : '回收站',
      ),
  },
  { title: '创建时间', dataIndex: 'create_time', width: 170 },
  { title: '操作', key: 'action', width: 250 },
];
const tableScroll = { x: 1450 };

const locationColumns = [
  {
    title: '驱动',
    dataIndex: 'driver',
    width: 90,
    customRender: ({ text }: any) => formatDriver(text),
  },
  { title: 'Bucket', dataIndex: 'bucket', width: 140, ellipsis: true },
  { title: '对象路径', dataIndex: 'path', key: 'path', width: 320 },
  { title: '区域', dataIndex: 'region', width: 110, ellipsis: true },
  { title: 'Endpoint', dataIndex: 'endpoint', width: 180, ellipsis: true },
  { title: 'ETag', dataIndex: 'etag', width: 160, ellipsis: true },
  {
    title: '大小',
    dataIndex: 'size',
    width: 100,
    customRender: ({ text }: any) => formatFileSize(Number(text || 0)),
  },
  { title: '主位置', dataIndex: 'is_primary', key: 'is_primary', width: 90 },
  { title: '状态', dataIndex: 'status', key: 'location_status', width: 90 },
];

const usageColumns = [
  { title: '引用类型', dataIndex: 'owner_type', width: 140 },
  { title: '业务ID', dataIndex: 'owner_id', width: 100 },
  { title: '字段', dataIndex: 'field', width: 120 },
  { title: '排序', dataIndex: 'sort', width: 80 },
  { title: '创建时间', dataIndex: 'create_time', width: 170 },
];

onMounted(async () => {
  searchParams.status = routeStatus.value;
  await Promise.all([loadCategories(), loadUploadOptions()]);
  await loadData();
});

watch(
  () => route.path,
  () => {
    searchParams.status = routeStatus.value;
    pagination.current = 1;
    loadData();
  },
);
</script>

<template>
  <div class="asset-page">
    <div class="asset-toolbar">
      <a-input
        v-model:value="searchParams.keyword"
        placeholder="关键词"
        allow-clear
        class="asset-toolbar__item"
      />
      <a-tree-select
        v-model:value="searchParams.category_id"
        :tree-data="categoryOptions()"
        placeholder="分类"
        allow-clear
        tree-default-expand-all
        class="asset-toolbar__item"
      />
      <a-select
        v-model:value="searchParams.type"
        placeholder="类型"
        allow-clear
        show-search
        class="asset-toolbar__item"
        option-filter-prop="label"
        :options="assetTypeOptions"
        :loading="uploadOptionsLoading"
      />
      <a-select
        v-model:value="searchParams.driver"
        placeholder="上传驱动"
        allow-clear
        show-search
        class="asset-toolbar__item"
        option-filter-prop="label"
        :options="driverOptions"
        :loading="uploadOptionsLoading"
      />
      <span v-if="currentDriverLabel" class="asset-toolbar__hint">
        当前驱动：{{ currentDriverLabel }}
      </span>
      <a-button
        type="primary"
        @click="loadData"
        v-access:code="'SystemUploadAssetList'"
      >
        查询
      </a-button>
      <a-button @click="resetSearch">重置</a-button>
      <a-button
        v-if="isRecyclePage"
        danger
        :disabled="pagination.total <= 0"
        @click="handleClearRecycle"
        v-access:code="'SystemUploadAssetRecycleClear'"
      >
        清空回收站
      </a-button>
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
            type="link"
            size="small"
            @click="openDetail(record)"
            v-access:code="'SystemUploadAssetInfo'"
          >
            详情
          </a-button>
          <a-button
            v-if="record.status === 1"
            type="link"
            size="small"
            @click="handleDelete(record)"
            v-access:code="'SystemUploadAssetDelete'"
          >
            删除
          </a-button>
          <a-button
            v-if="record.status === 0"
            type="link"
            size="small"
            @click="handleRestore(record)"
            v-access:code="'SystemUploadAssetRestore'"
          >
            恢复
          </a-button>
          <a-button
            v-if="record.status === 0"
            type="link"
            danger
            size="small"
            @click="handlePurge(record)"
            v-access:code="'SystemUploadAssetPurge'"
          >
            永久删除
          </a-button>
        </template>
      </template>
    </a-table>

    <a-drawer
      v-model:open="detailOpen"
      title="素材详情"
      width="920px"
      destroy-on-close
    >
      <a-spin :spinning="detailLoading">
        <template v-if="assetDetail">
          <div class="asset-detail">
            <div class="asset-detail__section">
              <div class="asset-detail__title">基础信息</div>
              <a-descriptions bordered size="small" :column="2">
                <a-descriptions-item label="素材ID">
                  {{ assetDetail.id }}
                </a-descriptions-item>
                <a-descriptions-item label="状态">
                  <a-tag :color="assetDetail.status === 1 ? 'green' : 'orange'">
                    {{ assetDetail.status === 1 ? '正常' : '回收站' }}
                  </a-tag>
                </a-descriptions-item>
                <a-descriptions-item label="原始文件名">
                  {{ assetDetail.original_name || '-' }}
                </a-descriptions-item>
                <a-descriptions-item label="素材名称">
                  {{ assetDetail.name || '-' }}
                </a-descriptions-item>
                <a-descriptions-item label="分类">
                  {{ assetDetail.category_name || '-' }}
                </a-descriptions-item>
                <a-descriptions-item label="类型">
                  {{ formatAssetType(assetDetail.type) }}
                </a-descriptions-item>
                <a-descriptions-item label="MIME">
                  {{ assetDetail.mime || '-' }}
                </a-descriptions-item>
                <a-descriptions-item label="扩展名">
                  {{ assetDetail.ext || '-' }}
                </a-descriptions-item>
                <a-descriptions-item label="文件大小">
                  {{ formatFileSize(assetDetail.size) }}
                </a-descriptions-item>
                <a-descriptions-item label="图片尺寸">
                  {{ formatImageSize(assetDetail) }}
                </a-descriptions-item>
                <a-descriptions-item label="上传模块">
                  {{ assetDetail.module || '-' }}
                </a-descriptions-item>
                <a-descriptions-item label="上传者">
                  {{ assetDetail.uploader_type || '-' }} /
                  {{ assetDetail.uploader_id || 0 }}
                </a-descriptions-item>
                <a-descriptions-item label="可见性">
                  {{ assetDetail.visibility || '-' }}
                </a-descriptions-item>
                <a-descriptions-item label="引用数">
                  {{ assetDetail.usage_count || 0 }}
                </a-descriptions-item>
                <a-descriptions-item label="SHA256" :span="2">
                  <span class="asset-detail__mono">{{
                    assetDetail.hash || '-'
                  }}</span>
                  <a-button
                    v-if="assetDetail.hash"
                    type="link"
                    size="small"
                    @click="copyText(assetDetail.hash)"
                  >
                    复制
                  </a-button>
                </a-descriptions-item>
                <a-descriptions-item label="创建时间">
                  {{ assetDetail.create_time || '-' }}
                </a-descriptions-item>
                <a-descriptions-item label="更新时间">
                  {{ assetDetail.update_time || '-' }}
                </a-descriptions-item>
              </a-descriptions>
            </div>

            <div class="asset-detail__section">
              <div class="asset-detail__title">存储位置</div>
              <a-table
                row-key="id"
                size="small"
                :columns="locationColumns"
                :data-source="assetDetail.locations || []"
                :pagination="false"
                :scroll="{ x: 1280 }"
              >
                <template #bodyCell="{ column, record }">
                  <template v-if="column.key === 'path'">
                    <div class="asset-detail__path" :title="record.path">
                      {{ record.path || '-' }}
                    </div>
                    <a-button
                      v-if="record.path"
                      type="link"
                      size="small"
                      @click="copyText(record.path)"
                    >
                      复制路径
                    </a-button>
                  </template>
                  <template v-else-if="column.key === 'is_primary'">
                    <a-tag
                      :color="record.is_primary === 1 ? 'blue' : 'default'"
                    >
                      {{ record.is_primary === 1 ? '主位置' : '备用' }}
                    </a-tag>
                  </template>
                  <template v-else-if="column.key === 'location_status'">
                    <a-tag :color="record.status === 1 ? 'green' : 'orange'">
                      {{ formatLocationStatus(record.status) }}
                    </a-tag>
                  </template>
                </template>
              </a-table>
            </div>

            <div class="asset-detail__section">
              <div class="asset-detail__title">引用关系</div>
              <a-table
                row-key="id"
                size="small"
                :columns="usageColumns"
                :data-source="assetDetail.usage || []"
                :pagination="false"
              />
            </div>
          </div>
        </template>
      </a-spin>
    </a-drawer>
  </div>
</template>

<style scoped>
.asset-page {
  padding: 16px;
}

.asset-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
}

.asset-toolbar__item {
  width: 180px;
}

.asset-toolbar__hint {
  display: inline-flex;
  align-items: center;
  height: 32px;
  font-size: 13px;
  color: hsl(var(--muted-foreground));
}

.asset-name-cell {
  display: grid;
  min-width: 0;
  gap: 2px;
}

.asset-name-cell__primary,
.asset-name-cell__secondary {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.asset-name-cell__secondary {
  font-size: 12px;
  color: hsl(var(--muted-foreground));
}

.asset-detail {
  display: grid;
  gap: 18px;
}

.asset-detail__section {
  display: grid;
  gap: 10px;
}

.asset-detail__title {
  font-size: 14px;
  font-weight: 600;
  color: hsl(var(--foreground));
}

.asset-detail__mono,
.asset-detail__path {
  font-family:
    ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono',
    'Courier New', monospace;
}

.asset-detail__path {
  max-width: 300px;
  overflow: hidden;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
