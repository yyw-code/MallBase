<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import { computed, ref, watch } from 'vue';

import { IconPicker } from '@vben/common-ui';

import { message } from 'ant-design-vue';

import {
  createSettingGroupApi,
  getSettingGroupAllApi,
  updateSettingGroupApi,
} from '#/api/setting';
import { getPermissionTreeApi } from '#/api/system/permission';

const props = defineProps<{
  editData?: null | SettingApi.SettingGroup;
  visible: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}>();

const isEdit = computed(() => !!props.editData);
const modalTitle = computed(() => (isEdit.value ? '编辑分组' : '新增分组'));
const saving = ref(false);

// 图标集前缀
const iconPrefix = ref('ant-design');

// 表单数据
const formData = ref({
  parent_id: 0 as number,
  menu_parent_permission_id: undefined as number | undefined,
  name: '',
  code: '',
  icon: '',
  description: '',
  sort: 0,
  status: 1,
});

// 父分组树形数据
const groupTreeData = ref<SettingApi.SettingGroup[]>([]);

// 父菜单权限树形数据（type=1 的菜单权限）
const permissionTreeData = ref<any[]>([]);

// 是否为顶级分组（parent_id === 0 时需要选择父菜单权限）
const isTopLevel = computed(() => formData.value.parent_id === 0);

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
    const tree = await getPermissionTreeApi({ type: 1 });
    permissionTreeData.value = tree;
  } catch {
    console.error('加载权限树失败');
  }
};

/** 树形数据转换为 TreeSelect 格式（带"顶级"选项） */
const convertGroupToTreeSelect = (data: SettingApi.SettingGroup[]): any[] => {
  return [
    {
      title: '顶级',
      value: 0,
      key: 0,
    },
    ...data.map((item) => ({
      title: item.name,
      value: item.id,
      key: item.id,
      children: item.children
        ? convertGroupToTreeSelect(item.children)
        : undefined,
    })),
  ];
};

/** 权限树转换为 TreeSelect 格式（带"顶级"选项） */
const convertPermissionToTreeSelect = (data: any[]): any[] => {
  return [
    {
      title: '顶级',
      value: 0,
      key: 0,
    },
    ...data.map((item) => ({
      title: item.name,
      value: item.id,
      key: item.id,
      children: item.children
        ? convertPermissionToTreeSelect(item.children)
        : undefined,
    })),
  ];
};

/** 打开弹窗时初始化 */
watch(
  () => props.visible,
  (val) => {
    if (val) {
      loadGroupTree();
      loadPermissionTree();

      formData.value = props.editData
        ? {
            parent_id: props.editData.parent_id,
            menu_parent_permission_id: undefined,
            name: props.editData.name,
            code: props.editData.code,
            icon: props.editData.icon || '',
            description: props.editData.description || '',
            sort: props.editData.sort,
            status: props.editData.status,
          }
        : {
            parent_id: 0,
            menu_parent_permission_id: undefined,
            name: '',
            code: '',
            icon: '',
            description: '',
            sort: 0,
            status: 1,
          };
    }
  },
);

/** 关闭弹窗 */
const handleCancel = () => {
  emit('update:visible', false);
};

/** 提交 */
const handleOk = async () => {
  if (!formData.value.name) {
    message.warning('请输入分组名称');
    return;
  }
  if (!formData.value.code) {
    message.warning('请输入分组编码');
    return;
  }

  saving.value = true;
  try {
    if (isEdit.value && props.editData) {
      await updateSettingGroupApi(props.editData.id, formData.value);
      message.success('更新成功');
    } else {
      await createSettingGroupApi(formData.value);
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
    <a-form :label-col="{ span: 6 }" :wrapper-col="{ span: 16 }" class="mt-4">
      <a-form-item label="父分组" name="parent_id">
        <a-tree-select
          v-model:value="formData.parent_id"
          :tree-data="convertGroupToTreeSelect(groupTreeData)"
          placeholder="请选择父分组"
          tree-default-expand-all
        />
      </a-form-item>

      <a-form-item
        v-if="isTopLevel"
        label="父菜单权限"
        name="menu_parent_permission_id"
      >
        <a-tree-select
          v-model:value="formData.menu_parent_permission_id"
          :tree-data="convertPermissionToTreeSelect(permissionTreeData)"
          placeholder="请选择父菜单"
          tree-default-expand-all
        />
        <div class="mt-1 text-xs text-gray-400">
          顶级分组需要选择在菜单树中的挂载位置
        </div>
      </a-form-item>

      <a-form-item label="分组名称" name="name" required>
        <a-input v-model:value="formData.name" placeholder="如：微信设置" />
      </a-form-item>

      <a-form-item label="分组编码" name="code" required>
        <a-input
          v-model:value="formData.code"
          placeholder="如：wechat（英文+数字）"
          :disabled="isEdit"
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
