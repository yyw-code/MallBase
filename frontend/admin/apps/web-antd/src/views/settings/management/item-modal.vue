<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { createSettingItemApi, updateSettingItemApi } from '#/api/setting';

const props = defineProps<{
  editData?: null | SettingApi.SettingItem;
  groupId: number;
  visible: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}>();

const isEdit = computed(() => !!props.editData);
const modalTitle = computed(() => (isEdit.value ? '编辑设置项' : '新增设置项'));
const saving = ref(false);

/** 支持的表单类型列表 */
const typeOptions = [
  { label: '单行文本 (input)', value: 'input' },
  { label: '多行文本 (textarea)', value: 'textarea' },
  { label: '数字 (number)', value: 'number' },
  { label: '密码 (password)', value: 'password' },
  { label: '开关 (switch)', value: 'switch' },
  { label: '单选 (radio)', value: 'radio' },
  { label: '多选 (checkbox)', value: 'checkbox' },
  { label: '下拉选择 (select)', value: 'select' },
  { label: '图片 (image)', value: 'image' },
  { label: '多图 (images)', value: 'images' },
  { label: '文件 (file)', value: 'file' },
  { label: '多文件 (files)', value: 'files' },
  { label: '富文本 (editor)', value: 'editor' },
  { label: 'JSON', value: 'json' },
];

/** 需要配置选项的类型 */
const needOptions = computed(() =>
  ['checkbox', 'radio', 'select'].includes(formData.value.type),
);

// 表单数据
const formData = ref({
  name: '',
  code: '',
  value: '',
  type: 'input',
  options: '' as string,
  placeholder: '',
  remark: '',
  sort: 0,
  is_required: 0,
});

/** 打开弹窗时初始化 */
watch(
  () => props.visible,
  (val) => {
    if (val) {
      if (props.editData) {
        const item = props.editData;
        formData.value = {
          name: item.name,
          code: item.code,
          value: item.value || '',
          type: item.type,
          // options 可能是数组或字符串，统一转为字符串编辑
          options:
            typeof item.options === 'string'
              ? item.options || ''
              : item.options
                ? JSON.stringify(item.options, null, 2)
                : '',
          placeholder: item.placeholder || '',
          remark: item.remark || '',
          sort: item.sort,
          is_required: item.is_required,
        };
      } else {
        formData.value = {
          name: '',
          code: '',
          value: '',
          type: 'input',
          options: '',
          placeholder: '',
          remark: '',
          sort: 0,
          is_required: 0,
        };
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
  if (!formData.value.name) {
    message.warning('请输入设置项名称');
    return;
  }
  if (!formData.value.code) {
    message.warning('请输入设置项编码');
    return;
  }

  // 校验 options 格式
  if (needOptions.value && formData.value.options) {
    try {
      JSON.parse(formData.value.options);
    } catch {
      message.warning('选项 JSON 格式不正确');
      return;
    }
  }

  saving.value = true;
  try {
    const submitData: any = {
      ...formData.value,
      // 将空字符串的 options 设为 null
      options: formData.value.options || null,
    };

    if (isEdit.value && props.editData) {
      await updateSettingItemApi(props.editData.id, submitData);
      message.success('更新成功');
    } else {
      submitData.group_id = props.groupId;
      await createSettingItemApi(submitData);
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
    <a-form :label-col="{ span: 5 }" :wrapper-col="{ span: 18 }" class="mt-4">
      <a-form-item label="名称" name="name" required>
        <a-input v-model:value="formData.name" placeholder="如：AppID" />
      </a-form-item>

      <a-form-item label="编码" name="code" required>
        <a-input
          v-model:value="formData.code"
          placeholder="如：wechat_appid"
          :disabled="isEdit"
        />
      </a-form-item>

      <a-form-item label="表单类型" name="type" required>
        <a-select
          v-model:value="formData.type"
          :options="typeOptions"
          placeholder="请选择表单类型"
        />
      </a-form-item>

      <a-form-item label="默认值" name="value">
        <a-input v-model:value="formData.value" placeholder="默认值" />
      </a-form-item>

      <a-form-item v-if="needOptions" label="选项" name="options">
        <a-textarea
          v-model:value="formData.options"
          placeholder='[{"label":"启用","value":"1"},{"label":"禁用","value":"0"}]'
          :rows="4"
          class="font-mono"
        />
        <div class="mt-1 text-xs text-gray-400">请输入 JSON 数组格式</div>
      </a-form-item>

      <a-form-item label="占位提示" name="placeholder">
        <a-input
          v-model:value="formData.placeholder"
          placeholder="输入框提示文字"
        />
      </a-form-item>

      <a-form-item label="备注" name="remark">
        <a-textarea
          v-model:value="formData.remark"
          placeholder="设置项说明"
          :rows="2"
        />
      </a-form-item>

      <a-form-item label="排序" name="sort">
        <a-input-number v-model:value="formData.sort" :min="0" class="w-full" />
      </a-form-item>

      <a-form-item label="必填" name="is_required">
        <a-switch
          :checked="formData.is_required === 1"
          @change="(val: boolean) => (formData.is_required = val ? 1 : 0)"
        />
      </a-form-item>
    </a-form>
  </a-modal>
</template>
