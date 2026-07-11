<script lang="ts" setup>
import type { FormInstance, Rule } from 'ant-design-vue/es/form';

import type { SettingApi } from '#/api/setting';

import { computed, reactive, ref, watch } from 'vue';

import { IconPicker } from '@vben/common-ui';

import { message, Modal } from 'ant-design-vue';

import {
  createSettingGroupApi,
  createSettingSectionApi,
  deleteSettingSectionApi,
  getSettingGroupAllApi,
  getSettingGroupInfoApi,
  getSettingSectionListApi,
  updateSettingGroupApi,
  updateSettingSectionApi,
} from '#/api/setting';

const props = defineProps<{
  editData?: null | SettingApi.SettingGroup;
  mode?: 'create' | 'detail' | 'edit';
  visible: boolean;
}>();

const emit = defineEmits<{
  (e: 'success'): void;
  (e: 'update:visible', value: boolean): void;
}>();

const isEdit = computed(() => !!props.editData);
const drawerWidth = 'min(920px, calc(100vw - 24px))';
const saving = ref(false);
const activeTab = ref('base');
const currentGroupIsSystem = ref(false);
const isDetailMode = computed(() => props.mode === 'detail');
const isSystemGroup = computed(
  () =>
    isEdit.value &&
    (currentGroupIsSystem.value ||
      Number(props.editData?.is_system || 0) === 1),
);
const basicReadonly = computed(() => isDetailMode.value || isSystemGroup.value);
const modalTitle = computed(() => {
  if (!isEdit.value) return '新增分组';
  if (isDetailMode.value || isSystemGroup.value) return '分组详情';
  return '编辑分组';
});

const iconPrefix = ref('ant-design');
const formData = reactive({
  parent_id: 0 as number,
  name: '',
  code: '',
  icon: '',
  display_type: 'page' as 'category' | 'page' | 'tab',
  description: '',
  sort: 0,
  status: 1,
});
const formRef = ref<FormInstance>();

const formRules: Record<string, Rule[]> = {
  name: [
    { required: true, message: '请输入分组名称', whitespace: true },
    { max: 50, message: '分组名称不能超过50个字符' },
  ],
  code: [
    { required: true, message: '请输入分组编码', whitespace: true },
    {
      pattern: /^[a-z]\w*$/i,
      message: '编码只能包含英文字母、数字和下划线，且以字母开头',
    },
    { max: 50, message: '分组编码不能超过50个字符' },
  ],
};

const groupTreeData = ref<SettingApi.SettingGroup[]>([]);
const sectionItems = ref<SettingApi.SettingSection[]>([]);
const sectionLoading = ref(false);
const sectionSaving = ref(false);
const sectionForm = reactive({
  name: '',
  code: '',
  sort: 0,
});

const loadGroupTree = async () => {
  try {
    groupTreeData.value = await getSettingGroupAllApi();
  } catch {
    console.error('加载分组树失败');
  }
};

const groupTreeSelectData = computed(() => {
  const convert = (items: SettingApi.SettingGroup[]): any[] =>
    items
      .filter((item) => item.id !== props.editData?.id)
      .map((item) => ({
        title: item.name,
        value: item.id,
        key: item.id,
        children: item.children ? convert(item.children) : undefined,
      }));

  return [
    { title: '顶级（无父分组）', value: 0, key: 0 },
    ...convert(groupTreeData.value),
  ];
});

const isPageGroup = computed(
  () => formData.display_type === 'page' && !!props.editData?.id,
);

const sectionColumns = [
  { title: '名称', dataIndex: 'name', width: 180 },
  { title: '编码', dataIndex: 'code', width: 180 },
  { title: '排序', dataIndex: 'sort', width: 100 },
  { title: '来源', dataIndex: 'is_system', width: 100 },
  { title: '操作', key: 'action', width: 140 },
];

