<script lang="ts" setup>
import type { FormInstance, Rule } from 'ant-design-vue/es/form';

import type { ClientThemeApi } from '#/api/client';

import { computed, onMounted, reactive, ref, watch } from 'vue';

import { message, Modal } from 'ant-design-vue';

import {
  copyClientThemeApi,
  createClientThemeApi,
  deleteClientThemeApi,
  getClientThemeInfoApi,
  getClientThemeListApi,
  getClientThemePolicyApi,
  publishClientThemeApi,
  updateClientThemeApi,
  updateClientThemePolicyApi,
} from '#/api/client';
import { useTableCrud } from '#/composables/useTableCrud';

import ClientPhonePreview from '../components/ClientPhonePreview.vue';

defineOptions({ name: 'ClientThemeManagement' });

type TokenRow = {
  desc?: string;
  key: string;
  label: string;
  required?: boolean;
  value: string;
};

const THEME_TYPE_OPTIONS: Array<{
  color: string;
  label: string;
  value: ClientThemeApi.ThemeType;
}> = [
  { color: 'gold', label: '浅色', value: 'light' },
  { color: 'blue', label: '深色', value: 'dark' },
  { color: 'purple', label: '自定义', value: 'custom' },
];

const MODE_OPTIONS: Array<{
  desc: string;
  label: string;
  value: ClientThemeApi.ThemeMode;
}> = [
  {
    desc: '客户端根据系统浅色/深色自动切换',
    label: '跟随系统',
    value: 'system',
  },
  { desc: '固定使用系统浅色主题', label: '浅色', value: 'light' },
  { desc: '固定使用系统深色主题', label: '深色', value: 'dark' },
  { desc: '固定使用管理员发布的自定义主题', label: '自定义', value: 'custom' },
];

const TOKEN_DEFS: TokenRow[] = [
  {
    desc: '按钮、链接、选中态等主色',
    key: 'colorPrimary',
    label: '品牌主色',
    required: true,
    value: '#0d50d5',
  },
  {
    desc: '浅色按钮和弱主色背景',
    key: 'colorPrimaryLight',
    label: '品牌浅色',
    value: '#386bef',
  },
  {
    desc: '页面最底层背景',
    key: 'colorBg',
    label: '页面背景',
    required: true,
    value: '#ffffff',
  },
  {
    desc: '次级页面背景',
    key: 'colorBgSecondary',
    label: '次级背景',
    value: '#faf8ff',
  },
  {
    desc: '卡片、模块、弹层背景',
    key: 'colorBgSurface',
    label: '卡片背景',
    required: true,
    value: '#f3f3fe',
  },
  {
    desc: '标题和正文主文字',
    key: 'colorText',
    label: '主文字',
    required: true,
    value: '#191b23',
  },
  {
    desc: '说明、辅助信息文字',
    key: 'colorTextSecondary',
    label: '次级文字',
    required: true,
    value: '#434654',
  },
  {
    desc: '弱提示和占位文字',
    key: 'colorTextTertiary',
    label: '弱文字',
    value: '#737686',
  },
  {
    desc: '卡片、输入框边框',
    key: 'colorBorder',
    label: '边框',
    required: true,
    value: '#e0e4e8',
  },
  {
    desc: '列表和模块分隔线',
    key: 'colorDivider',
    label: '分割线',
    value: '#f0f2f5',
  },
  {
    desc: '价格、促销价、金额强调',
    key: 'colorPrice',
    label: '价格色',
    required: true,
    value: '#ff5a1f',
  },
  {
    desc: '错误和危险操作',
    key: 'colorError',
    label: '错误色',
    value: '#ba1a1a',
  },
  {
    desc: '成功状态',
    key: 'colorSuccess',
    label: '成功色',
    value: '#34c759',
  },
  {
    desc: '警告状态',
    key: 'colorWarning',
    label: '警告色',
    value: '#f0ad4e',
  },
];

const SUMMARY_TOKEN_KEYS = [
  'colorPrimary',
  'colorBg',
  'colorBgSurface',
  'colorText',
  'colorPrice',
];

const DEFAULT_PREVIEW_TOKENS = Object.fromEntries(
  TOKEN_DEFS.map((item) => [item.key, item.value]),
) as Record<string, string>;

const activeTab = ref<'setting' | 'themes'>('setting');

const tokenDefMap = computed<Record<string, TokenRow>>(() =>
  Object.fromEntries(TOKEN_DEFS.map((item) => [item.key, item])),
);

