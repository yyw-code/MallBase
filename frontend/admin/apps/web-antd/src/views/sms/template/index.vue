<script lang="ts" setup>
import type { SmsProviderApi } from '#/api/sms/provider';
import type { SmsSceneApi } from '#/api/sms/scene';
import type { SmsSignApi } from '#/api/sms/sign';
import type { SmsTemplateApi } from '#/api/sms/template';

import { computed, ref, watch } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

import { extractPlaceholders } from '#/api/sms/constants';
import { getSmsProviderListApi } from '#/api/sms/provider';
import { getAllSmsSceneApi } from '#/api/sms/scene';
import { getSmsSignListApi } from '#/api/sms/sign';
import {
  createSmsTemplateApi,
  createSmsTemplateByScenesApi,
  deleteSmsTemplateApi,
  getSmsTemplateInfoApi,
  getSmsTemplateListApi,
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

const CODE_PLACEHOLDER = '$' + '{code}';
const DEFAULT_TEMPLATE_CONTENT = `您的验证码是 ${CODE_PLACEHOLDER},5 分钟内有效,请勿泄露。`;

type CreateMode = 'manual' | 'scenes';

interface SceneTemplateDraft {
  remark: string;
  template_code: string;
  template_content: string;
  template_name: string;
  template_type: number;
}

const providers = ref<SmsProviderApi.ProviderItem[]>([]);
const signs = ref<SmsSignApi.SignItem[]>([]);

const providerOptions = computed(() =>
  providers.value.map((p) => ({ label: p.name, value: p.id })),
);

const signOptions = computed(() =>
  signs.value.map((s) => ({
    label: s.sign_name,
    value: s.id,
  })),
);

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
const createMode = ref<CreateMode>('manual');
const sceneList = ref<SmsSceneApi.SceneItem[]>([]);
const selectedSceneCodes = ref<string[]>([]);
const sceneTemplateDrafts = ref<Record<string, SceneTemplateDraft>>({});
const submittingScenes = ref(false);

const loadProviders = async () => {
  const res = await getSmsProviderListApi({ page: 1, limit: 100 });
  providers.value = res.list;
};

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

watch(
  () => formData.value.provider_id,
  (providerId) => {
    loadSigns(providerId);
    if (!isEdit.value) {
      formData.value.sign_id = undefined;
    }
  },
);

watch(createMode, (mode) => {
  if (mode === 'scenes') {
    formData.value.submit_to_platform = 1;
  }
});

const formatPlaceholder = (name: string) => `$${`{${name}}`}`;

const templatePlaceholders = computed<string[]>(() =>
  extractPlaceholders(formData.value.template_content),
);

const sceneNameOf = (code: string): string =>
  sceneList.value.find((scene) => scene.scene_code === code)?.scene_name ||
  code;

const sceneOf = (code: string): SmsSceneApi.SceneItem | undefined =>
  sceneList.value.find((scene) => scene.scene_code === code);

const resetSceneCreateState = () => {
  createMode.value = 'manual';
  selectedSceneCodes.value = [];
  sceneTemplateDrafts.value = {};
};

const handleSelectedScenesChange = (codes: (number | string)[]) => {
  const nextCodes = codes.map(String);
  const nextDrafts: Record<string, SceneTemplateDraft> = {};
  nextCodes.forEach((code) => {
    const scene = sceneOf(code);
    nextDrafts[code] = sceneTemplateDrafts.value[code] || {
      remark: scene?.draft_template_remark || '',
      template_code: '',
      template_content: scene?.draft_template_content || '',
      template_name: scene?.draft_template_name || '',
      template_type: scene?.draft_template_type ?? 0,
    };
  });
  selectedSceneCodes.value = nextCodes;
  sceneTemplateDrafts.value = nextDrafts;
};

const isSceneSelected = (code: string) =>
  selectedSceneCodes.value.includes(code);

const handleSceneCheckedChange = (code: string, checked: boolean) => {
  const current = new Set(selectedSceneCodes.value);
  if (checked) {
    current.add(code);
  } else {
    current.delete(code);
  }
  handleSelectedScenesChange(
    sceneList.value
      .map((scene) => scene.scene_code)
      .filter((sceneCode) => current.has(sceneCode)),
  );
};

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
    provider_id:
      providers.value.length === 1 ? providers.value[0]?.id : undefined,
    sign_id: undefined,
    submit_to_platform: 0,
    template_name: '',
    template_type: 0,
    template_code: '',
    template_content: DEFAULT_TEMPLATE_CONTENT,
    remark: '验证码短信,用于下发动态验证码',
  });
};

