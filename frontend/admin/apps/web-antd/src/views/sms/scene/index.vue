<script lang="ts" setup>
import type { SmsProviderApi } from '#/api/sms/provider';
import type { SmsSceneApi } from '#/api/sms/scene';
import type { SmsSignApi } from '#/api/sms/sign';
import type { SmsTemplateApi } from '#/api/sms/template';

import { computed, reactive, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

import { extractPlaceholders, SMS_AUDIT_STATUS } from '#/api/sms/constants';
import { getSmsProviderListApi } from '#/api/sms/provider';
import {
  bindSmsSceneApi,
  getSmsSceneListApi,
  saveSmsSceneDraftApi,
  unbindSmsSceneApi,
} from '#/api/sms/scene';
import { getSmsSignListApi } from '#/api/sms/sign';
import { getSmsTemplateListApi } from '#/api/sms/template';

defineOptions({ name: 'SmsScene' });

const { hasAccessByCodes } = useAccess();

interface SceneFormState {
  scene_code: string;
  provider_id?: number;
  template_id?: number;
  sign_id?: number;
  status: number;
  draft_template_name: string;
  draft_template_content: string;
  draft_template_type: number;
  draft_template_remark: string;
}

const loading = ref(false);
const submitting = ref(false);
const tableData = ref<SmsSceneApi.SceneItem[]>([]);

const providers = ref<SmsProviderApi.ProviderItem[]>([]);
const signs = ref<SmsSignApi.SignItem[]>([]);
const templates = ref<SmsTemplateApi.TemplateItem[]>([]);

const modalVisible = ref(false);
const editingScene = ref<null | SmsSceneApi.SceneItem>(null);

const searchParams = ref<SmsSceneApi.ListParams>({
  keyword: '',
  provider_id: undefined,
  status: undefined,
});
const pagination = reactive({
  current: 1,
  pageSize: 10,
  showSizeChanger: true,
  showTotal: (total: number) => `共 ${total} 条`,
  total: 0,
});

const statusOptions = [
  { label: '启用', value: 1 },
  { label: '禁用', value: 0 },
];

const templateTypeOptions = [
  { label: '验证码', value: 0 },
  { label: '通知', value: 1 },
  { label: '推广', value: 2 },
  { label: '国际/港澳台', value: 3 },
];

const auditStatusMeta: Record<string, { color: string; label: string }> = {
  [SMS_AUDIT_STATUS.LOCAL_ONLY]: { color: 'default', label: '仅本地' },
  [SMS_AUDIT_STATUS.PASSED]: { color: 'green', label: '已通过' },
  [SMS_AUDIT_STATUS.PENDING]: { color: 'gold', label: '审核中' },
  [SMS_AUDIT_STATUS.REJECTED]: { color: 'red', label: '已驳回' },
  [SMS_AUDIT_STATUS.SUBMITTING]: { color: 'processing', label: '提交中' },
};

const formData = ref<SceneFormState>({
  scene_code: '',
  provider_id: undefined,
  template_id: undefined,
  sign_id: undefined,
  status: 1,
  draft_template_name: '',
  draft_template_content: '',
  draft_template_type: 0,
  draft_template_remark: '',
});

const providerOptions = computed(() =>
  providers.value.map((p) => ({ label: p.name, value: p.id })),
);

const filteredTemplates = computed(() =>
  templates.value.filter((t) => t.provider_id === formData.value.provider_id),
);

const filteredSigns = computed(() =>
  signs.value.filter((s) => s.provider_id === formData.value.provider_id),
);

const sceneAvailableParams = computed<string[]>(() => {
  const params = editingScene.value?.available_params;
  return params && params.length > 0 ? params : ['code'];
});

const draftPlaceholders = computed<string[]>(() =>
  extractPlaceholders(formData.value.draft_template_content),
);

const getTemplatePlaceholders = (
  template: SmsTemplateApi.TemplateItem,
): string[] => {
  return template.placeholders && template.placeholders.length > 0
    ? template.placeholders
    : extractPlaceholders(template.template_content);
};

const formatPlaceholder = (name: string) => `$${`{${name}}`}`;

const auditLabel = (status?: null | string): string => {
  if (!status) return '未知';
  return auditStatusMeta[status]?.label || status;
};

const auditColor = (status?: null | string): string => {
  if (!status) return 'default';
  return auditStatusMeta[status]?.color || 'default';
};

const getUnsupportedPlaceholders = (
  template: SmsTemplateApi.TemplateItem,
): string[] => {
  const available = sceneAvailableParams.value;
  return getTemplatePlaceholders(template).filter(
    (name) => !available.includes(name),
  );
};

const templateOptions = computed(() =>
  filteredTemplates.value.map((template) => {
    const unsupported = getUnsupportedPlaceholders(template);
    const missingCode = !template.template_code;
    const status = auditLabel(template.audit_status);
    const code = template.template_code || '未填编码';
    const baseLabel = `${template.template_name} (${code},${status})`;

    if (missingCode) {
      return {
        disabled: true,
        label: `${baseLabel} - 需先填写模板编码`,
        value: template.id,
      };
    }

    if (unsupported.length === 0) {
      return { disabled: false, label: baseLabel, value: template.id };
    }

    const reason = unsupported
      .map((name) => formatPlaceholder(name))
      .join('、');
    return {
      disabled: true,
      label: `${baseLabel} - 含场景不支持的变量 ${reason}`,
      value: template.id,
    };
  }),
);

const signOptions = computed(() =>
  filteredSigns.value.map((sign) => ({
    label: sign.sign_name,
    value: sign.id,
  })),
);

const selectedTemplate = computed(() =>
  templates.value.find(
    (template) => template.id === formData.value.template_id,
  ),
);

const loadAll = async () => {
  loading.value = true;
  try {
    const [sceneResult, providerList, signList, templateList] =
      await Promise.all([
        getSmsSceneListApi({
          ...searchParams.value,
          limit: pagination.pageSize,
          page: pagination.current,
        }),
        getSmsProviderListApi({ page: 1, limit: 100 }),
        getSmsSignListApi({ page: 1, limit: 200 }),
        getSmsTemplateListApi({ page: 1, limit: 200 }),
      ]);
    tableData.value = sceneResult.list;
    pagination.total = sceneResult.total;
    providers.value = providerList.list;
    signs.value = signList.list;
    templates.value = templateList.list;
  } finally {
    loading.value = false;
  }
};

const handleSearch = () => {
  pagination.current = 1;
  loadAll();
};

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    provider_id: undefined,
    status: undefined,
  };
  pagination.current = 1;
  loadAll();
};

