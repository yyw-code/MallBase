<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import { computed, ref, watch } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

import { invalidateUploadConfig } from '#/api/core/upload-config-cache';
import { getSettingSectionListApi, updateSettingItemApi } from '#/api/setting';

type GroupOption = {
  label: string;
  value: number;
};

const props = defineProps<{
  detailData?: null | SettingApi.SettingItem;
  groupOptions: GroupOption[];
  visible: boolean;
}>();

const emit = defineEmits<{
  (e: 'success'): void;
  (e: 'update:visible', value: boolean): void;
}>();

const { hasAccessByCodes } = useAccess();

const TYPE_LABEL_MAP: Record<string, string> = {
  checkbox: '多选',
  editor: '富文本',
  file: '文件',
  files: '多文件',
  image: '图片',
  images: '多图',
  input: '文本',
  json: 'JSON',
  number: '数字',
  password: '密码',
  radio: '单选',
  select: '下拉',
  switch: '开关',
  textarea: '多行文本',
};

const drawerWidth = 'min(860px, calc(100vw - 24px))';
const saving = ref(false);
const activeTab = ref('base');
const sectionLoading = ref(false);
const sectionItems = ref<SettingApi.SettingSection[]>([]);
const sectionCode = ref('');
const canUpdateSettingItem = computed(() =>
  hasAccessByCodes(['SettingItemUpdate']),
);

const currentGroupLabel = computed(() => {
  const groupId = Number(props.detailData?.group_id || 0);
  return (
    props.groupOptions.find((item) => Number(item.value) === groupId)?.label ||
    '-'
  );
});

const sectionSelectOptions = computed(() =>
  sectionItems.value.map((item) => ({
    label: item.name,
    value: item.code,
  })),
);

const effectiveSectionLabel = computed(() => {
  const item = props.detailData;
  if (!item) return '-';
  return item.resolved_ui?.section || item.ui?.section || '-';
});

const typeLabel = computed(() => {
  const type = props.detailData?.type || '';
  return TYPE_LABEL_MAP[type] || type || '-';
});

const inputComponentLabel = computed(() => {
  const ui = props.detailData?.resolved_ui || props.detailData?.ui;
  if (!ui?.component) return '默认组件';
  if (ui.component === 'money_yuan') return '金额输入';
  if (ui.component === 'remote_select') return '远程下拉';
  return ui.component;
});

const visibleConditionText = computed(() => {
  const ui = props.detailData?.resolved_ui || props.detailData?.ui;
  const conditions = ui?.visible_when || [];
  return conditions.length > 0 ? `${conditions.length} 条` : '-';
});

const optionsText = computed(() => formatJsonLike(props.detailData?.options));
const rulesText = computed(() => formatJsonLike(props.detailData?.rules));

const loadSections = async (groupId: number) => {
  if (!groupId) {
    sectionItems.value = [];
    return;
  }

  sectionLoading.value = true;
  try {
    sectionItems.value = await getSettingSectionListApi(groupId);
  } catch (error) {
    console.error('加载页内分组失败:', error);
    message.error('加载页内分组失败');
    sectionItems.value = [];
  } finally {
    sectionLoading.value = false;
  }
};

function formatJsonLike(value: unknown): string {
  if (value === undefined || value === null || value === '') return '-';
  if (typeof value === 'string') {
    try {
      return JSON.stringify(JSON.parse(value), null, 2);
    } catch {
      return value;
    }
  }
  return JSON.stringify(value, null, 2);
}

function buildUiPayload(): null | SettingApi.SettingItemUi {
  const currentUi = { ...props.detailData?.ui };
  delete currentUi.section;

  const nextSectionCode = sectionCode.value.trim();
  if (nextSectionCode) {
    currentUi.section_code = nextSectionCode.slice(0, 64);
  } else {
    delete currentUi.section_code;
  }

  return Object.keys(currentUi).length > 0 ? currentUi : null;
}

const handleCancel = () => {
  emit('update:visible', false);
};

const handleSaveSection = async () => {
  if (!props.detailData?.id) return;

  saving.value = true;
  try {
    await updateSettingItemApi(props.detailData.id, {
      ui: buildUiPayload(),
    });
    invalidateUploadConfig();
    message.success('页内分组已保存');
    emit('success');
  } catch (error) {
    console.error('保存页内分组失败:', error);
    message.error('保存页内分组失败');
  } finally {
    saving.value = false;
  }
};

watch(
  () => [props.visible, props.detailData?.id] as const,
  async ([visible]) => {
    if (!visible || !props.detailData) return;

    activeTab.value = 'base';
    sectionCode.value = String(
      props.detailData.ui?.section_code ||
        props.detailData.resolved_ui?.section_code ||
        '',
    );
    await loadSections(Number(props.detailData.group_id || 0));
  },
);
</script>