const typeMap = computed<Record<string, (typeof THEME_TYPE_OPTIONS)[number]>>(
  () =>
    Object.fromEntries(THEME_TYPE_OPTIONS.map((item) => [item.value, item])),
);

const modeMap = computed<Record<string, (typeof MODE_OPTIONS)[number]>>(() =>
  Object.fromEntries(MODE_OPTIONS.map((item) => [item.value, item])),
);

const { tableData, loading, pagination, loadData } = useTableCrud<
  ClientThemeApi.ThemeItem,
  ClientThemeApi.ListParams
>(
  {
    delete: deleteClientThemeApi,
    list: getClientThemeListApi,
  },
  { immediateLoad: false },
);

const searchParams = ref<ClientThemeApi.ListParams>({
  keyword: '',
  status: undefined,
  type: undefined,
});

const columns = [
  { dataIndex: 'name', title: '主题名称', width: 200 },
  { dataIndex: 'type', title: '类型', width: 110 },
  { dataIndex: 'tokens', title: '配色摘要', width: 360 },
  { dataIndex: 'sort', title: '排序', width: 90 },
  { dataIndex: 'status', title: '状态', width: 100 },
  { dataIndex: 'update_time', title: '更新时间', width: 170 },
  { fixed: 'right', key: 'action', title: '操作', width: 240 },
];

const policyLoading = ref(false);
const policyForm = reactive<ClientThemeApi.ThemePolicy>({
  allow_user_select: 1,
  default_mode: 'system',
  default_theme_id: null,
});

const themeOptions = computed(() =>
  tableData.value
    .filter((item) => item.status === 1 && item.type === 'custom')
    .map((item) => ({
      label: item.name,
      value: item.id,
    })),
);

const currentModeDesc = computed(
  () => modeMap.value[policyForm.default_mode]?.desc || '',
);

const publishedThemes = computed(() =>
  tableData.value.filter((item) => item.status === 1),
);

const previewThemeCards = computed(() => publishedThemes.value.slice(0, 4));

const getThemeTokens = (theme?: ClientThemeApi.ThemeItem | null) => ({
  ...DEFAULT_PREVIEW_TOKENS,
  ...theme?.tokens,
});

const getDefaultCustomTheme = () =>
  publishedThemes.value.find(
    (item) => item.id === policyForm.default_theme_id && item.type === 'custom',
  );

const getThemeByType = (type: ClientThemeApi.ThemeType) =>
  publishedThemes.value.find((item) => item.type === type);

const currentPreviewTheme = computed(() => {
  if (policyForm.default_mode === 'custom') {
    return getDefaultCustomTheme() || getThemeByType('custom');
  }
  if (policyForm.default_mode === 'dark') {
    return getThemeByType('dark');
  }
  return getThemeByType('light');
});

const currentPreviewTokens = computed(() =>
  getThemeTokens(currentPreviewTheme.value),
);

const policyPriorityDesc = computed(() =>
  policyForm.allow_user_select
    ? '开启后，后台默认主题作为首次进入和兜底配置；用户在客户端选择主题后，以用户个人选择为准。'
    : '关闭后，客户端隐藏主题选择入口，所有用户统一使用后台设置的默认模式。',
);

const isSystemTheme = (record: ClientThemeApi.ThemeItem) =>
  record.is_system === 1;

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    status: undefined,
    type: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

const loadPolicy = async () => {
  try {
    const policy = await getClientThemePolicyApi();
    Object.assign(policyForm, {
      allow_user_select: policy.allow_user_select ?? 1,
      default_mode: policy.default_mode || 'system',
      default_theme_id: policy.default_theme_id ?? null,
    });
  } catch (error) {
    console.error('加载主题设置失败:', error);
  }
};

const handleSavePolicy = async () => {
  policyLoading.value = true;
  try {
    await updateClientThemePolicyApi({
      allow_user_select: policyForm.allow_user_select,
      default_mode: policyForm.default_mode,
      default_theme_id:
        policyForm.default_mode === 'custom'
          ? policyForm.default_theme_id || null
          : null,
    });
    message.success('主题设置已保存');
  } catch (error) {
    console.error('保存主题设置失败:', error);
  } finally {
    policyLoading.value = false;
  }
};

watch(
  () => policyForm.default_mode,
  (mode) => {
    if (mode !== 'custom') {
      policyForm.default_theme_id = null;
    }
  },
);

const modalVisible = ref(false);
const modalLoading = ref(false);
const formRef = ref<FormInstance>();
const editingId = ref<null | number>(null);

