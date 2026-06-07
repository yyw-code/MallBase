<script lang="ts" setup>
import type { SmsSceneApi } from '#/api/sms/scene';
import type { SmsProviderApi } from '#/api/sms/provider';
import type { SmsSignApi } from '#/api/sms/sign';
import type { SmsTemplateApi } from '#/api/sms/template';

import { computed, reactive, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

import {
  extractPlaceholders,
  isPnvsDriver,
  SMS_AUDIT_STATUS,
} from '#/api/sms/constants';
import { getSmsProviderListApi } from '#/api/sms/provider';
import {
  bindSmsSceneApi,
  getSmsSceneListApi,
  unbindSmsSceneApi,
} from '#/api/sms/scene';
import { getSmsSignListApi } from '#/api/sms/sign';
import { getSmsTemplateListApi } from '#/api/sms/template';

defineOptions({ name: 'SmsScene' });

const { hasAccessByCodes } = useAccess();

const loading = ref(false);
const tableData = ref<SmsSceneApi.SceneItem[]>([]);

const providers = ref<SmsProviderApi.ProviderItem[]>([]);
const signs = ref<SmsSignApi.SignItem[]>([]);
const templates = ref<SmsTemplateApi.TemplateItem[]>([]);

const modalVisible = ref(false);
const editingScene = ref<SmsSceneApi.SceneItem | null>(null);
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
const formData = ref<SmsSceneApi.BindParams>({
  scene_code: '',
  provider_id: 0,
  template_id: 0,
  sign_id: 0,
  status: 1,
});

const selectedProvider = computed(() =>
  providers.value.find((p) => p.id === formData.value.provider_id),
);

const isPnvs = computed(() => isPnvsDriver(selectedProvider.value?.driver));

// PNVS 接受 PASSED 或 LOCAL_ONLY(系统赠送签名/模板本地登记后即为 LOCAL_ONLY);
// 普通驱动仅接受 PASSED
const allowedStatuses = computed(() =>
  isPnvs.value
    ? [SMS_AUDIT_STATUS.PASSED, SMS_AUDIT_STATUS.LOCAL_ONLY]
    : [SMS_AUDIT_STATUS.PASSED],
);

const filteredTemplates = computed(() =>
  templates.value.filter(
    (t) =>
      t.provider_id === formData.value.provider_id &&
      allowedStatuses.value.includes(t.audit_status as never),
  ),
);

// 当前场景可用的占位符集合(后端按场景下发,缺省回退到默认验证码占位符)
const sceneAvailableParams = computed<string[]>(() => {
  const params = editingScene.value?.available_params;
  return params && params.length > 0 ? params : ['code', 'min'];
});

// 取模板占位符:优先用后端派生字段,缺省时从模板内容兜底解析
const getTemplatePlaceholders = (
  template: SmsTemplateApi.TemplateItem,
): string[] => {
  return template.placeholders && template.placeholders.length > 0
    ? template.placeholders
    : extractPlaceholders(template.template_content);
};

// 判断模板占位符是否被当前场景全部支持,返回不被支持的占位符列表
const getUnsupportedPlaceholders = (
  template: SmsTemplateApi.TemplateItem,
): string[] => {
  const available = sceneAvailableParams.value;
  return getTemplatePlaceholders(template).filter(
    (name) => !available.includes(name),
  );
};

// 模板下拉选项:在 provider + 审核状态过滤基础上,对占位符不兼容的模板标灰禁用
const templateOptions = computed(() =>
  filteredTemplates.value.map((t) => {
    const unsupported = getUnsupportedPlaceholders(t);
    const baseLabel = `${t.template_name} (${t.template_code})`;
    if (unsupported.length === 0) {
      return { label: baseLabel, value: t.id, disabled: false };
    }
    const reason = unsupported.map((name) => `\${${name}}`).join('、');
    return {
      label: `${baseLabel} — 含场景不支持的占位符 ${reason}`,
      value: t.id,
      disabled: true,
    };
  }),
);

const filteredSigns = computed(() =>
  signs.value.filter(
    (s) =>
      s.provider_id === formData.value.provider_id &&
      allowedStatuses.value.includes(s.audit_status as never),
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
    provider_id: row.provider_id || providers.value[0]?.id || 0,
    template_id: row.template_id || 0,
    sign_id: row.sign_id || 0,
    status: row.status ?? 1,
  };
  // 回填的模板若与当前场景占位符不兼容,清空并提示重选
  if (formData.value.template_id) {
    const bound = templates.value.find(
      (t) => t.id === formData.value.template_id,
    );
    if (bound && getUnsupportedPlaceholders(bound).length > 0) {
      formData.value.template_id = 0;
      message.warning('原绑定模板与当前场景占位符不兼容,请重新选择模板');
    }
  }
  modalVisible.value = true;
};

const handleProviderChange = () => {
  // 切换服务商后清空模板/签名,强制重新选择
  formData.value.template_id = 0;
  formData.value.sign_id = 0;
};

const handleSubmit = async () => {
  if (!formData.value.provider_id) {
    message.error('请选择服务商');
    return;
  }
  if (!formData.value.template_id || !formData.value.sign_id) {
    message.error('请完整选择模板与签名');
    return;
  }
  await bindSmsSceneApi(formData.value);
  message.success('绑定成功');
  modalVisible.value = false;
  loadAll();
};

const handleUnbind = async (row: SmsSceneApi.SceneItem) => {
  await unbindSmsSceneApi(row.scene_code);
  message.success('已取消绑定');
  loadAll();
};

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '场景', dataIndex: 'scene_name', width: 180 },
  { title: '场景编码', dataIndex: 'scene_code', width: 200 },
  { title: '服务商', dataIndex: 'provider_name', width: 160 },
  { title: '模板', dataIndex: 'template_name', width: 200 },
  { title: '签名', dataIndex: 'sign_name', width: 160 },
  { title: '状态', dataIndex: 'status', width: 100 },
  { title: '更新时间', dataIndex: 'update_time', width: 180 },
  { title: '操作', key: 'action', width: 200 },
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
        message="把内置场景与服务商绑定后即可发送短信验证码。传统短信驱动需要绑定已审核通过的模板和签名；PNVS 驱动签名和模板可选，如不配置则使用平台默认值。"
      />
    </div>

    <a-form layout="inline" class="mb-4" v-access:code="'SmsSceneList'">
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
          placeholder="场景/编码/模板/签名"
          allow-clear
          style="width: 220px"
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
      :scroll="{ x: 1460 }"
      row-key="scene_code"
      @change="handleTableChange"
      v-access:code="'SmsSceneList'"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.dataIndex === 'id'">
          <span>{{ record.id || '-' }}</span>
        </template>
        <template
          v-if="
            ['provider_name', 'template_name', 'sign_name'].includes(
              column.dataIndex,
            )
          "
        >
          <span v-if="record[column.dataIndex]">{{
            record[column.dataIndex]
          }}</span>
          <a-tag v-else color="default">未绑定</a-tag>
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
              {{ record.provider_id ? '修改绑定' : '绑定' }}
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
      :title="`绑定场景:${editingScene?.scene_name || ''}`"
      width="560px"
      @ok="handleSubmit"
    >
      <a-form :label-col="{ span: 6 }" :wrapper-col="{ span: 16 }">
        <a-form-item label="场景">
          <a-input :value="editingScene?.scene_name" disabled />
        </a-form-item>
        <a-form-item label="服务商" required>
          <a-select
            v-model:value="formData.provider_id"
            :options="providers.map((p) => ({ label: p.name, value: p.id }))"
            @change="handleProviderChange"
          />
        </a-form-item>
        <a-form-item label="模板" :required="!isPnvs">
          <a-select
            v-model:value="formData.template_id"
            placeholder="仅可选已审核通过的模板"
            :options="templateOptions"
            :disabled="filteredTemplates.length === 0"
          />
          <div
            v-if="filteredTemplates.length === 0 && formData.provider_id"
            class="mt-1 text-xs text-gray-500"
          >
            当前服务商下没有已审核通过的模板,请先到模板管理创建并等待审核
          </div>
          <div v-else class="mt-1 text-xs text-gray-500">
            只能选占位符为
            {{ sceneAvailableParams.map((p) => '${' + p + '}').join('、') }}
            的模板;标灰项含当前场景无法提供的参数
          </div>
        </a-form-item>
        <a-form-item label="签名" :required="!isPnvs">
          <a-select
            v-model:value="formData.sign_id"
            placeholder="仅可选已审核通过的签名"
            :options="
              filteredSigns.map((s) => ({
                label: s.sign_name,
                value: s.id,
              }))
            "
            :disabled="filteredSigns.length === 0"
          />
          <div
            v-if="filteredSigns.length === 0 && formData.provider_id"
            class="mt-1 text-xs text-gray-500"
          >
            当前服务商下没有已审核通过的签名
          </div>
        </a-form-item>
        <a-alert
          v-if="isPnvs"
          type="warning"
          show-icon
          message="PNVS 签名和模板为可选项。如不配置将使用平台默认签名和模板；如您的账号要求传入签名，请在此处选择已配置的签名。"
          class="mb-4"
        />
        <a-form-item label="状态">
          <a-radio-group v-model:value="formData.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
