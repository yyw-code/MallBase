<script lang="ts" setup>
import type { SmsSceneApi } from '#/api/sms/scene';
import type { SmsProviderApi } from '#/api/sms/provider';
import type { SmsSignApi } from '#/api/sms/sign';
import type { SmsTemplateApi } from '#/api/sms/template';

import { computed, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

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
const formData = ref<SmsSceneApi.BindParams>({
  scene_code: '',
  provider_id: 0,
  template_id: 0,
  sign_id: 0,
  status: 1,
});

const filteredTemplates = computed(() =>
  templates.value.filter(
    (t) =>
      t.provider_id === formData.value.provider_id && t.audit_status === 'passed',
  ),
);

const filteredSigns = computed(() =>
  signs.value.filter(
    (s) =>
      s.provider_id === formData.value.provider_id && s.audit_status === 'passed',
  ),
);

const loadAll = async () => {
  loading.value = true;
  try {
    const [scenes, providerList, signList, templateList] = await Promise.all([
      getSmsSceneListApi(),
      getSmsProviderListApi({ page: 1, limit: 100 }),
      getSmsSignListApi({ page: 1, limit: 200 }),
      getSmsTemplateListApi({ page: 1, limit: 200 }),
    ]);
    tableData.value = scenes;
    providers.value = providerList.list;
    signs.value = signList.list;
    templates.value = templateList.list;
  } finally {
    loading.value = false;
  }
};

const openBindModal = (row: SmsSceneApi.SceneItem) => {
  editingScene.value = row;
  formData.value = {
    scene_code: row.scene_code,
    provider_id: row.provider_id || providers.value[0]?.id || 0,
    template_id: row.template_id || 0,
    sign_id: row.sign_id || 0,
    status: row.status || 1,
  };
  modalVisible.value = true;
};

const handleProviderChange = () => {
  // 切换服务商后清空模板/签名,强制重新选择
  formData.value.template_id = 0;
  formData.value.sign_id = 0;
};

const handleSubmit = async () => {
  if (
    !formData.value.provider_id ||
    !formData.value.template_id ||
    !formData.value.sign_id
  ) {
    message.error('请完整选择服务商、模板与签名');
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
  { title: '场景', dataIndex: 'scene_name', width: 180 },
  { title: '场景编码', dataIndex: 'scene_code', width: 200 },
  { title: '服务商', dataIndex: 'provider_name', width: 160 },
  { title: '模板', dataIndex: 'template_name', width: 200 },
  { title: '签名', dataIndex: 'sign_name', width: 160 },
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
        message="把内置场景与已审核通过的阿里云模板/签名绑定后,uniapp 端的验证码请求才能正常发送"
      />
    </div>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="false"
      :scroll="{ x: 1200 }"
      row-key="scene_code"
      v-access:code="'SmsSceneList'"
    >
      <template #bodyCell="{ column, record }">
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
        <a-form-item label="模板" required>
          <a-select
            v-model:value="formData.template_id"
            placeholder="仅可选已审核通过的模板"
            :options="
              filteredTemplates.map((t) => ({
                label: `${t.template_name} (${t.template_code})`,
                value: t.id,
              }))
            "
            :disabled="filteredTemplates.length === 0"
          />
          <div
            v-if="filteredTemplates.length === 0 && formData.provider_id"
            class="mt-1 text-xs text-gray-500"
          >
            当前服务商下没有已审核通过的模板,请先到模板管理创建并等待审核
          </div>
        </a-form-item>
        <a-form-item label="签名" required>
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