const handleTableChange = (newPagination: {
  current?: number;
  pageSize?: number;
}) => {
  pagination.current = newPagination.current || 1;
  pagination.pageSize = newPagination.pageSize || pagination.pageSize;
  loadAll();
};

const openBindModal = (row: SmsSceneApi.SceneItem) => {
  editingScene.value = row;
  formData.value = {
    scene_code: row.scene_code,
    provider_id: row.provider_id || undefined,
    template_id: row.template_id || undefined,
    sign_id: row.sign_id || undefined,
    status: row.status ?? 1,
    draft_template_name: row.draft_template_name,
    draft_template_content: row.draft_template_content,
    draft_template_type: row.draft_template_type,
    draft_template_remark: row.draft_template_remark,
  };

  if (formData.value.template_id) {
    const bound = templates.value.find(
      (template) => template.id === formData.value.template_id,
    );
    if (bound && getUnsupportedPlaceholders(bound).length > 0) {
      formData.value.template_id = undefined;
      message.warning('原绑定模板与当前场景变量不兼容,请重新选择模板');
    }
  }
  modalVisible.value = true;
};

const handleProviderChange = () => {
  formData.value.template_id = undefined;
  formData.value.sign_id = undefined;
};

const assertDraftForm = (): boolean => {
  if (!formData.value.draft_template_name?.trim()) {
    message.error('请输入场景模板名称');
    return false;
  }
  if (!formData.value.draft_template_content?.trim()) {
    message.error('请输入场景模板内容');
    return false;
  }
  return true;
};

const handleSaveDraft = async () => {
  if (!assertDraftForm()) return;

  submitting.value = true;
  try {
    await saveSmsSceneDraftApi({
      scene_code: formData.value.scene_code,
      draft_template_name: formData.value.draft_template_name,
      draft_template_content: formData.value.draft_template_content,
      draft_template_type: formData.value.draft_template_type,
      draft_template_remark: formData.value.draft_template_remark,
    });
    message.success('草稿已保存');
    modalVisible.value = false;
    loadAll();
  } finally {
    submitting.value = false;
  }
};

