<script lang="ts" setup>
import type { SmsProviderApi } from '#/api/sms/provider';
import type { SmsSignApi } from '#/api/sms/sign';

import { computed, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

import { getSmsProviderListApi } from '#/api/sms/provider';
import {
  deleteSmsSignApi,
  getSmsSignListApi,
  importSmsSignApi,
} from '#/api/sms/sign';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'SmsSign' });

const { hasAccessByCodes } = useAccess();

const providers = ref<SmsProviderApi.ProviderItem[]>([]);
const loadProviders = async () => {
  const res = await getSmsProviderListApi({ page: 1, limit: 100 });
  providers.value = res.list;
};

const providerOptions = computed(() =>
  providers.value.map((provider) => ({
    label: provider.name,
    value: provider.id,
  })),
);
const canImport = computed(() => providers.value.length > 0);

const searchParams = ref<SmsSignApi.ListParams>({
  keyword: '',
  provider_id: undefined,
});

const { tableData, loading, pagination, loadData, refresh, handleDelete } =
  useTableCrud<SmsSignApi.SignItem, SmsSignApi.ListParams>(
    {
      delete: deleteSmsSignApi,
      list: getSmsSignListApi,
    },
    { immediateLoad: false },
  );

const importModalVisible = ref(false);
const importFormData = ref<SmsSignApi.ImportParams>({
  provider_id: undefined,
  remark: '',
  sign_name: '',
});
const importing = ref(false);

const openImportModal = async () => {
  if (providers.value.length === 0) await loadProviders();
  if (!canImport.value) {
    message.warning('请先在「服务商管理」新建阿里云短信服务商');
    return;
  }
  importFormData.value = {
    provider_id:
      providers.value.length === 1 ? providers.value[0]?.id : undefined,
    remark: '',
    sign_name: '',
  };
  importModalVisible.value = true;
};

const handleImportSubmit = async () => {
  if (!importFormData.value.provider_id || !importFormData.value.sign_name) {
    message.error('请完整填写服务商和签名名称');
    return;
  }
  importing.value = true;
  try {
    await importSmsSignApi(importFormData.value);
    message.success('导入成功');
    importModalVisible.value = false;
    loadData(searchParams.value);
  } finally {
    importing.value = false;
  }
};

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    provider_id: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

const providerName = (id: number) =>
  providers.value.find((p) => p.id === id)?.name || `#${id}`;

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '服务商', dataIndex: 'provider_id', width: 160 },
  { title: '签名', dataIndex: 'sign_name', width: 180 },
  { title: '备注', dataIndex: 'remark', width: 320 },
  { title: '创建时间', dataIndex: 'create_time', width: 180 },
  { title: '操作', key: 'action', width: 100 },
];

if (hasAccessByCodes(['SmsSignList'])) {
  loadProviders();
  loadData(searchParams.value);
}
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-tooltip title="本地登记短信签名名称,用于场景绑定和发送短信">
        <a-button
          type="primary"
          :disabled="!canImport"
          @click="openImportModal"
          v-access:code="'SmsSignImport'"
        >
          导入签名
        </a-button>
      </a-tooltip>
      <a-button class="ml-2" @click="refresh" v-access:code="'SmsSignList'">
        刷新
      </a-button>
    </div>

    <a-form layout="inline" class="mb-4" v-access:code="'SmsSignList'">
      <a-form-item label="服务商">
        <a-select
          v-model:value="searchParams.provider_id"
          placeholder="全部"
          allow-clear
          :options="providers.map((p) => ({ label: p.name, value: p.id }))"
          style="width: 180px"
        />
      </a-form-item>
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="签名名称"
          allow-clear
          style="width: 200px"
        />
      </a-form-item>
      <a-form-item>
        <a-button
          type="primary"
          @click="
            () => {
              pagination.current = 1;
              loadData(searchParams.value);
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
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1200 }"
      row-key="id"
      @change="
        (newPagination) => {
          pagination.current = newPagination.current;
          pagination.pageSize = newPagination.pageSize;
          loadData(searchParams.value);
        }
      "
      v-access:code="'SmsSignList'"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.dataIndex === 'provider_id'">
          {{ providerName(record.provider_id) }}
        </template>
        <template v-if="column.dataIndex === 'remark'">
          {{ record.remark || '-' }}
        </template>
        <template v-if="column.key === 'action'">
          <a-space>
            <a-button
              type="link"
              danger
              size="small"
              @click="handleDelete(record, 'sign_name')"
              v-access:code="'SmsSignDelete'"
            >
              删除
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>

    <a-modal
      v-model:open="importModalVisible"
      title="导入签名"
      width="520px"
      :confirm-loading="importing"
      @ok="handleImportSubmit"
    >
      <a-alert
        type="info"
        show-icon
        message="签名只在本地登记名称,不会调用短信平台接口,也不会同步平台状态。请确保签名名称与短信平台实际可用签名一致。"
        class="mb-4"
      />
      <a-form :label-col="{ span: 6 }" :wrapper-col="{ span: 16 }">
        <a-form-item label="服务商" required>
          <a-select
            v-model:value="importFormData.provider_id"
            :options="providerOptions"
            placeholder="请选择服务商"
          />
        </a-form-item>
        <a-form-item label="签名名称" required>
          <a-input
            v-model:value="importFormData.sign_name"
            placeholder="请输入短信平台已可用的签名名称"
          />
        </a-form-item>
        <a-form-item label="备注">
          <a-textarea
            v-model:value="importFormData.remark"
            :rows="3"
            placeholder="可选,例如:生产环境验证码签名"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
