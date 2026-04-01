<script lang="ts" setup>
import type { FormInstance, Rule } from 'ant-design-vue/es/form';

import type { SettingApi } from '#/api/setting';

import { computed, reactive, ref, watch } from 'vue';

import { IconPicker } from '@vben/common-ui';

import { message } from 'ant-design-vue';

import {
  createSettingGroupApi,
  getSettingGroupAllApi,
  getSettingGroupInfoApi,
  getSettingPermissionTreeApi,
  updateSettingGroupApi,
} from '#/api/setting';

const props = defineProps<{
  editData?: null | SettingApi.SettingGroup;
  visible: boolean;
}>();

const emit = defineEmits<{
  (e: 'success'): void;
  (e: 'update:visible', value: boolean): void;
}>();

const isEdit = computed(() => !!props.editData);
const modalTitle = computed(() => (isEdit.value ? '编辑分组' : '新增分组'));
const saving = ref(false);

// 图标集前缀
const iconPrefix = ref('ant-design');

// 表单数据
const formData = reactive({
  parent_id: 0 as number,
  menu_parent_permission_id: undefined as number | undefined,
  name: '',
  code: '',
  icon: '',
  description: '',
  sort: 0,
  status: 1,
});

// 表单 ref
const formRef = ref<FormInstance>();

// 表单验证规则
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

// 父分组树形数据
const groupTreeData = ref<SettingApi.SettingGroup[]>([]);

// 父菜单权限树形数据（type=1 的菜单权限）
const permissionTreeData = ref<any[]>([]);

/** 加载父分组数据 */
const loadGroupTree = async () => {
  try {
    groupTreeData.value = await getSettingGroupAllApi();
  } catch {
    console.error('加载分组树失败');
  }
};

/** 加载菜单权限树 */
const loadPermissionTree = async () => {
  try {
    permissionTreeData.value = await getSettingPermissionTreeApi();
  } catch {
    console.error('加载权限树失败');
  }
};

/** 分组树转换为 TreeSelect 格式（只在根级加一个"顶级"选项） */
const groupTreeSelectData = computed(() => {
  const convert = (items: SettingApi.SettingGroup[]): any[] =>
    items.map((item) => ({
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

/** 权限树转换为 TreeSelect 格式（只在根级加一个"顶级"选项） */
const permissionTreeSelectData = computed(() => {
  const convert = (items: any[]): any[] =>
    items.map((item) => ({
      title: item.name,
      value: item.id,
      key: item.id,
      children: item.children ? convert(item.children) : undefined,
    }));

  return [
    { title: '顶级（无父菜单）', value: 0, key: 0 },
    ...convert(permissionTreeData.value),
  ];
});

/** 打开弹窗时初始化 */
watch(
  () => props.visible,
  async (val) => {
    if (val) {
      loadGroupTree();
      loadPermissionTree();
      formRef.value?.clearValidate();

      if (props.editData) {
        // 通过 info 接口获取最新详情数据回显
        try {
          const detail = await getSettingGroupInfoApi(props.editData.id);
          Object.assign(formData, {
            parent_id: detail.parent_id,
            menu_parent_permission_id: detail.permission_id,
            name: detail.name,
            code: detail.code,
            icon: detail.icon || '',
            description: detail.description || '',
            sort: detail.sort,
            status: detail.status,
          });
        } catch (error) {
          console.error('获取分组详情失败:', error);
          message.error('获取分组详情失败');
        }
      } else {
        Object.assign(formData, {
          parent_id: 0,
          menu_parent_permission_id: undefined,
          name: '',
          code: '',
          icon: '',
          description: '',
          sort: 0,
          status: 1,
        });
      }
    }
  },
);

/** 关闭弹窗 */
const handleCancel = () => {
  emit('update:visible', false);
};

/** 提交 */
const handleOk = async () => {
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
  <a-modal
    :open="visible"
    :title="modalTitle"
    :confirm-loading="saving"
    width="600px"
    @ok="handleOk"
    @cancel="handleCancel"
  >
    <a-form
      ref="formRef"
      :model="formData"
      :rules="formRules"
      :label-col="{ span: 6 }"
      :wrapper-col="{ span: 16 }"
      class="mt-4"
    >
      <a-form-item label="父分组" name="parent_id">
        <a-tree-select
          v-model:value="formData.parent_id"
          :tree-data="groupTreeSelectData"
          placeholder="请选择父分组"
          tree-default-expand-all
        />
      </a-form-item>

      <a-form-item label="父菜单权限" name="menu_parent_permission_id">
        <a-tree-select
          v-model:value="formData.menu_parent_permission_id"
          :tree-data="permissionTreeSelectData"
          placeholder="请选择父菜单"
          tree-default-expand-all
        />
        <div class="mt-1 text-xs text-gray-400">
          选择在菜单树中的挂载位置，决定左侧菜单中显示的位置
        </div>
      </a-form-item>

      <a-form-item label="分组名称" name="name">
        <a-input v-model:value="formData.name" placeholder="如：微信设置" />
      </a-form-item>

      <a-form-item label="分组编码" name="code">
        <a-input
          v-model:value="formData.code"
          placeholder="如：wechat（英文+数字）"
        />
      </a-form-item>

      <a-form-item label="图标" name="icon">
        <div class="flex w-full flex-col">
          <div class="mb-2">
            <a-select
              v-model:value="iconPrefix"
              style="width: 200px"
              placeholder="选择图标集"
            >
              <a-select-option value="ant-design"> Ant Design </a-select-option>
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
            :prefix="iconPrefix"
            placeholder="请选择图标"
            style="width: 100%"
          />
        </div>
      </a-form-item>

      <a-form-item label="描述" name="description">
        <a-textarea
          v-model:value="formData.description"
          placeholder="分组描述"
          :rows="3"
        />
      </a-form-item>

      <a-form-item label="排序" name="sort">
        <a-input-number v-model:value="formData.sort" :min="0" class="w-full" />
      </a-form-item>

      <a-form-item label="状态" name="status">
        <a-radio-group v-model:value="formData.status">
          <a-radio :value="1">启用</a-radio>
          <a-radio :value="0">禁用</a-radio>
        </a-radio-group>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