<template>
  <a-drawer
    :open="visible"
    title="设置项详情"
    :width="drawerWidth"
    class="setting-item-detail-drawer"
    destroy-on-close
    placement="right"
    @close="handleCancel"
  >
    <div v-if="detailData" class="detail-content">
      <a-tabs v-model:active-key="activeTab" class="detail-drawer-tabs">
        <a-tab-pane key="base" tab="基础信息">
          <a-form :label-col="{ style: { width: '100px' } }" class="pt-4">
            <a-form-item label="所属分组">
              <a-input :value="currentGroupLabel" disabled />
            </a-form-item>
            <a-form-item label="名称">
              <a-input :value="detailData.name" disabled />
            </a-form-item>
            <a-form-item label="编码">
              <a-input :value="detailData.code" disabled />
            </a-form-item>
            <a-form-item label="表单类型">
              <a-input :value="`${typeLabel}（${detailData.type}）`" disabled />
            </a-form-item>
            <a-form-item label="来源">
              <div class="readonly-field">
                <a-tag :color="detailData.is_system === 1 ? 'blue' : 'default'">
                  {{ detailData.is_system === 1 ? '系统内置' : '用户添加' }}
                </a-tag>
              </div>
            </a-form-item>
            <a-form-item label="默认值">
              <a-textarea :value="detailData.value || '-'" disabled :rows="2" />
            </a-form-item>
            <a-form-item label="占位提示">
              <a-input :value="detailData.placeholder || '-'" disabled />
            </a-form-item>
            <a-form-item label="当前页内分组">
              <a-input :value="effectiveSectionLabel" disabled />
            </a-form-item>
            <a-form-item label="输入组件">
              <a-input :value="inputComponentLabel" disabled />
            </a-form-item>
            <a-form-item label="显示条件">
              <a-input :value="visibleConditionText" disabled />
            </a-form-item>
            <a-form-item label="选项">
              <a-textarea :value="optionsText" disabled :rows="3" />
            </a-form-item>
            <a-form-item label="验证规则">
              <a-textarea :value="rulesText" disabled :rows="4" />
            </a-form-item>
            <a-form-item label="备注">
              <a-textarea
                :value="detailData.remark || '-'"
                disabled
                :rows="2"
              />
            </a-form-item>
            <a-form-item label="排序">
              <a-input :value="String(detailData.sort)" disabled />
            </a-form-item>
          </a-form>
        </a-tab-pane>

        <a-tab-pane key="section" tab="页内分组">
          <a-form :label-col="{ style: { width: '100px' } }" class="pt-4">
            <a-form-item label="页内分组">
              <a-select
                v-model:value="sectionCode"
                :disabled="!canUpdateSettingItem"
                :loading="sectionLoading"
                :options="sectionSelectOptions"
                allow-clear
                placeholder="请选择页内分组"
                show-search
                option-filter-prop="label"
              />
              <div class="rule-tip">
                这里只保存设置项在当前页面里的分段归属，不修改基础信息。
              </div>
            </a-form-item>
          </a-form>
        </a-tab-pane>
      </a-tabs>
    </div>
    <a-empty v-else description="暂无设置项数据" />

    <template #footer>
      <div class="drawer-footer">
        <a-button @click="handleCancel">取消</a-button>
        <a-button
          v-if="activeTab === 'section'"
          type="primary"
          :loading="saving"
          @click="handleSaveSection"
          v-access:code="'SettingItemUpdate'"
        >
          保存页内分组
        </a-button>
      </div>
    </template>
  </a-drawer>
</template>

<style lang="css" scoped>
.detail-content {
  width: 100%;
}

.detail-descriptions :deep(.ant-descriptions-item-label) {
  width: 120px;
  color: hsl(var(--muted-foreground));
}

.detail-drawer-tabs :deep(.ant-tabs-nav) {
  margin-bottom: 16px;
}

.code-text {
  font-family:
    ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono',
    'Courier New', monospace;
}

.pre-wrap {
  display: inline-block;
  max-width: 100%;
  white-space: pre-wrap;
  word-break: break-word;
}

.section-editor {
  padding: 12px;
  margin-top: 16px;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.section-editor-title {
  margin-bottom: 8px;
  font-size: 14px;
  font-weight: 500;
  color: hsl(var(--foreground));
}

.section-editor-tip {
  margin-top: 6px;
  font-size: 12px;
  line-height: 1.5;
  color: hsl(var(--muted-foreground));
}

.drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.setting-item-detail-drawer :deep(.ant-drawer-body) {
  padding-bottom: 12px;
}

.setting-item-detail-drawer :deep(.ant-drawer-footer) {
  padding: 12px 24px;
  background: hsl(var(--background));
  border-top: 1px solid hsl(var(--border));
}
</style>