const formData = reactive({
  name: '',
  sort: 0,
  status: 0,
  tokenRows: [] as TokenRow[],
  type: 'custom' as ClientThemeApi.ThemeType,
});

const formRules: Record<string, Rule[]> = {
  name: [{ message: '请输入主题名称', required: true, whitespace: true }],
};

const modalTitle = computed(() => (editingId.value ? '编辑主题' : '新增主题'));

const tokenRowsFromTokens = (tokens?: Record<string, string>) => {
  const source = tokens || {};
  const rows = TOKEN_DEFS.map((item) => ({
    ...item,
    value: source[item.key] || item.value,
  }));
  Object.entries(source).forEach(([key, value]) => {
    if (!tokenDefMap.value[key]) {
      rows.push({
        key,
        label: key,
        value: String(value),
      });
    }
  });
  return rows;
};

const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    name: '',
    sort: 0,
    status: 0,
    tokenRows: tokenRowsFromTokens(),
    type: 'custom',
  });
};

const rowsToTokens = () => {
  const tokens: Record<string, string> = {};
  for (const row of formData.tokenRows) {
    const key = row.key.trim();
    if (key && row.value) {
      tokens[key] = row.value;
    }
  }
  return tokens;
};

const formPreviewTokens = computed(() => ({
  ...DEFAULT_PREVIEW_TOKENS,
  ...rowsToTokens(),
}));

const handleCreate = () => {
  editingId.value = null;
  resetForm();
  modalVisible.value = true;
};

const handleEdit = async (record: ClientThemeApi.ThemeItem) => {
  if (isSystemTheme(record)) {
    message.info('系统主题不能直接编辑，请复制为自定义主题后修改');
    return;
  }
  try {
    const detail = await getClientThemeInfoApi(record.id);
    editingId.value = detail.id;
    resetForm();
    Object.assign(formData, {
      name: detail.name,
      sort: detail.sort ?? 0,
      status: detail.status ?? 0,
      tokenRows: tokenRowsFromTokens(detail.tokens),
      type: detail.type,
    });
    modalVisible.value = true;
  } catch (error) {
    console.error('获取主题详情失败:', error);
    message.error('获取主题详情失败');
  }
};

const addTokenRow = () => {
  formData.tokenRows.push({
    key: '',
    label: '自定义颜色',
    value: '#1677ff',
  });
};

const removeTokenRow = (index: number) => {
  if (formData.tokenRows[index]?.required) {
    message.warning('核心颜色不能删除');
    return;
  }
  formData.tokenRows.splice(index, 1);
};

const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    const tokens = rowsToTokens();
    const missed = TOKEN_DEFS.filter(
      (item) => item.required && !tokens[item.key],
    );
    if (missed.length > 0) {
      message.warning(
        `请完善核心颜色：${missed.map((item) => item.label).join('、')}`,
      );
      return;
    }

    modalLoading.value = true;
    const data: ClientThemeApi.SaveParams = {
      name: formData.name,
      sort: formData.sort,
      status: formData.status,
      tokens,
      type: formData.type,
    };
    if (editingId.value) {
      await updateClientThemeApi(editingId.value, data);
      message.success('更新成功');
    } else {
      await createClientThemeApi(data);
      message.success('创建成功');
    }
    modalVisible.value = false;
    await loadData(searchParams.value);
  } catch (error: any) {
    if (!error?.errorFields) {
      console.error('保存主题失败:', error);
    }
  } finally {
    modalLoading.value = false;
  }
};

const handleCopy = async (record: ClientThemeApi.ThemeItem) => {
  await copyClientThemeApi(record.id);
  message.success('复制成功');
  await loadData(searchParams.value);
};

const handlePublish = async (record: ClientThemeApi.ThemeItem) => {
  if (isSystemTheme(record)) {
    message.info('系统主题已内置发布');
    return;
  }
  await publishClientThemeApi(record.id);
  message.success('发布成功');
  await loadData(searchParams.value);
};

const handleDelete = (record: ClientThemeApi.ThemeItem) => {
  if (isSystemTheme(record)) {
    message.info('系统主题不能删除');
    return;
  }
  Modal.confirm({
    content: `确定要删除主题"${record.name}"吗？`,
    onOk: async () => {
      await deleteClientThemeApi(record.id);
      message.success('删除成功');
      await loadData(searchParams.value);
    },
  });
};

