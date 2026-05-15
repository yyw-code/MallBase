<script lang="ts" setup>
import type { SmsSignApi } from '#/api/sms/sign';

import { ref } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

import { getSmsProviderListApi } from '#/api/sms/provider';
import type { SmsProviderApi } from '#/api/sms/provider';
import {
  createSmsSignApi,
  deleteSmsSignApi,
  getSmsSignListApi,
  syncAllSmsSignApi,
  syncSmsSignStatusApi,
} from '#/api/sms/sign';
import { useFormModal, useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'SmsSign' });

const { hasAccessByCodes } = useAccess();

const auditStatusOptions = [
  { label: '审核中', value: 'pending', color: 'gold' },
  { label: '审核通过', value: 'passed', color: 'green' },
  { label: '审核失败', value: 'rejected', color: 'red' },
  { label: '仅本地', value: 'local_only', color: 'default' },
];

const signSourceOptions = [
  { label: '企事业单位的全称或简称', value: 0 },
  { label: '工信部备案网站全称或简称', value: 1 },
  { label: 'App 应用全称', value: 2 },
  { label: '公众号或小程序', value: 3 },
  { label: '电商平台店铺名', value: 4 },
  { label: '商标名', value: 5 },
];

const signTypeOptions = [
  { label: '验证码', value: 0 },
  { label: '通用', value: 1 },
];

const providers = ref<SmsProviderApi.ProviderItem[]>([]);
const loadProviders = async () => {
  const res = await getSmsProviderListApi({ page: 1, limit: 100 });
  providers.value = res.list;
};

const searchParams = ref<SmsSignApi.ListParams>({
  keyword: '',
  provider_id: undefined,
  audit_status: undefined,
});

const { tableData, loading, pagination, loadData, refresh, handleDelete } =
  useTableCrud<SmsSignApi.SignItem, SmsSignApi.ListParams>(
    {
      delete: deleteSmsSignApi,
      list: getSmsSignListApi,
    },
    { immediateLoad: false },
  );

const {
  modalVisible,
  modalTitle,
  formData,
  formRef,
  openCreateModal,
  handleSubmit,
} = useFormModal<SmsSignApi.SignItem>();

const handleCreate = async () => {
  if (providers.value.length === 0) await loadProviders();
  openCreateModal({
    provider_id: providers.value[0]?.id,
    sign_name: '',
    sign_source: 0,
    sign_type: 1,
    remark: '',
  });
};

const handleFormSubmit = async () => {
  await handleSubmit({ create: createSmsSignApi }, () =>
    loadData(searchParams.value),
  );
};

const handleSync = async (row: SmsSignApi.SignItem) => {
  await syncSmsSignStatusApi(row.id);
  message.success('同步成功');
  loadData(searchParams.value);
};

const handleSyncAll = async () => {
  const providerId = searchParams.value.provider_id;
  if (!providerId) {
    message.warning('请先选择服务商再批量同步');
    return;
  }
  const stat = await syncAllSmsSignApi(providerId);
  message.success(`同步完成: 成功 ${stat.success} 个,失败 ${stat.failed} 个`);
  loadData(searchParams.value);
};

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    provider_id: undefined,
    audit_status: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

const auditStatusTag = (status: string) =>
  auditStatusOptions.find((o) => o.value === status);

const providerName = (id: number) =>
  providers.value.find((p) => p.id === id)?.name || `#${id}`;

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '服务商', dataIndex: 'provider_id', width: 160 },
  { title: '签名', dataIndex: 'sign_name', width: 180 },
  { title: '类型', dataIndex: 'sign_type', width: 100 },
  { title: '审核状态', dataIndex: 'audit_status', width: 120 },
  { title: '审核备注', dataIndex: 'audit_reason', ellipsis: true },
  { title: '最近同步', dataIndex: 'last_synced_at', width: 180 },
  { title: '操作', key: 'action', width: 200 },
];

if (hasAccessByCodes(['SmsSignList'])) {
  loadProviders();
  loadData(searchParams.value);
}
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button
        type="primary"
        @click="handleCreate"
        v-access:code="'SmsSignCreate'"
      >
        新增签名
      </a-button>
      <a-button
        class="ml-2"
        @click="handleSyncAll"
        v-access:code="'SmsSignSyncAll'"
      >
        批量同步状态
      </a-button>
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
      <a-form-item label="审核">
        <a-select
          v-model:value="searchParams.audit_status"
          placeholder="全部"
          allow-clear
          :options="auditStatusOptions.map((o) => ({ label: o.label, value: o.value }))"
          style="width: 140px"
        />
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
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1200 }"
      row-key="id"
      @change="
        (newPagination) => {
          pagination.current = newPagination.current;
          pagination.pageSize = newPagination.pageSize;
          loadData(searchParams);
        }
      "
      v-access:code="'SmsSignList'"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.dataIndex === 'provider_id'">
          {{ providerName(record.provider_id) }}
        </template>
        <template v-if="column.dataIndex === 'sign_type'">
          {{ record.sign_type === 0 ? '验证码' : '通用' }}
        </template>
        <template v-if="column.dataIndex === 'audit_status'">
          <a-tag :color="auditStatusTag(record.audit_status)?.color">
            {{ auditStatusTag(record.audit_status)?.label || record.audit_status }}
          </a-tag>
        </template>
        <template v-if="column.key === 'action'">
          <a-space>
            <a-button
              type="link"
              size="small"
              @click="handleSync(record)"
              v-access:code="'SmsSignSyncStatus'"
            >
              同步状态
            </a-button>
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
      v-model:open="modalVisible"
      :title="modalTitle"
      width="600px"
      @ok="handleFormSubmit"
    >
      <a-form
        ref="formRef"
        :model="formData"
        :label-col="{ span: 6 }"
        :wrapper-col="{ span: 16 }"
      >
        <a-form-item
          label="服务商"
          name="provider_id"
          :rules="[{ required: true, message: '请选择服务商' }]"
        >
          <a-select
            v-model:value="formData.provider_id"
            :options="providers.map((p) => ({ label: p.name, value: p.id }))"
          />
        </a-form-item>
        <a-form-item
          label="签名名称"
          name="sign_name"
          :rules="[{ required: true, message: '请输入签名名称' }]"
        >
          <a-input
            v-model:value="formData.sign_name"
            placeholder="阿里云控制台审核通过后的签名文本"
          />
        </a-form-item>
        <a-form-item label="签名来源" name="sign_source">
          <a-select
            v-model:value="formData.sign_source"
            :options="signSourceOptions"
          />
        </a-form-item>
        <a-form-item label="签名类型" name="sign_type">
          <a-select
            v-model:value="formData.sign_type"
            :options="signTypeOptions"
          />
        </a-form-item>
        <a-form-item label="申请说明" name="remark">
          <a-textarea
            v-model:value="formData.remark"
            :rows="3"
            placeholder="向阿里云说明用途场景,有助审核"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