const handleBind = async () => {
  if (!assertDraftForm()) return;
  if (!formData.value.provider_id) {
    message.error('请选择服务商');
    return;
  }
  if (!formData.value.sign_id) {
    message.error('请选择签名');
    return;
  }
  if (!formData.value.template_id) {
    message.error('请选择已有模板');
    return;
  }

  submitting.value = true;
  try {
    await bindSmsSceneApi({
      scene_code: formData.value.scene_code,
      provider_id: formData.value.provider_id,
      template_id: formData.value.template_id,
      sign_id: formData.value.sign_id,
      status: formData.value.status,
      draft_template_name: formData.value.draft_template_name,
      draft_template_content: formData.value.draft_template_content,
      draft_template_type: formData.value.draft_template_type,
      draft_template_remark: formData.value.draft_template_remark,
    });
    message.success('绑定成功');
    modalVisible.value = false;
    loadAll();
  } finally {
    submitting.value = false;
  }
};

const handleUnbind = async (row: SmsSceneApi.SceneItem) => {
  await unbindSmsSceneApi(row.scene_code);
  message.success('已取消绑定');
  loadAll();
};

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '场景', dataIndex: 'scene_name', width: 160 },
  { title: '场景编码', dataIndex: 'scene_code', width: 190 },
  { title: '服务商', dataIndex: 'provider_name', width: 150 },
  { title: '绑定模板', dataIndex: 'template_name', width: 210 },
  { title: '模板编码', dataIndex: 'template_code', width: 180 },
  { title: '签名', dataIndex: 'sign_name', width: 160 },
  {
    title: '场景模板草稿',
    dataIndex: 'draft_template_content',
    ellipsis: true,
    width: 300,
  },
  { title: '状态', dataIndex: 'status', width: 100 },
  { title: '更新时间', dataIndex: 'update_time', width: 180 },
  { title: '操作', key: 'action', width: 210 },
];

