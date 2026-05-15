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
  importSmsSignApi,
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

// 创建签名表单的本地状态(资质文件单独管理,不放进 formData 避免后端字段污染)
const signFiles = ref<SmsSignApi.SignFileItem[]>([]);
const uploadingFiles = ref(false);

const handleCreate = async () => {
  if (providers.value.length === 0) await loadProviders();
  signFiles.value = [];
  openCreateModal({
    provider_id: providers.value[0]?.id,
    sign_name: '',
    sign_source: 0,
    sign_type: 1,
    remark: '',
  });
};

const readFileAsBase64 = (file: File): Promise<SmsSignApi.SignFileItem> =>
  new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = reader.result as string;
      // dataURL 形如 "data:image/png;base64,XXXX"
      const base64 = result.split(',')[1] || '';
      const ext = file.name.split('.').pop()?.toLowerCase() || 'jpg';
      resolve({ file_contents: base64, file_suffix: ext });
    };
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });

const handleBeforeUpload = async (file: File) => {
  // 阿里云限制单文件 ≤ 2MB
  if (file.size > 2 * 1024 * 1024) {
    message.error(`${file.name} 超过 2MB,请压缩后再上传`);
    return false;
  }
  uploadingFiles.value = true;
  try {
    const item = await readFileAsBase64(file);
    signFiles.value.push(item);
    message.success(`${file.name} 已就绪`);
  } catch (e) {
    message.error(`${file.name} 读取失败`);
    console.error(e);
  } finally {
    uploadingFiles.value = false;
  }
  // 返回 false 阻止 ant-design 默认的上传行为(我们只要 base64,不上服务器)
  return false;
};

const handleRemoveFile = (idx: number) => {
  signFiles.value.splice(idx, 1);
};

const handleFormSubmit = async () => {
  if (signFiles.value.length === 0) {
    message.error('请至少上传一个资质证明文件(营业执照/App 截图/网站备案截图等)');
    return;
  }
  // 把 sign_files 临时塞进 formData 让 useFormModal 一起提交
  (formData.value as any).sign_files = signFiles.value;
  await handleSubmit({ create: createSmsSignApi }, () => {
    signFiles.value = [];
    loadData(searchParams.value);
  });
};

// ------------------- 导入已审核签名 -------------------

const importModalVisible = ref(false);
const importFormData = ref<SmsSignApi.ImportParams>({
  provider_id: 0,
  sign_name: '',
});
const importing = ref(false);

const openImportModal = async () => {
  if (providers.value.length === 0) await loadProviders();
  importFormData.value = {
    provider_id: providers.value[0]?.id || 0,
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

const handleSync = async (row: SmsSignApi.SignItem) => {
  await syncSmsSignStatusApi(row.id);
  message.success('同步成功');
  loadData(searchParams.value);
};

const handleSyncAll = async () => {
  let providerId = searchParams.value.provider_id;
  // 只有 1 个服务商时自动选中,免去人工筛选
  if (!providerId && providers.value.length === 1) {
    providerId = providers.value[0]!.id;
  }
  if (!providerId) {
    message.warning('当前存在多个服务商,请先在上方筛选中选择要同步的服务商');
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
  { title: '审核备注', dataIndex: 'audit_reason', width: 360 },
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
      <a-tooltip title="本地提交新签名 + 资质文件,推送到阿里云审核">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SmsSignCreate'"
        >
          新增签名（推送阿里云）
        </a-button>
      </a-tooltip>
      <a-tooltip title="把已经在阿里云审核通过的签名拉回本地,不触发新审核">
        <a-button
          class="ml-2"
          @click="openImportModal"
          v-access:code="'SmsSignImport'"
        >
          导入已审核签名
        </a-button>
      </a-tooltip>
      <a-tooltip
        title="把本地所有签名一次性向阿里云查最新审核状态并回写,适合提交后过段时间批量刷新"
      >
        <a-button
          class="ml-2"
          @click="handleSyncAll"
          v-access:code="'SmsSignSyncAll'"
        >
          批量同步状态
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
        <template v-if="column.dataIndex === 'audit_reason'">
          <div
            class="whitespace-pre-wrap break-all text-xs leading-relaxed"
            style="max-height: 120px; overflow-y: auto"
          >
            {{ record.audit_reason || '-' }}
          </div>
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
        <a-form-item label="资质文件" required>
          <a-upload
            :before-upload="handleBeforeUpload"
            :show-upload-list="false"
            accept=".jpg,.jpeg,.png,.pdf,.gif,.bmp"
            :multiple="true"
          >
            <a-button :loading="uploadingFiles">
              选择文件（≤2MB,可多选）
            </a-button>
          </a-upload>
          <div v-if="signFiles.length > 0" class="mt-2 space-y-1">
            <div
              v-for="(f, idx) in signFiles"
              :key="idx"
              class="flex items-center gap-2 rounded border border-dashed border-gray-300 px-2 py-1 text-xs"
            >
              <span class="flex-1">
                文件 {{ idx + 1 }}（.{{ f.file_suffix }}，
                {{ Math.round(((f.file_contents.length * 3) / 4 / 1024) * 10) / 10 }} KB）
              </span>
              <a-button
                type="link"
                danger
                size="small"
                @click="handleRemoveFile(idx)"
              >
                移除
              </a-button>
            </div>
          </div>
          <div class="mt-1 text-xs text-gray-500">
            企业:营业执照；个人:身份证 + App 截图/网站备案截图；商标:商标注册证。
            支持 jpg/png/pdf,单文件 ≤ 2MB
          </div>
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal
      v-model:open="importModalVisible"
      title="从阿里云导入已审核签名"
      width="520px"
      :confirm-loading="importing"
      @ok="handleImportSubmit"
    >
      <a-alert
        type="info"
        show-icon
        message="只调用 QuerySmsSign 把阿里云上已审核通过的签名拉回本地,不会触发新审核"
        class="mb-4"
      />
      <a-form :label-col="{ span: 6 }" :wrapper-col="{ span: 16 }">
        <a-form-item label="服务商" required>
          <a-select
            v-model:value="importFormData.provider_id"
            :options="providers.map((p) => ({ label: p.name, value: p.id }))"
          />
        </a-form-item>
        <a-form-item label="签名名称" required>
          <a-input
            v-model:value="importFormData.sign_name"
            placeholder="必须与阿里云控制台的签名文本完全一致"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