const handleEdit = async (row: SmsTemplateApi.TemplateItem) => {
  createMode.value = 'manual';
  await openEditModal(row, getSmsTemplateInfoApi);
  formData.value.submit_to_platform =
    formData.value.audit_status === 'local_only' ? 0 : 1;
  formData.value.template_code = formData.value.template_code || '';
};

const handleCreateByScenes = async () => {
  if (!formData.value.provider_id) {
    message.error('请选择服务商');
    return;
  }
  if (!formData.value.sign_id) {
    message.error('请选择关联签名');
    return;
  }
  if (selectedSceneCodes.value.length === 0) {
    message.error('请至少选择一个场景');
    return;
  }

  const submitToPlatform = (formData.value.submit_to_platform ?? 0) as 0 | 1;
  if (submitToPlatform === 0) {
    const missing = selectedSceneCodes.value
      .map((code) => sceneTemplateDrafts.value[code])
      .some((draft) => !draft?.template_code?.trim());
    if (missing) {
      message.error('本地登记模式下每个场景都需要填写模板编码');
      return;
    }
  }

  const items: SmsTemplateApi.CreateByScenesItem[] =
    selectedSceneCodes.value.map((code) => {
      const draft = sceneTemplateDrafts.value[code];
      return {
        scene_code: code,
        template_code: draft?.template_code?.trim() || '',
        template_content: draft?.template_content || '',
        template_name: draft?.template_name || sceneNameOf(code),
        template_type: draft?.template_type ?? 0,
        remark: draft?.remark || '',
      };
    });

  submittingScenes.value = true;
  try {
    const result = await createSmsTemplateByScenesApi({
      provider_id: formData.value.provider_id,
      sign_id: formData.value.sign_id,
      submit_to_platform: submitToPlatform,
      items,
    });
    if (result.created > 0) {
      message.success(`已创建 ${result.created} 个模板`);
    }
    result.results
      .filter((item) => !item.success)
      .forEach((item) => {
        message.error(`${item.scene_name}: ${item.message}`);
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

  await formRef.value?.validate?.();

  if (
    formData.value.submit_to_platform === 0 &&
    !String(formData.value.template_code || '').trim()
  ) {
    message.error('本地登记模式需填写模板编码');
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

const handleTableChange = (newPagination: {
  current?: number;
  pageSize?: number;
}) => {
  pagination.current = newPagination.current || 1;
  pagination.pageSize = newPagination.pageSize || pagination.pageSize;
  loadData(searchParams.value);
};

const handleSync = async (row: SmsTemplateApi.TemplateItem) => {
  await syncSmsTemplateStatusApi(row.id);
  message.success('已加入后台同步队列,稍后刷新查看');
  loadData(searchParams.value);
};

const handleSyncAll = async () => {
  let providerId = searchParams.value.provider_id;
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

const selectedRowKeys = ref<number[]>([]);
const rowSelection = computed(() => ({
  selectedRowKeys: selectedRowKeys.value,
  onChange: (keys: (number | string)[]) => {
    selectedRowKeys.value = keys.map(Number);
  },
}));

const handleSyncBatch = async () => {
  if (selectedRowKeys.value.length === 0) {
    message.warning('请先勾选要同步的模板');
    return;
  }
  const stat = await syncBatchSmsTemplateApi(selectedRowKeys.value);
  const extras: string[] = [];
  if (stat.invalid && stat.invalid > 0) extras.push(`非法 ${stat.invalid} 条`);
  if (stat.skipped && stat.skipped > 0) extras.push(`跳过 ${stat.skipped} 条`);
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

const auditStatusTag = (status: string) =>
  auditStatusOptions.find((o) => o.value === status);

const providerName = (id: number) =>
  providers.value.find((p) => p.id === id)?.name || `#${id}`;

const templateTypeLabel = (type: number) =>
  templateTypeOptions.find((o) => o.value === type)?.label || `类型 ${type}`;

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '服务商', dataIndex: 'provider_id', width: 160 },
  { title: '模板名称', dataIndex: 'template_name', width: 180 },
  { title: '模板编码', dataIndex: 'template_code', width: 180 },
  {
    title: '模板内容',
    dataIndex: 'template_content',
    ellipsis: true,
    width: 280,
  },
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
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">短信模板</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-tooltip title="新增本地模板记录,可选择仅本地登记或提交阿里云">
          <a-button
            type="primary"
            @click="handleCreate"
            v-access:code="'SmsTemplateCreate'"
          >
            新增模板
          </a-button>
        </a-tooltip>
        <a-tooltip title="查询阿里云最新审核状态并回写本地">
          <a-button @click="handleSyncAll" v-access:code="'SmsTemplateSyncAll'">
            批量同步状态
          </a-button>
        </a-tooltip>
        <a-tooltip title="同步当前勾选的模板状态">
          <a-button
            :disabled="selectedRowKeys.length === 0"
            @click="handleSyncBatch"
            v-access:code="'SmsTemplateSyncBatch'"
          >
            批量同步选中{{
              selectedRowKeys.length > 0 ? `（${selectedRowKeys.length}）` : ''
            }}
          </a-button>
        </a-tooltip>
        <a-button @click="refresh" v-access:code="'SmsTemplateList'">
          刷新
        </a-button>
      </div>
    </div>

    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
        v-access:code="'SmsTemplateList'"
      >
        <a-form-item label="服务商" class="mb-0">
          <a-select
            v-model:value="searchParams.provider_id"
            placeholder="全部"
            allow-clear
            :options="providerOptions"
            class="w-full"
          />
        </a-form-item>
        <a-form-item label="关键词" class="mb-0">
          <a-input
            v-model:value="searchParams.keyword"
            placeholder="模板名称/编码"
            allow-clear
            class="w-full"
          />
        </a-form-item>
        <a-form-item label="审核" class="mb-0">
          <a-select
            class="w-full"
            v-model:value="searchParams.audit_status"
            placeholder="全部"
            allow-clear
            :options="
              auditStatusOptions.map((o) => ({
                label: o.label,
                value: o.value,
              }))
            "
          />
        </a-form-item>
        <a-form-item class="mb-0 md:col-span-3 xl:col-span-6">
          <div class="flex justify-end gap-2">
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
            <a-button @click="resetSearch">重置</a-button>
          </div>
        </a-form-item>
      </a-form>
    </div>

    <div class="overflow-hidden rounded-lg border bg-[hsl(var(--card))]">
      <a-table
        :columns="columns"
        :data-source="tableData"
        :loading="loading"
        :pagination="pagination"
        :row-selection="rowSelection"
        :scroll="{ x: 1660 }"
        row-key="id"
        @change="handleTableChange"
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
              {{
                auditStatusTag(record.audit_status)?.label ||
                record.audit_status
              }}
            </a-tag>
          </template>
          <template v-if="column.dataIndex === 'template_code'">
            <span v-if="record.template_code">{{ record.template_code }}</span>
            <a-tag v-else color="default">未填写</a-tag>
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
    </div>

    <a-drawer
      v-model:open="modalVisible"
      :title="modalTitle"
      width="720px"
      :body-style="{ overflow: 'hidden', padding: 0 }"
      destroy-on-close
    >
      <div class="sms-template-drawer">
        <div class="sms-template-drawer__body">
          <a-form
            ref="formRef"
            :model="formData"
            :label-col="{ style: { width: '108px' } }"
            :wrapper-col="{ flex: 1 }"
          >
            <a-form-item
              label="服务商"
              name="provider_id"
              :rules="[{ required: true, message: '请选择服务商' }]"
            >
              <a-select
                v-model:value="formData.provider_id"
                :disabled="isEdit"
                :options="providerOptions"
              />
            </a-form-item>
            <a-form-item
              label="关联签名"
              name="sign_id"
              :rules="[{ required: true, message: '请选择关联签名' }]"
            >
              <a-select
                v-model:value="formData.sign_id"
                placeholder="请选择签名"
                :options="signOptions"
                :not-found-content="
                  formData.provider_id
                    ? '该服务商下暂无签名,请先创建或导入签名'
                    : '请先选择服务商'
                "
              />
            </a-form-item>
            <a-form-item v-if="!isEdit" label="创建方式">
              <a-radio-group v-model:value="createMode">
                <a-radio-button value="manual">手动创建</a-radio-button>
                <a-radio-button value="scenes">根据场景创建</a-radio-button>
              </a-radio-group>
            </a-form-item>
            <a-form-item label="保存方式">
              <a-radio-group v-model:value="formData.submit_to_platform">
                <a-radio-button :value="0">本地登记</a-radio-button>
                <a-radio-button :value="1">提交阿里云</a-radio-button>
              </a-radio-group>
            </a-form-item>

            <template v-if="createMode === 'manual'">
              <a-form-item
                v-if="formData.submit_to_platform === 0"
                label="模板编码"
                name="template_code"
                :rules="[{ required: true, message: '请输入模板编码' }]"
              >
                <a-input
                  v-model:value="formData.template_code"
                  placeholder="阿里云平台模板编码,如 SMS_xxxxxxxxx"
                />
              </a-form-item>
              <a-form-item
                v-else-if="formData.template_code"
                label="当前编码"
                name="template_code"
              >
                <a-input v-model:value="formData.template_code" disabled />
              </a-form-item>
              <a-form-item
                label="模板名称"
                name="template_name"
                :rules="[{ required: true, message: '请输入模板名称' }]"
              >
                <a-input
                  v-model:value="formData.template_name"
                  placeholder="如:登录验证码"
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
                  placeholder="使用 ${code} 作为验证码变量"
                />
              </a-form-item>
              <a-form-item label="识别变量">
                <template v-if="templatePlaceholders.length > 0">
                  <a-tag
                    v-for="name in templatePlaceholders"
                    :key="name"
                    color="blue"
                  >
                    {{ formatPlaceholder(name) }}
                  </a-tag>
                </template>
                <span v-else class="text-xs text-gray-500">无</span>
              </a-form-item>
              <a-form-item label="申请说明" name="remark">
                <a-textarea
                  v-model:value="formData.remark"
                  :rows="3"
                  placeholder="提交阿里云时作为模板申请说明"
                />
              </a-form-item>
            </template>

            <template v-else>
              <a-alert
                type="info"
                show-icon
                message="按场景草稿批量创建模板。模板创建完成后,仍需要到场景页选择并绑定实际使用的模板。"
                class="mb-3"
              />
              <a-form-item label="选择场景">
                <div class="sms-scene-create-list">
                  <div
                    v-for="scene in sceneList"
                    :key="scene.scene_code"
                    class="sms-scene-create-list__item"
                  >
                    <a-checkbox
                      :checked="isSceneSelected(scene.scene_code)"
                      @change="
                        (event) =>
                          handleSceneCheckedChange(
                            scene.scene_code,
                            event.target.checked,
                          )
                      "
                    >
                      {{ scene.scene_name }}
                    </a-checkbox>
                    <div
                      v-if="isSceneSelected(scene.scene_code)"
                      class="sms-scene-create-list__preview"
                    >
                      <a-input
                        v-if="formData.submit_to_platform === 0"
                        v-model:value="
                          sceneTemplateDrafts[scene.scene_code].template_code
                        "
                        class="mb-2"
                        placeholder="本地登记模板编码,如 SMS_xxxxxxxxx"
                      />
                      <div class="sms-scene-create-list__label">模板内容</div>
                      <div class="sms-scene-create-list__text">
                        {{
                          sceneTemplateDrafts[scene.scene_code].template_content
                        }}
                      </div>
                      <div class="sms-scene-create-list__label">申请说明</div>
                      <div class="sms-scene-create-list__text">
                        {{ sceneTemplateDrafts[scene.scene_code].remark }}
                      </div>
                    </div>
                  </div>
                </div>
                <div
                  v-if="sceneList.length === 0"
                  class="mt-1 text-xs text-gray-500"
                >
                  暂无可用场景
                </div>
              </a-form-item>
            </template>
          </a-form>
        </div>
        <div class="sms-template-drawer__footer">
          <a-button @click="modalVisible = false">取消</a-button>
          <a-button
            type="primary"
            class="ml-2"
            :loading="submittingScenes"
            @click="handleFormSubmit"
          >
            确定
          </a-button>
        </div>
      </div>
    </a-drawer>
  </div>
</template>

<style scoped>
.sms-template-drawer {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.sms-template-drawer__body {
  flex: 1;
  min-height: 0;
  padding: 24px 24px 8px;
  overflow-y: auto;
}

.sms-template-drawer__footer {
  flex: 0 0 auto;
  padding: 12px 24px;
  text-align: right;
  background: var(--ant-color-bg-container, #fff);
  border-top: 1px solid var(--ant-color-border-secondary, #f0f0f0);
}

.sms-scene-create-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.sms-scene-create-list__item {
  padding: 10px 12px;
  border: 1px solid hsl(var(--border));
  border-radius: 6px;
}

.sms-scene-create-list__preview {
  margin-top: 8px;
  padding-left: 24px;
}

.sms-scene-create-list__label {
  margin-bottom: 4px;
  font-size: 12px;
  line-height: 20px;
  color: hsl(var(--muted-foreground));
}

.sms-scene-create-list__text {
  padding: 8px 10px;
  margin-bottom: 8px;
  font-size: 13px;
  line-height: 20px;
  color: hsl(var(--foreground));
  white-space: pre-wrap;
  background: hsl(var(--muted));
  border-radius: 6px;
}
</style>
