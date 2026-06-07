<script lang="ts" setup>
import type { SmsSceneApi } from '#/api/sms/scene';
import type { SmsSignApi } from '#/api/sms/sign';
import type { SmsTemplateApi } from '#/api/sms/template';

import { computed, ref, watch } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

import { extractPlaceholders, isPnvsDriver } from '#/api/sms/constants';
import { getSmsProviderListApi } from '#/api/sms/provider';
import type { SmsProviderApi } from '#/api/sms/provider';
import { getAllSmsSceneApi } from '#/api/sms/scene';
import { getSmsSignListApi } from '#/api/sms/sign';
import {
  createSmsTemplateApi,
  createSmsTemplateByScenesApi,
  deleteSmsTemplateApi,
  getSmsTemplateInfoApi,
  getSmsTemplateListApi,
  importSmsTemplateApi,
  syncAllSmsTemplateApi,
  syncBatchSmsTemplateApi,
  syncSmsTemplateStatusApi,
  updateSmsTemplateApi,
} from '#/api/sms/template';
import { useFormModal, useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'SmsTemplate' });

const { hasAccessByCodes } = useAccess();

const auditStatusOptions = [
  { label: '提交中', value: 'submitting', color: 'processing' },
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

// 阿里云模板必须关联一个已存在的短信签名(CreateSmsTemplate 的 RelatedSignName)
const signs = ref<SmsSignApi.SignItem[]>([]);
const loadSigns = async (providerId?: number) => {
  if (!providerId) {
    signs.value = [];
    return;
  }
  try {
    const res = await getSmsSignListApi({
      provider_id: providerId,
      page: 1,
      limit: 100,
    });
    signs.value = res.list;
  } catch (error) {
    console.error('加载签名列表失败:', error);
    signs.value = [];
  }
};

const SIGN_AUDIT_LABEL: Record<string, string> = {
  local_only: '仅本地', passed: '已通过', pending: '审核中', rejected: '已驳回',
};
const signOptions = computed(() =>
  signs.value.map((s) => ({
    label: `${s.sign_name}（${SIGN_AUDIT_LABEL[s.audit_status] ?? s.audit_status}）`,
    value: s.id,
  })),
);

// 非 PNVS 服务商:模板可从阿里云控制台导入(PNVS 无 QuerySmsTemplate API)
const importableProviders = computed(() =>
  providers.value.filter((p) => !isPnvsDriver(p.driver)),
);
const canImport = computed(() => importableProviders.value.length > 0);

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

// 当前选中服务商是否 PNVS:PNVS 模板由阿里云预置,表单需录入模板编码(SMS_xxx)
const isPnvsProvider = computed(() => {
  const p = providers.value.find((p) => p.id === formData.value.provider_id);
  return isPnvsDriver(p?.driver);
});

const isPnvsRow = (record: SmsTemplateApi.TemplateItem) => {
  const p = providers.value.find((p) => p.id === record.provider_id);
  return isPnvsDriver(p?.driver);
};

// 切换服务商时重新加载该服务商的签名;新建状态下顺带重置已选签名
watch(
  () => formData.value.provider_id,
  (pid) => {
    loadSigns(pid);
    if (!isEdit.value) {
      formData.value.sign_id = undefined;
    }
  },
);

// ------------------- 创建方式:手动 / 根据场景 -------------------

type CreateMode = 'manual' | 'scenes';

const DEFAULT_SCENE_CONTENT = '您的验证码是 ${code},5 分钟内有效,请勿泄露。';

const createMode = ref<CreateMode>('manual');

// 根据场景创建:场景列表与每个勾选场景的可编辑表单状态(独立 state,不混入 useFormModal)
const sceneList = ref<SmsSceneApi.SceneItem[]>([]);
const selectedSceneCodes = ref<string[]>([]);
interface SceneTemplateDraft {
  template_name: string;
  template_content: string;
}
const sceneTemplateDrafts = ref<Record<string, SceneTemplateDraft>>({});
const submittingScenes = ref(false);

const sceneCheckboxOptions = computed(() =>
  sceneList.value.map((s) => ({ label: s.scene_name, value: s.scene_code })),
);

const sceneNameOf = (code: string): string =>
  sceneList.value.find((s) => s.scene_code === code)?.scene_name || code;

// 手动创建表单内容里识别到的占位符(实时)
const manualPlaceholders = computed<string[]>(() =>
  extractPlaceholders(formData.value.template_content),
);

// 勾选/取消勾选场景时,同步初始化或移除对应的可编辑草稿
const handleSelectedScenesChange = (codes: (number | string)[]) => {
  const nextCodes = codes.map((c) => String(c));
  const nextDrafts: Record<string, SceneTemplateDraft> = {};
  nextCodes.forEach((code) => {
    nextDrafts[code] = sceneTemplateDrafts.value[code] || {
      template_name: sceneNameOf(code),
      template_content: DEFAULT_SCENE_CONTENT,
    };
  });
  selectedSceneCodes.value = nextCodes;
  sceneTemplateDrafts.value = nextDrafts;
};

const resetSceneCreateState = () => {
  createMode.value = 'manual';
  selectedSceneCodes.value = [];
  sceneTemplateDrafts.value = {};
};

// PNVS 不支持「根据场景创建」(无 AddSmsTemplate API),切换到 PNVS 时强制回落手动
watch(isPnvsProvider, (pnvs) => {
  if (pnvs && createMode.value === 'scenes') {
    createMode.value = 'manual';
  }
});

const handleCreate = async () => {
  if (providers.value.length === 0) await loadProviders();
  resetSceneCreateState();
  if (sceneList.value.length === 0) {
    try {
      sceneList.value = await getAllSmsSceneApi();
    } catch (error) {
      console.error('加载场景列表失败:', error);
    }
  }
  openCreateModal({
    provider_id: providers.value[0]?.id,
    sign_id: undefined,
    template_name: '',
    template_type: 0,
    template_code: '',
    template_content: DEFAULT_SCENE_CONTENT,
    remark: '验证码短信,用于下发动态验证码',
  });
};

const handleEdit = async (row: SmsTemplateApi.TemplateItem) => {
  createMode.value = 'manual';
  await openEditModal(row, getSmsTemplateInfoApi);
};

const handleCreateByScenes = async () => {
  const signId = formData.value.sign_id;
  if (!signId) {
    message.error('请选择关联签名');
    return;
  }
  if (selectedSceneCodes.value.length === 0) {
    message.error('请至少选择一个场景');
    return;
  }
  const items: SmsTemplateApi.CreateByScenesItem[] =
    selectedSceneCodes.value.map((code) => {
      const draft = sceneTemplateDrafts.value[code];
      return {
        scene_code: code,
        template_name: draft?.template_name || sceneNameOf(code),
        template_content: draft?.template_content || '',
        template_type: 0,
      };
    });
  submittingScenes.value = true;
  try {
    const result = await createSmsTemplateByScenesApi({
      provider_id: formData.value.provider_id,
      sign_id: signId,
      items,
    });
    if (result.created > 0) {
      message.info(
        `已创建 ${result.created} 个模板,正在后台批量提交阿里云;失败项请查看列表「审核备注」`,
      );
    }
    result.results
      .filter((r) => !r.success)
      .forEach((r) => {
        message.error(`${r.scene_name}: ${r.message}`);
      });
    modalVisible.value = false;
    loadData(searchParams.value);
  } finally {
    submittingScenes.value = false;
  }
};

const handleFormSubmit = async () => {
  if (createMode.value === 'scenes') {
    await handleCreateByScenes();
    return;
  }
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
  message.success('已加入后台同步队列,稍后刷新查看');
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
  const stat = await syncAllSmsTemplateApi(providerId);
  message.success(`已派发 ${stat.dispatched} 条同步任务`);
  loadData(searchParams.value);
};

// ------------------- 勾选批量同步 -------------------

const selectedRowKeys = ref<number[]>([]);
const rowSelection = computed(() => ({
  selectedRowKeys: selectedRowKeys.value,
  onChange: (keys: (number | string)[]) => {
    selectedRowKeys.value = keys.map((k) => Number(k));
  },
  // PNVS 模板由平台预置,无远端审核状态,不可同步 → 禁用勾选
  getCheckboxProps: (record: SmsTemplateApi.TemplateItem) => ({
    disabled: isPnvsRow(record),
  }),
}));

const handleSyncBatch = async () => {
  if (selectedRowKeys.value.length === 0) {
    message.warning('请先勾选要同步的模板');
    return;
  }
  const stat = await syncBatchSmsTemplateApi(selectedRowKeys.value);
  const extras: string[] = [];
  if (stat.invalid && stat.invalid > 0) extras.push(`非法 ${stat.invalid} 条`);
  if (stat.skipped && stat.skipped > 0)
    extras.push(`跳过 ${stat.skipped} 条(PNVS / 不存在)`);
  const suffix = extras.length > 0 ? `,${extras.join('、')}` : '';
  message.success(`已派发 ${stat.dispatched} 条同步任务${suffix}`);
  selectedRowKeys.value = [];
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

// ------------------- 导入已审核模板 -------------------

const importModalVisible = ref(false);
const importFormData = ref<SmsTemplateApi.ImportParams>({
  provider_id: 0,
  template_code: '',
});
const importing = ref(false);

const openImportModal = async () => {
  if (providers.value.length === 0) await loadProviders();
  if (!canImport.value) {
    message.warning('当前没有可导入模板的服务商(PNVS 不支持导入)');
    return;
  }
  importFormData.value = {
    provider_id: importableProviders.value[0]?.id || 0,
    template_code: '',
  };
  importModalVisible.value = true;
};

const handleImportSubmit = async () => {
  if (
    !importFormData.value.provider_id ||
    !importFormData.value.template_code
  ) {
    message.error('请完整填写服务商和模板编码');
    return;
  }
  importing.value = true;
  try {
    await importSmsTemplateApi(importFormData.value);
    message.success('导入成功');
    importModalVisible.value = false;
    loadData(searchParams.value);
  } finally {
    importing.value = false;
  }
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
  { title: '模板名称', dataIndex: 'template_name', width: 180 },
  { title: '模板编码', dataIndex: 'template_code', width: 180 },
  { title: '模板内容', dataIndex: 'template_content', width: 260, ellipsis: true },
  { title: '类型', dataIndex: 'template_type', width: 100 },
  { title: '审核状态', dataIndex: 'audit_status', width: 120 },
  { title: '审核备注', dataIndex: 'audit_reason', width: 320 },
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
      <a-tooltip
        title="普通模板会推送阿里云审核(通常 2 小时内出结果);PNVS 模板仅本地登记"
      >
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SmsTemplateCreate'"
        >
          新增模板
        </a-button>
      </a-tooltip>
      <a-tooltip title="已经在阿里云审核通过的模板编码(SMS_xxx)拉回本地,不触发新审核">
        <a-button
          class="ml-2"
          :disabled="!canImport"
          @click="openImportModal"
          v-access:code="'SmsTemplateImport'"
        >
          导入已审核模板
        </a-button>
      </a-tooltip>
      <a-tooltip
        title="把本地所有模板一次性向阿里云查最新审核状态并回写,适合提交后过段时间批量刷新"
      >
        <a-button
          class="ml-2"
          @click="handleSyncAll"
          v-access:code="'SmsTemplateSyncAll'"
        >
          批量同步状态
        </a-button>
      </a-tooltip>
      <a-tooltip title="同步当前勾选的模板状态(PNVS 模板不可勾选)">
        <a-button
          class="ml-2"
          :disabled="selectedRowKeys.length === 0"
          @click="handleSyncBatch"
          v-access:code="'SmsTemplateSyncBatch'"
        >
          批量同步选中{{
            selectedRowKeys.length > 0 ? `（${selectedRowKeys.length}）` : ''
          }}
        </a-button>
      </a-tooltip>
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
      :row-selection="rowSelection"
      :scroll="{ x: 1600 }"
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
        <template v-if="column.dataIndex === 'template_content'">
          <a-tooltip :title="record.template_content">
            <span class="text-xs">{{ record.template_content || '-' }}</span>
          </a-tooltip>
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
              v-if="!isPnvsRow(record)"
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
      width="720px"
      :confirm-loading="submittingScenes"
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
          v-if="!isPnvsProvider"
          label="关联签名"
          name="sign_id"
          :rules="[{ required: true, message: '请选择关联签名' }]"
        >
          <a-select
            v-model:value="formData.sign_id"
            placeholder="请选择已创建的短信签名"
            :options="signOptions"
            :not-found-content="
              formData.provider_id
                ? '该服务商下暂无签名,请先到「短信签名」创建并通过审核'
                : '请先选择服务商'
            "
          />
        </a-form-item>
        <a-form-item v-if="!isEdit" label="创建方式">
          <a-radio-group v-model:value="createMode">
            <a-radio value="manual">手动创建</a-radio>
            <a-radio v-if="!isPnvsProvider" value="scenes">
              根据场景创建
            </a-radio>
          </a-radio-group>
        </a-form-item>

        <!-- 手动创建 -->
        <template v-if="createMode === 'manual'">
          <a-alert
            v-if="isPnvsProvider"
            type="info"
            show-icon
            message="PNVS 模板为阿里云号码认证服务系统赠送,请到 PNVS 控制台「赠送模板配置」页面查看模板编码(SMS_xxx)后填入。模板内容仅本地保存用于绑定时的参数校验,实际发送内容由阿里云使用控制台预置版本。"
            class="mb-3"
          />
          <a-form-item
            label="模板名称"
            name="template_name"
            :rules="[{ required: true, message: '请输入模板名称' }]"
          >
            <a-input
              v-model:value="formData.template_name"
              :placeholder="
                isPnvsProvider
                  ? '本地备注名,例如「PNVS 登录验证」'
                  : '如：登录验证码'
              "
            />
          </a-form-item>
          <a-form-item
            v-if="isPnvsProvider"
            label="模板编码"
            name="template_code"
            :rules="[
              { required: true, message: '请输入 PNVS 控制台查到的模板编码' },
            ]"
          >
            <a-input
              v-model:value="formData.template_code"
              :disabled="isEdit"
              placeholder="PNVS 控制台「赠送模板配置」中的模板编码,例如 SMS_154950909"
            />
          </a-form-item>
          <a-form-item
            v-if="!isPnvsProvider"
            label="模板类型"
            name="template_type"
          >
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
              :placeholder="
                isPnvsProvider
                  ? '请抄录阿里云 PNVS 控制台「赠送模板配置」中显示的模板原文,系统据此校验场景参数（如 ${code}、${min}）'
                  : '使用 ${code} 作为验证码占位符'
              "
            />
          </a-form-item>
          <a-form-item label="识别到的占位符">
            <template v-if="manualPlaceholders.length > 0">
              <a-tag
                v-for="name in manualPlaceholders"
                :key="name"
                color="blue"
              >
                {{ '${' + name + '}' }}
              </a-tag>
            </template>
            <span v-else class="text-xs text-gray-500">无</span>
            <div class="mt-1 text-xs text-gray-500">
              从模板内容自动识别,可据此核对参数是否填写正确
            </div>
          </a-form-item>
          <a-form-item label="申请说明" name="remark">
            <a-textarea
              v-model:value="formData.remark"
              :rows="3"
              :placeholder="
                isPnvsProvider
                  ? '可选:本地备注,例如「PNVS 默认验证码模板」'
                  : '向阿里云说明使用场景,有助审核'
              "
            />
          </a-form-item>
        </template>

        <!-- 根据场景创建 -->
        <template v-else>
          <a-alert
            type="info"
            show-icon
            message="为选中的内置场景批量创建验证码模板,提交后会推送阿里云审核;此处只创建模板,不会自动绑定场景。"
            class="mb-3"
          />
          <a-form-item label="选择场景">
            <a-checkbox-group
              :value="selectedSceneCodes"
              :options="sceneCheckboxOptions"
              @change="handleSelectedScenesChange"
            />
            <div
              v-if="sceneList.length === 0"
              class="mt-1 text-xs text-gray-500"
            >
              暂无可用场景
            </div>
          </a-form-item>
          <a-form-item
            v-for="[code, draft] in Object.entries(sceneTemplateDrafts)"
            :key="code"
            :label="sceneNameOf(code)"
          >
            <a-input
              v-model:value="draft.template_name"
              class="mb-2"
              placeholder="模板名称"
            />
            <a-textarea
              v-model:value="draft.template_content"
              :rows="3"
              placeholder="模板内容,使用 ${code} 作为验证码占位符"
            />
          </a-form-item>
        </template>
      </a-form>
    </a-modal>

    <a-modal
      v-model:open="importModalVisible"
      title="从阿里云导入已审核模板"
      width="520px"
      :confirm-loading="importing"
      @ok="handleImportSubmit"
    >
      <a-alert
        type="info"
        show-icon
        message="只调用 QuerySmsTemplate 把阿里云上已审核通过的模板拉回本地,不会触发新审核。PNVS 服务商不支持导入。"
        class="mb-4"
      />
      <a-form :label-col="{ span: 6 }" :wrapper-col="{ span: 16 }">
        <a-form-item label="服务商" required>
          <a-select
            v-model:value="importFormData.provider_id"
            :options="
              importableProviders.map((p) => ({ label: p.name, value: p.id }))
            "
          />
        </a-form-item>
        <a-form-item label="模板编码" required>
          <a-input
            v-model:value="importFormData.template_code"
            placeholder="阿里云分配的 SMS_xxxxxxxxx"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