const handleTableChange = (newPagination: {
  current?: number;
  pageSize?: number;
}) => {
  pagination.current = newPagination.current ?? pagination.current;
  pagination.pageSize = newPagination.pageSize ?? pagination.pageSize;
  loadData(searchParams.value);
};

onMounted(async () => {
  await Promise.all([loadData(searchParams.value), loadPolicy()]);
});
</script>

<template>
  <div class="theme-page">
    <a-tabs v-model:active-key="activeTab">
      <a-tab-pane key="setting" tab="主题设置">
        <a-card title="客户端主题策略">
          <a-alert
            class="mb-4"
            message="主题生效规则"
            :description="policyPriorityDesc"
            show-icon
            type="info"
          />
          <div class="setting-grid">
            <section class="setting-form">
              <a-form
                :label-col="{ style: { width: '108px' } }"
                :model="policyForm"
              >
                <a-form-item label="允许用户选择">
                  <a-switch
                    v-model:checked="policyForm.allow_user_select"
                    :checked-value="1"
                    :un-checked-value="0"
                    checked-children="开启"
                    un-checked-children="关闭"
                  />
                </a-form-item>
                <a-form-item label="默认模式">
                  <a-radio-group v-model:value="policyForm.default_mode">
                    <a-radio-button
                      v-for="item in MODE_OPTIONS"
                      :key="item.value"
                      :value="item.value"
                    >
                      {{ item.label }}
                    </a-radio-button>
                  </a-radio-group>
                </a-form-item>
                <a-form-item label="默认主题">
                  <a-select
                    v-model:value="policyForm.default_theme_id"
                    :disabled="policyForm.default_mode !== 'custom'"
                    :options="themeOptions"
                    allow-clear
                    placeholder="选择已发布的自定义主题"
                    style="width: 280px"
                  />
                </a-form-item>
                <a-form-item>
                  <a-button
                    type="primary"
                    :loading="policyLoading"
                    @click="handleSavePolicy"
                  >
                    保存设置
                  </a-button>
                </a-form-item>
              </a-form>
            </section>

            <section class="setting-preview">
              <div class="preview-title">
                <strong>当前策略预览</strong>
                <span>{{ currentPreviewTheme?.name || '系统默认主题' }}</span>
              </div>
              <ClientPhonePreview
                kind="profile"
                size="compact"
                current-path="/pages/profile/index"
                :theme-tokens="currentPreviewTokens"
              />
              <div class="preview-policy">
                <div class="preview-row">
                  <span>用户端入口</span>
                  <strong>{{
                    policyForm.allow_user_select ? '显示' : '隐藏'
                  }}</strong>
                </div>
                <div class="preview-row">
                  <span>后台默认</span>
                  <strong>{{ modeMap[policyForm.default_mode]?.label }}</strong>
                </div>
                <div class="preview-row">
                  <span>优先级</span>
                  <strong>{{
                    policyForm.allow_user_select
                      ? '用户选择优先'
                      : '后台统一控制'
                  }}</strong>
                </div>
                <div class="preview-desc">{{ currentModeDesc }}</div>
              </div>
            </section>
          </div>
        </a-card>
      </a-tab-pane>

      <a-tab-pane key="themes" tab="主题列表">
        <a-card>
          <template #title>
            <div class="theme-card-title">
              <span>主题列表</span>
              <a-space>
                <a-button @click="() => loadData(searchParams)">刷新</a-button>
                <a-button type="primary" @click="handleCreate">
                  新增主题
                </a-button>
              </a-space>
            </div>
          </template>

          <a-form layout="inline" class="mb-4">
            <a-form-item label="关键词">
              <a-input
                v-model:value="searchParams.keyword"
                allow-clear
                placeholder="主题名称"
                style="width: 200px"
              />
            </a-form-item>
            <a-form-item label="类型">
              <a-select
                v-model:value="searchParams.type"
                :options="THEME_TYPE_OPTIONS"
                allow-clear
                placeholder="请选择"
                style="width: 140px"
              />
            </a-form-item>
            <a-form-item label="状态">
              <a-select
                v-model:value="searchParams.status"
                allow-clear
                placeholder="请选择"
                style="width: 120px"
              >
                <a-select-option :value="1">已发布</a-select-option>
                <a-select-option :value="0">草稿</a-select-option>
              </a-select>
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

          <div v-if="previewThemeCards.length > 0" class="theme-preview-grid">
            <article
              v-for="record in previewThemeCards"
              :key="record.id"
              class="theme-preview-card"
            >
              <div class="theme-preview-card__head">
                <div>
                  <strong>{{ record.name }}</strong>
                  <span>{{ typeMap[record.type]?.label || record.type }}</span>
                </div>
                <a-tag :color="typeMap[record.type]?.color || 'default'">
                  {{ record.status === 1 ? '已发布' : '草稿' }}
                </a-tag>
              </div>
              <ClientPhonePreview
                kind="home"
                size="compact"
                current-path="/pages/index/index"
                :theme-tokens="getThemeTokens(record)"
              />
            </article>
          </div>

          <a-table
            :columns="columns"
            :data-source="tableData"
            :loading="loading"
            :pagination="pagination"
            :scroll="{ x: 1180 }"
            row-key="id"
            @change="handleTableChange"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.dataIndex === 'name'">
                <div class="theme-name-cell">
                  <strong>{{ record.name }}</strong>
                  <a-tag v-if="isSystemTheme(record)">系统</a-tag>
                </div>
              </template>

              <template v-if="column.dataIndex === 'type'">
                <a-tag :color="typeMap[record.type]?.color || 'default'">
                  {{ typeMap[record.type]?.label || record.type }}
                </a-tag>
              </template>

              <template v-if="column.dataIndex === 'tokens'">
                <div class="token-summary">
                  <div
                    v-for="key in SUMMARY_TOKEN_KEYS"
                    :key="key"
                    class="token-chip"
                  >
                    <i
                      :style="{
                        backgroundColor: record.tokens?.[key] || '#999999',
                      }"
                    ></i>
                    <span>{{ tokenDefMap[key]?.label || key }}</span>
                    <em>{{ record.tokens?.[key] || '-' }}</em>
                  </div>
                </div>
              </template>

              <template v-if="column.dataIndex === 'status'">
                <a-tag :color="record.status === 1 ? 'green' : 'default'">
                  {{ record.status === 1 ? '已发布' : '草稿' }}
                </a-tag>
              </template>

              <template v-if="column.key === 'action'">
                <a-space v-if="isSystemTheme(record)">
                  <a-tag>系统内置</a-tag>
                  <a-button
                    type="link"
                    size="small"
                    @click="handleCopy(record)"
                  >
                    复制
                  </a-button>
                </a-space>
                <a-space v-else>
                  <a-button
                    type="link"
                    size="small"
                    @click="handleEdit(record)"
                  >
                    编辑
                  </a-button>
                  <a-button
                    type="link"
                    size="small"
                    @click="handleCopy(record)"
                  >
                    复制
                  </a-button>
                  <a-button
                    type="link"
                    size="small"
                    @click="handlePublish(record)"
                  >
                    发布
                  </a-button>
                  <a-button
                    type="link"
                    danger
                    size="small"
                    @click="handleDelete(record)"
                  >
                    删除
                  </a-button>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-tab-pane>
    </a-tabs>

    <a-modal
      v-model:open="modalVisible"
      :confirm-loading="modalLoading"
      :title="modalTitle"
      width="1120px"
      @ok="handleSubmit"
    >
      <div class="theme-modal-grid">
        <a-form
          ref="formRef"
          :label-col="{ style: { width: '100px' } }"
          :model="formData"
          :rules="formRules"
          class="pt-4"
        >
          <a-form-item label="主题名称" name="name">
            <a-input
              v-model:value="formData.name"
              allow-clear
              placeholder="请输入主题名称"
            />
          </a-form-item>
          <a-form-item label="主题类型">
            <a-tag color="purple">自定义主题</a-tag>
            <span class="type-hint">
              系统浅色/深色为内置主题，复制后可改为自定义主题。
            </span>
          </a-form-item>
          <a-form-item label="排序" name="sort">
            <a-input-number
              v-model:value="formData.sort"
              :min="0"
              :max="9999"
              class="w-full"
            />
          </a-form-item>
          <a-form-item label="状态" name="status">
            <a-radio-group v-model:value="formData.status">
              <a-radio :value="0">草稿</a-radio>
              <a-radio :value="1">已发布</a-radio>
            </a-radio-group>
          </a-form-item>
          <a-form-item label="主题颜色">
            <div class="token-editor">
              <div
                v-for="(row, index) in formData.tokenRows"
                :key="`${row.key}_${index}`"
                class="token-row"
              >
                <div class="token-info">
                  <strong>{{ row.label }}</strong>
                  <span>{{ row.desc || row.key || '自定义主题变量' }}</span>
                </div>
                <a-input
                  v-if="!row.required && !tokenDefMap[row.key]"
                  v-model:value="row.key"
                  allow-clear
                  placeholder="变量编码，如 colorAccent"
                />
                <div v-else class="token-code">{{ row.key }}</div>
                <a-input
                  v-model:value="row.value"
                  allow-clear
                  placeholder="#1677ff"
                >
                  <template #addonAfter>
                    <input
                      v-model="row.value"
                      class="color-input"
                      type="color"
                    />
                  </template>
                </a-input>
                <a-button danger size="small" @click="removeTokenRow(index)">
                  删除
                </a-button>
              </div>
              <a-button size="small" @click="addTokenRow">
                添加自定义颜色
              </a-button>
            </div>
          </a-form-item>
        </a-form>

        <aside class="theme-modal-preview">
          <div class="preview-title">
            <strong>实时预览</strong>
            <span>{{ formData.name || '未命名主题' }}</span>
          </div>
          <ClientPhonePreview
            kind="home"
            size="compact"
            current-path="/pages/index/index"
            :theme-tokens="formPreviewTokens"
          />
        </aside>
      </div>
    </a-modal>
  </div>