if (hasAccessByCodes(['SmsSceneList'])) {
  loadAll();
}
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-alert
        type="info"
        show-icon
        message="场景页用于维护每个业务场景的模板草稿,并绑定已有模板与签名。创建模板请到模板管理中操作。"
      />
    </div>

    <a-form layout="inline" class="mb-4" v-access:code="'SmsSceneList'">
      <a-form-item label="服务商">
        <a-select
          v-model:value="searchParams.provider_id"
          placeholder="全部"
          allow-clear
          :options="providerOptions"
          style="width: 180px"
        />
      </a-form-item>
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="场景/编码/模板/签名/草稿"
          allow-clear
          style="width: 240px"
          @press-enter="handleSearch"
        />
      </a-form-item>
      <a-form-item label="状态">
        <a-select
          v-model:value="searchParams.status"
          placeholder="全部"
          allow-clear
          :options="statusOptions"
          style="width: 120px"
        />
      </a-form-item>
      <a-form-item>
        <a-button type="primary" @click="handleSearch">搜索</a-button>
        <a-button class="ml-2" @click="resetSearch">重置</a-button>
      </a-form-item>
    </a-form>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1900 }"
      row-key="scene_code"
      @change="handleTableChange"
      v-access:code="'SmsSceneList'"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.dataIndex === 'id'">
          <span>{{ record.id || '-' }}</span>
        </template>
        <template v-if="column.dataIndex === 'provider_name'">
          <span v-if="record.provider_name">{{ record.provider_name }}</span>
          <a-tag v-else color="default">未绑定</a-tag>
        </template>
        <template v-if="column.dataIndex === 'template_name'">
          <template v-if="record.template_name">
            <span>{{ record.template_name }}</span>
            <a-tag
              class="ml-1"
              :color="auditColor(record.template_audit_status)"
            >
              {{ auditLabel(record.template_audit_status) }}
            </a-tag>
          </template>
          <a-tag v-else color="default">未绑定</a-tag>
        </template>
        <template v-if="column.dataIndex === 'template_code'">
          <span v-if="record.template_code">{{ record.template_code }}</span>
          <a-tag v-else color="default">未填写</a-tag>
        </template>
        <template v-if="column.dataIndex === 'sign_name'">
          <span v-if="record.sign_name">{{ record.sign_name }}</span>
          <a-tag v-else color="default">未绑定</a-tag>
        </template>
        <template v-if="column.dataIndex === 'draft_template_content'">
          <a-tooltip :title="record.draft_template_content">
            <span class="text-xs">
              {{ record.draft_template_content || '-' }}
            </span>
          </a-tooltip>
        </template>
        <template v-if="column.dataIndex === 'status'">
          <a-tag :color="record.status === 1 ? 'green' : 'default'">
            {{
              record.status === 1
                ? '启用'
                : record.status === 0
                  ? '禁用'
                  : '未绑定'
            }}
          </a-tag>
        </template>
        <template v-if="column.key === 'action'">
          <a-space>
            <a-button
              type="link"
              size="small"
              @click="openBindModal(record)"
              v-access:code="'SmsSceneBind'"
            >
              {{ record.provider_id ? '配置' : '配置场景' }}
            </a-button>
            <a-button
              v-if="record.provider_id"
              type="link"
              danger
              size="small"
              @click="handleUnbind(record)"
              v-access:code="'SmsSceneUnbind'"
            >
              取消绑定
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>

    <a-modal
      v-model:open="modalVisible"
      :title="`配置场景:${editingScene?.scene_name || ''}`"
      width="760px"
      :confirm-loading="submitting"
    >
      <a-form
        class="pt-4"
        :label-col="{ style: { width: '112px' } }"
        :wrapper-col="{ flex: 1 }"
      >
        <a-form-item label="场景">
          <a-input :value="editingScene?.scene_name" disabled />
        </a-form-item>
        <a-form-item label="服务商" required>
          <a-select
            v-model:value="formData.provider_id"
            placeholder="请选择服务商"
            allow-clear
            :options="providerOptions"
            @change="handleProviderChange"
          />
        </a-form-item>
        <a-form-item label="签名" required>
          <a-select
            v-model:value="formData.sign_id"
            placeholder="请选择签名"
            allow-clear
            :options="signOptions"
            :disabled="!formData.provider_id"
            :not-found-content="
              formData.provider_id ? '当前服务商下暂无签名' : '请先选择服务商'
            "
          />
        </a-form-item>
        <a-form-item label="状态">
          <a-radio-group v-model:value="formData.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="草稿名称" required>
          <a-input
            v-model:value="formData.draft_template_name"
            placeholder="如:登录验证码"
          />
        </a-form-item>
        <a-form-item label="模板类型">
          <a-select
            v-model:value="formData.draft_template_type"
            :options="templateTypeOptions"
          />
        </a-form-item>
        <a-form-item label="草稿内容" required>
          <a-textarea
            v-model:value="formData.draft_template_content"
            :rows="4"
            placeholder="使用 ${code} 变量"
          />
          <div class="mt-2">
            <span class="mr-2 text-xs text-gray-500">可用变量</span>
            <a-tag
              v-for="name in sceneAvailableParams"
              :key="name"
              color="blue"
            >
              {{ formatPlaceholder(name) }}
            </a-tag>
          </div>
          <div v-if="draftPlaceholders.length > 0" class="mt-1">
            <span class="mr-2 text-xs text-gray-500">已识别</span>
            <a-tag v-for="name in draftPlaceholders" :key="name">
              {{ formatPlaceholder(name) }}
            </a-tag>
          </div>
        </a-form-item>
        <a-form-item label="申请说明">
          <a-textarea
            v-model:value="formData.draft_template_remark"
            :rows="2"
            placeholder="提交阿里云时作为模板申请说明"
          />
        </a-form-item>
        <a-form-item label="已有模板" required>
          <a-select
            v-model:value="formData.template_id"
            placeholder="请选择模板"
            allow-clear
            :options="templateOptions"
            :disabled="!formData.provider_id || filteredTemplates.length === 0"
            :not-found-content="
              formData.provider_id ? '当前服务商下暂无模板' : '请先选择服务商'
            "
          />
          <div class="mt-1 text-xs text-gray-500">
            模板必须有平台模板编码,且变量必须包含在当前场景可用变量内。
          </div>
          <a-alert
            v-if="
              selectedTemplate &&
              selectedTemplate.audit_status !== SMS_AUDIT_STATUS.PASSED
            "
            type="warning"
            show-icon
            class="mt-2"
            :message="`当前模板状态为${auditLabel(selectedTemplate.audit_status)},可以绑定,发送结果以平台返回为准。`"
          />
        </a-form-item>
      </a-form>
      <template #footer>
        <a-button @click="modalVisible = false">取消</a-button>
        <a-button
          :loading="submitting"
          @click="handleSaveDraft"
          v-access:code="'SmsSceneBind'"
        >
          保存草稿
        </a-button>
        <a-button
          type="primary"
          :loading="submitting"
          @click="handleBind"
          v-access:code="'SmsSceneBind'"
        >
          绑定模板
        </a-button>
      </template>
    </a-modal>
  </div>
</template>
