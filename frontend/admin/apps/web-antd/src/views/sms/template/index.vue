<script lang="ts" setup>
import type { SmsTemplateApi } from '#/api/sms/template';

import { computed, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

import { getSmsProviderListApi } from '#/api/sms/provider';
import type { SmsProviderApi } from '#/api/sms/provider';
import {
  createSmsTemplateApi,
  deleteSmsTemplateApi,
  getSmsTemplateInfoApi,
  getSmsTemplateListApi,
  syncAllSmsTemplateApi,
  syncSmsTemplateStatusApi,
  updateSmsTemplateApi,
} from '#/api/sms/template';
import { useFormModal, useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'SmsTemplate' });

const { hasAccessByCodes } = useAccess();

const auditStatusOptions = [
  { label: '审核中', value: 'pending', color: 'gold' },
  { label: '审核通过', value: 'passed', color: 'green' },
  { label: '审核失败', value: 'rejected', color: 'red' },
  { label: '仅本地', value: 'local_only', color: 'default' },
];

const templateTypeOptions = [
  { label: '验证码', value: 0 },
  { label: '通知', value: 1 },
  { label: '推广', value: 2 },
  { label: '国际/港澳台', value: 3 },
];

const providers = ref<SmsProviderApi.ProviderItem[]>([]);
const loadProviders = async () => {
  const res = await getSmsProviderListApi({ page: 1, limit: 100 });
  providers.value = res.list;
};

const searchParams = ref<SmsTemplateApi.ListParams>({
  keyword: '',
  provider_id: undefined,
  audit_status: undefined,
});

const { tableData, loading, pagination, loadData, refresh, handleDelete } =
  useTableCrud<SmsTemplateApi.TemplateItem, SmsTemplateApi.ListParams>(
    {
      delete: deleteSmsTemplateApi,
      getInfo: getSmsTemplateInfoApi,
      list: getSmsTemplateListApi,
    },
    { immediateLoad: false },
  );

const {
  modalVisible,
  modalTitle,
  formData,
  formRef,
  openCreateModal,
  openEditModal,
  handleSubmit,
} = useFormModal<SmsTemplateApi.TemplateItem>();

const isEdit = computed(() => modalTitle.value.includes('编辑'));

const handleCreate = async () => {
  if (providers.value.length === 0) await loadProviders();
  openCreateModal({
    provider_id: providers.value[0]?.id,
    template_name: '',
    template_type: 0,
    template_content: '您的验证码是 ${code},5 分钟内有效,请勿泄露。',
    remark: '',
  });
};

const handleEdit = async (row: SmsTemplateApi.TemplateItem) => {
  await openEditModal(row, getSmsTemplateInfoApi);
};

const handleFormSubmit = async () => {
  await handleSubmit(
    {
      create: createSmsTemplateApi,
      update: (id, data) => updateSmsTemplateApi(id, data),
    },
    () => loadData(searchParams.value),
  );
};

const handleSync = async (row: SmsTemplateApi.TemplateItem) => {
  await syncSmsTemplateStatusApi(row.id);
  message.success('同步成功');
  loadData(searchParams.value);
};

const handleSyncAll = async () => {
  const providerId = searchParams.value.provider_id;
  if (!providerId) {
    message.warning('请先选择服务商再批量同步');
    return;
  }
  const stat = await syncAllSmsTemplateApi(providerId);
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

const templateTypeLabel = (t: number) =>
  templateTypeOptions.find((o) => o.value === t)?.label || `类型 ${t}`;

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '服务商', dataIndex: 'provider_id', width: 160 },
  { title: '模板名称', dataIndex: 'template_name', width: 200 },
  { title: '模板编码', dataIndex: 'template_code', width: 180 },
  { title: '类型', dataIndex: 'template_type', width: 100 },
  { title: '审核状态', dataIndex: 'audit_status', width: 120 },
  { title: '审核备注', dataIndex: 'audit_reason', ellipsis: true },
  { title: '最近同步', dataIndex: 'last_synced_at', width: 180 },
  { title: '操作', key: 'action', width: 260 },
];

if (hasAccessByCodes(['SmsTemplateList'])) {
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
        v-access:code="'SmsTemplateCreate'"
      >
        新增模板
      </a-button>
      <a-button
        class="ml-2"
        @click="handleSyncAll"
        v-access:code="'SmsTemplateSyncAll'"
      >
        批量同步状态
      </a-button>
      <a-button class="ml-2" @click="refresh" v-access:code="'SmsTemplateList'">
        刷新
      </a-button>
    </div>

    <a-form layout="inline" class="mb-4" v-access:code="'SmsTemplateList'">
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
          placeholder="模板名称/编码"
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
      :scroll="{ x: 1400 }"
      row-key="id"
      @change="
        (newPagination) => {
          pagination.current = newPagination.current;
          pagination.pageSize = newPagination.pageSize;
          loadData(searchParams);
        }
      "
      v-access:code="'SmsTemplateList'"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.dataIndex === 'provider_id'">
          {{ providerName(record.provider_id) }}
        </template>
        <template v-if="column.dataIndex === 'template_type'">
          {{ templateTypeLabel(record.template_type) }}
        </template>
        <template v-if="column.dataIndex === 'audit_status'">
          <a-tag :color="auditStatusTag(record.audit_status)?.color">
            {{ auditStatusTag(record.audit_status)?.label || record.audit_status }}
          </a-tag>
        </template>
        <template v-if="column.dataIndex === 'template_code'">
          <span v-if="record.template_code">{{ record.template_code }}</span>
          <a-tag v-else color="default">未提交</a-tag>
        </template>
        <template v-if="column.key === 'action'">
          <a-space>
            <a-button
              type="link"
              size="small"
              @click="handleSync(record)"
              v-access:code="'SmsTemplateSyncStatus'"
            >
              同步
            </a-button>
            <a-button
              type="link"
              size="small"
              @click="handleEdit(record)"
              v-access:code="'SmsTemplateUpdate'"
            >
              编辑
            </a-button>
            <a-button
              type="link"
              danger
              size="small"
              @click="handleDelete(record, 'template_name')"
              v-access:code="'SmsTemplateDelete'"
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
      width="640px"
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
            :disabled="isEdit"
            :options="providers.map((p) => ({ label: p.name, value: p.id }))"
          />
        </a-form-item>
        <a-form-item
          label="模板名称"
          name="template_name"
          :rules="[{ required: true, message: '请输入模板名称' }]"
        >
          <a-input
            v-model:value="formData.template_name"
            placeholder="如：登录验证码"
          />
        </a-form-item>
        <a-form-item label="模板类型" name="template_type">
          <a-select
            v-model:value="formData.template_type"
            :options="templateTypeOptions"
          />
        </a-form-item>
        <a-form-item
          label="模板内容"
          name="template_content"
          :rules="[{ required: true, message: '请输入模板内容' }]"
        >
          <a-textarea
            v-model:value="formData.template_content"
            :rows="4"
            placeholder="使用 ${code} 作为验证码占位符"
          />
        </a-form-item>
        <a-form-item label="申请说明" name="remark">
          <a-textarea
            v-model:value="formData.remark"
            :rows="3"
            placeholder="向阿里云说明使用场景,有助审核"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