const loadSections = async (groupId?: number) => {
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

const resetSectionForm = () => {
  sectionForm.name = '';
  sectionForm.code = '';
  sectionForm.sort = 0;
};

const handleCreateSection = async () => {
  if (!props.editData?.id) return;
  const name = sectionForm.name.trim();
  const code = sectionForm.code.trim();
  if (!name || !code) {
    message.warning('请填写页内分组名称和编码');
    return;
  }

  sectionSaving.value = true;
  try {
    await createSettingSectionApi({
      group_id: props.editData.id,
      name,
      code,
      sort: Number(sectionForm.sort || 0),
    });
    message.success('创建成功');
    resetSectionForm();
    await loadSections(props.editData.id);
    emit('success');
  } catch (error) {
    console.error('创建页内分组失败:', error);
    message.error('创建页内分组失败');
  } finally {
    sectionSaving.value = false;
  }
};

const handleUpdateSection = async (record: SettingApi.SettingSection) => {
  const name = String(record.name || '').trim();
  const code = String(record.code || '').trim();
  if (!name || !code) {
    message.warning('请填写页内分组名称和编码');
    return;
  }

  sectionSaving.value = true;
  try {
    await updateSettingSectionApi(record.id, {
      name,
      code,
      sort: Number(record.sort || 0),
    });
    message.success('更新成功');
    await loadSections(props.editData?.id);
    emit('success');
  } catch (error) {
    console.error('更新页内分组失败:', error);
    message.error('更新页内分组失败');
  } finally {
    sectionSaving.value = false;
  }
};

const handleDeleteSection = (record: SettingApi.SettingSection) => {
  Modal.confirm({
    content: `确定要删除页内分组「${record.name}」吗？`,
    okType: 'danger',
    title: '删除页内分组',
    async onOk() {
      await deleteSettingSectionApi(record.id);
      message.success('删除成功');
      await loadSections(props.editData?.id);
      emit('success');
    },
  });
};

watch(
  () => [formData.parent_id, formData.display_type] as const,
  ([parentId, displayType]) => {
    if (displayType === 'category' && parentId !== 0) {
      message.warning('目录类型不能有父级，已自动重置');
      formData.parent_id = 0;
    }

    if (displayType === 'tab' && parentId === 0) {
      message.warning('选项卡必须选择父级分组');
    }
  },
);

watch(
  () => props.visible,
  async (val) => {
    if (!val) return;

    activeTab.value = 'base';
    resetSectionForm();
    loadGroupTree();
    formRef.value?.clearValidate();

    if (props.editData) {
      try {
        const detail = await getSettingGroupInfoApi(props.editData.id);
        currentGroupIsSystem.value = Number(detail.is_system || 0) === 1;
        Object.assign(formData, {
          parent_id: detail.parent_id,
          name: detail.name,
          code: detail.code,
          icon: detail.icon || '',
          display_type: detail.display_type || 'page',
          description: detail.description || '',
          sort: detail.sort,
          status: detail.status,
        });
        await loadSections(detail.id);
      } catch (error) {
        console.error('获取分组详情失败:', error);
        message.error('获取分组详情失败');
      }
    } else {
      currentGroupIsSystem.value = false;
      Object.assign(formData, {
        parent_id: 0,
        name: '',
        code: '',
        icon: '',
        display_type: 'page',
        description: '',
        sort: 0,
        status: 1,
      });
      sectionItems.value = [];
    }
  },
);

const handleCancel = () => {
  emit('update:visible', false);
};

const handleOk = async () => {
  if (basicReadonly.value) return;

  try {
    await formRef.value?.validate();
  } catch {
    return;
  }

  saving.value = true;
  try {
    if (isEdit.value && props.editData) {
      await updateSettingGroupApi(props.editData.id, formData);
      message.success('更新成功');
    } else {
      await createSettingGroupApi(formData);
      message.success('创建成功');
    }
    emit('update:visible', false);
    emit('success');
  } catch (error) {
    console.error('保存失败:', error);
    message.error('保存失败');
  } finally {
    saving.value = false;
  }
};
</script>

<template>
  <a-drawer
    :open="visible"
    :title="modalTitle"
    :width="drawerWidth"
    class="setting-group-drawer"
    destroy-on-close
    @close="handleCancel"
  >
    <a-tabs v-model:active-key="activeTab" class="group-drawer-tabs">
      <a-tab-pane key="base" tab="基础信息">
        <a-alert
          v-if="basicReadonly"
          class="mb-4"
          message="系统内置分组基础信息只允许查看，可在“页内分组”中调整表单分段。"
          show-icon
          type="info"
        />

        <a-form
          ref="formRef"
          :model="formData"
          :rules="formRules"
          :label-col="{ style: { width: '100px' } }"
          class="pt-4"
        >
          <a-form-item label="父分组" name="parent_id">
            <a-tree-select
              v-model:value="formData.parent_id"
              :disabled="basicReadonly"
              :tree-data="groupTreeSelectData"
              placeholder="请选择父分组"
              tree-default-expand-all
            />
            <div class="mt-1 text-xs text-gray-400">
              选择父分组后，菜单层级将自动跟随分组层级
            </div>
          </a-form-item>

          <a-form-item label="分组名称" name="name">
            <a-input
              v-model:value="formData.name"
              :disabled="basicReadonly"
              placeholder="如：微信设置"
            />
          </a-form-item>

          <a-form-item label="分组编码" name="code">
            <a-input
              v-model:value="formData.code"
              :disabled="basicReadonly"
              placeholder="如：wechat"
            />
          </a-form-item>

          <a-form-item label="图标" name="icon">
            <div class="flex w-full flex-col">
              <div class="mb-2">
                <a-select
                  v-model:value="iconPrefix"
                  :disabled="basicReadonly"
                  style="width: 200px"
                  placeholder="选择图标集"
                >
                  <a-select-option value="ant-design">
                    Ant Design
                  </a-select-option>
                  <a-select-option value="lucide">Lucide</a-select-option>
                  <a-select-option value="mdi">Material Design</a-select-option>
                  <a-select-option value="carbon">Carbon</a-select-option>
                  <a-select-option value="mdi-light">MDI Light</a-select-option>
                </a-select>
                <span class="ml-2 text-xs text-gray-400">
                  也可直接输入，如：lucide:shield
                </span>
              </div>
              <IconPicker
                v-model="formData.icon"
                :disabled="basicReadonly"
                :prefix="iconPrefix"
                placeholder="请选择图标"
                style="width: 100%"
              />
            </div>
          </a-form-item>

          <a-form-item label="展示方式" name="display_type">
            <a-radio-group
              v-model:value="formData.display_type"
              :disabled="basicReadonly"
            >
              <a-radio value="category">目录</a-radio>
              <a-radio value="page">页面</a-radio>
              <a-radio value="tab">选项卡</a-radio>
            </a-radio-group>
            <div class="mt-1 text-xs text-gray-400">
              <template v-if="formData.display_type === 'category'">
                目录仅用于左侧导航分组，不显示表单内容，不能有父级
              </template>
              <template v-else-if="formData.display_type === 'page'">
                页面显示表单内容，可作为目录的子级或选项卡的容器
              </template>
              <template v-else>
                选项卡聚合多个子页面，父级必须是页面类型
              </template>
            </div>
          </a-form-item>

          <a-form-item label="描述" name="description">
            <a-textarea
              v-model:value="formData.description"
              :disabled="basicReadonly"
              placeholder="分组描述"
              :rows="3"
            />
          </a-form-item>

          <a-form-item label="排序" name="sort">
            <a-input-number
              v-model:value="formData.sort"
              :disabled="basicReadonly"
              :min="0"
              class="w-full"
            />
          </a-form-item>

          <a-form-item label="状态" name="status">
            <a-radio-group
              v-model:value="formData.status"
              :disabled="basicReadonly"
            >
              <a-radio :value="1">启用</a-radio>
              <a-radio :value="0">禁用</a-radio>
            </a-radio-group>
          </a-form-item>
        </a-form>
      </a-tab-pane>

      <a-tab-pane key="sections" tab="页内分组" :disabled="!isEdit">
        <a-empty
          v-if="!isPageGroup"
          description="只有页面类型分组可以管理页内分组"
        />
        <template v-else>
          <a-form
            :model="sectionForm"
            :label-col="{ style: { width: '52px' } }"
            class="section-create-form"
          >
            <div class="section-create-grid">
              <a-form-item label="名称" class="mb-0">
                <a-input
                  v-model:value="sectionForm.name"
                  placeholder="页内分组名称"
                />
              </a-form-item>
              <a-form-item label="编码" class="mb-0">
                <a-input
                  v-model:value="sectionForm.code"
                  placeholder="如：basic"
                />
              </a-form-item>
              <a-form-item label="排序" class="mb-0">
                <a-input-number
                  v-model:value="sectionForm.sort"
                  :min="0"
                  class="w-full"
                />
              </a-form-item>
              <a-button
                type="primary"
                :loading="sectionSaving"
                class="section-create-button"
                @click="handleCreateSection"
                v-access:code="'SettingSectionCreate'"
              >
                新增
              </a-button>
            </div>
          </a-form>

          <a-table
            :columns="sectionColumns"
            :data-source="sectionItems"
            :loading="sectionLoading"
            :pagination="false"
            size="small"
            row-key="id"
            class="section-table"
            :scroll="{ x: 680 }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.dataIndex === 'name'">
                <a-input v-model:value="record.name" size="small" />
              </template>

              <template v-if="column.dataIndex === 'code'">
                <a-input
                  v-model:value="record.code"
                  :disabled="record.is_system === 1"
                  size="small"
                />
              </template>

              <template v-if="column.dataIndex === 'sort'">
                <a-input-number
                  v-model:value="record.sort"
                  :min="0"
                  class="w-full"
                  size="small"
                />
              </template>

              <template v-if="column.dataIndex === 'is_system'">
                <a-tag :color="record.is_system === 1 ? 'blue' : 'default'">
                  {{ record.is_system === 1 ? '系统内置' : '用户添加' }}
                </a-tag>
              </template>

              <template v-if="column.key === 'action'">
                <a-space>
                  <a-button
                    type="link"
                    size="small"
                    :loading="sectionSaving"
                    @click="handleUpdateSection(record)"
                    v-access:code="'SettingSectionUpdate'"
                  >
                    保存
                  </a-button>
                  <a-button
                    type="link"
                    danger
                    size="small"
                    :disabled="record.is_system === 1"
                    @click="handleDeleteSection(record)"
                    v-access:code="'SettingSectionDelete'"
                  >
                    删除
                  </a-button>
                </a-space>
              </template>
            </template>
          </a-table>
        </template>
      </a-tab-pane>
    </a-tabs>
    <template #footer>
      <div class="drawer-footer">
        <a-button @click="handleCancel">取消</a-button>
        <a-button
          v-if="activeTab === 'base' && !basicReadonly"
          type="primary"
          :loading="saving"
          @click="handleOk"
          v-access:code="isEdit ? 'SettingGroupUpdate' : 'SettingGroupCreate'"
        >
          确定
        </a-button>
      </div>
    </template>
  </a-drawer>
</template>

<style lang="css" scoped>
.drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.setting-group-drawer :deep(.ant-drawer-body) {
  padding-bottom: 12px;
}

.setting-group-drawer :deep(.ant-drawer-footer) {
  padding: 12px 24px;
  background: hsl(var(--background));
  border-top: 1px solid hsl(var(--border));
}

.group-drawer-tabs :deep(.ant-tabs-nav) {
  margin-bottom: 16px;
}

.section-create-form {
  padding: 12px;
  margin-bottom: 16px;
  background: transparent;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.section-create-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 140px auto;
  gap: 12px;
  align-items: flex-start;
}

.section-create-button {
  min-width: 72px;
}

.section-table {
  overflow: hidden;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

@media (max-width: 768px) {
  .section-create-grid {
    grid-template-columns: 1fr;
  }

  .section-create-button {
    width: 100%;
  }
}
</style>