</template>

<style scoped>
.theme-page {
  min-height: 100%;
  padding: 16px;
}

.setting-grid {
  display: grid;
  grid-template-columns: minmax(420px, 1fr) 360px;
  gap: 24px;
}

.setting-form,
.setting-preview {
  min-width: 0;
}

.setting-preview {
  padding: 18px;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  background: hsl(var(--card));
}

.preview-title {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 14px;
  font-weight: 600;
}

.preview-title span {
  overflow: hidden;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
  font-weight: 400;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.preview-policy {
  margin-top: 16px;
}

.preview-row {
  display: flex;
  justify-content: space-between;
  padding: 10px 0;
  border-bottom: 1px solid hsl(var(--border));
}

.preview-row span,
.preview-desc,
.token-info span,
.token-code {
  color: hsl(var(--muted-foreground));
}

.preview-desc {
  margin-top: 12px;
  line-height: 1.7;
}

.theme-card-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.theme-preview-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(260px, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}

.theme-preview-card {
  min-width: 0;
  overflow: hidden;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  background: hsl(var(--card));
}

.theme-preview-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 14px;
  border-bottom: 1px solid hsl(var(--border));
}

.theme-preview-card__head > div {
  min-width: 0;
}

.theme-preview-card__head strong,
.theme-preview-card__head span {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.theme-preview-card__head span {
  margin-top: 4px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
}

.theme-preview-card :deep(.client-phone-preview) {
  padding: 12px;
  background: hsl(var(--muted) / 28%);
}

.theme-name-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.token-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.token-chip {
  display: inline-flex;
  align-items: center;
  max-width: 190px;
  gap: 5px;
  padding: 4px 8px;
  border: 1px solid hsl(var(--border));
  border-radius: 6px;
}

.token-chip i {
  width: 14px;
  height: 14px;
  border: 1px solid hsl(var(--border));
  border-radius: 3px;
}

.token-chip span,
.token-chip em {
  overflow: hidden;
  font-size: 12px;
  font-style: normal;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.token-chip em {
  color: hsl(var(--muted-foreground));
}

.token-editor {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.token-row {
  display: grid;
  grid-template-columns:
    minmax(150px, 0.9fr) minmax(140px, 0.7fr) minmax(180px, 1fr)
    auto;
  gap: 8px;
  align-items: center;
  padding: 10px;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.token-info {
  min-width: 0;
}

.token-info strong,
.token-info span {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.token-info span {
  margin-top: 3px;
  font-size: 12px;
}

.token-code {
  overflow: hidden;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.color-input {
  width: 28px;
  height: 24px;
  padding: 0;
  border: 0;
  background: transparent;
}

.type-hint {
  margin-left: 8px;
  color: hsl(var(--muted-foreground));
}

.theme-modal-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 330px;
  gap: 18px;
  align-items: start;
}

.theme-modal-preview {
  position: sticky;
  top: 0;
  min-width: 0;
  padding: 14px;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  background: hsl(var(--card));
}

@media (max-width: 980px) {
  .setting-grid,
  .theme-modal-grid,
  .token-row {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 1500px) {
  .theme-preview-grid {
    grid-template-columns: repeat(2, minmax(260px, 1fr));
  }
}

@media (max-width: 980px) {
  .theme-preview-grid {
    grid-template-columns: 1fr;
  }
}
</style>
