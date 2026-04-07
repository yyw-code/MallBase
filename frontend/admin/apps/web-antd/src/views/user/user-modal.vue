<script lang="ts" setup>
import type { ClientUserApi } from '#/api/client';
import type { FileInfo } from '#/components/upload';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { createClientUserApi, updateClientUserApi } from '#/api/client';
import Upload from '#/components/upload/index.vue';

defineOptions({ name: 'UserModal' });

const props = withDefaults(defineProps<Props>(), {
  visible: false,
  editData: null,
});

const emit = defineEmits<Emits>();

interface Props {
  visible?: boolean;
  editData?: ClientUserApi.UserItem | null;
}

interface Emits {
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}

const isEdit = computed(() => !!props.editData?.id);
const title = computed(() => (isEdit.value ? '编辑用户' : '新增用户'));

/* ---------------- 表单 ---------------- */
const formData = ref({
  mobile: '',
  email: '',
  password: '',
  confirm_password: '',
  avatar: undefined as FileInfo | string | undefined,
  nickname: '',
  real_name: '',
  gender: 0,
  birthday: undefined as string | undefined,
  status: 1,
  remark: '',
});

const formRef = ref();

/* ---------------- 验证规则 ---------------- */
const rules = {
  mobile: [
    {
      required: true,
      pattern: /^1[3-9]\d{9}$/,
      message: '手机号格式不正确',
      trigger: 'blur' as const,
    },
  ],
  email: [
    {
      type: 'email' as const,
      message: '邮箱格式不正确',
      trigger: 'blur' as const,
    },
  ],
  password: [
    {
      required: computed(() => !isEdit.value),
      message: '请输入密码',
    },
    { min: 6, max: 32, message: '密码长度为6-32位' },
  ],
  confirm_password: [
    {
      required: computed(() => !!formData.value.password),
      message: '请确认密码',
    },
    {
      validator: (_rule: unknown, value: string) => {
        if (value && value !== formData.value.password) {
          return Promise.reject('两次输入的密码不一致');
        }
        return Promise.resolve();
      },
      trigger: 'change' as const,
    },
  ],
  nickname: [{ required: true, max: 50, message: '昵称最多50个字符' }],
  real_name: [{ max: 50, message: '真实姓名最多50个字符' }],
  remark: [{ max: 500, message: '备注最多500个字符' }],
};

/* ---------------- 提交 ---------------- */
const loading = ref(false);

const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    loading.value = true;

    const data = { ...formData.value };
    delete data.confirm_password; // 删除确认密码字段

    // 处理头像字段
    if (data.avatar) {
      if (typeof data.avatar === 'object' && 'url' in data.avatar) {
        data.avatar = data.avatar.url;
      }
    } else {
      delete data.avatar;
    }

    // 如果没有填写密码，删除密码字段
    if (!data.password) {
      delete data.password;
    }

    if (isEdit.value) {
      await updateClientUserApi(props.editData!.id, data);
      message.success('更新成功');
    } else {
      await createClientUserApi(data);
      message.success('创建成功');
    }

    emit('success');
    handleClose();
  } catch (error: unknown) {
    // 验证失败或接口错误
    if (error && typeof error === 'object' && 'errorFields' in error) {
      // 表单验证失败，不处理
      return;
    }
    console.error('提交失败:', error);
  } finally {
    loading.value = false;
  }
};

/* ---------------- 关闭 ---------------- */
const handleClose = () => {
  formRef.value?.resetFields();
  emit('update:visible', false);
};

/* ---------------- 监听编辑数据 ---------------- */
watch(
  () => props.editData,
  (data) => {
    formData.value = data
      ? {
          mobile: data.mobile || '',
          email: data.email || '',
          password: '',
          confirm_password: '',
          avatar: data.avatar || undefined,
          nickname: data.nickname || '',
          real_name: data.real_name || '',
          gender: data.gender ?? 0,
          birthday: data.birthday || undefined,
          status: data.status ?? 1,
          remark: data.remark || '',
        }
      : {
          mobile: '',
          email: '',
          password: '',
          confirm_password: '',
          avatar: undefined,
          nickname: '',
          real_name: '',
          gender: 0,
          birthday: undefined,
          status: 1,
          remark: '',
        };
  },
  { immediate: true },
);
</script>

<template>
  <a-modal
    :open="visible"
    :title="title"
    :confirm-loading="loading"
    width="600px"
    @ok="handleSubmit"
    @cancel="handleClose"
  >
    <a-form
      ref="formRef"
      :model="formData"
      :rules="rules"
      :label-col="{ style: { width: '100px' } }"
      class="pt-4"
    >
      <!-- 基本信息 -->
      <a-form-item label="昵称" name="nickname">
        <a-input
          v-model:value="formData.nickname"
          placeholder="请输入昵称"
          allow-clear
        />
      </a-form-item>

      <a-form-item label="真实姓名" name="real_name">
        <a-input
          v-model:value="formData.real_name"
          placeholder="请输入真实姓名"
          allow-clear
        />
      </a-form-item>
      <!-- 头像 -->
      <a-form-item label="头像">
        <Upload v-model:value="formData.avatar" type="image" module="user" />
      </a-form-item>

      <!-- 手机号和邮箱 -->
      <a-form-item label="手机号" name="mobile">
        <a-input
          v-model:value="formData.mobile"
          placeholder="请输入手机号"
          allow-clear
        />
      </a-form-item>

      <a-form-item label="邮箱" name="email">
        <a-input
          v-model:value="formData.email"
          placeholder="请输入邮箱"
          allow-clear
        />
      </a-form-item>

      <!-- 密码 -->
      <a-form-item v-if="!isEdit" label="密码" name="password">
        <a-input-password
          v-model:value="formData.password"
          placeholder="请输入密码（6-32位）"
          allow-clear
        />
      </a-form-item>

      <a-form-item v-if="!isEdit" label="确认密码" name="confirm_password">
        <a-input-password
          v-model:value="formData.confirm_password"
          placeholder="请再次输入密码"
          allow-clear
        />
      </a-form-item>

      <a-form-item label="性别" name="gender">
        <a-radio-group v-model:value="formData.gender">
          <a-radio :value="0">未知</a-radio>
          <a-radio :value="1">男</a-radio>
          <a-radio :value="2">女</a-radio>
        </a-radio-group>
      </a-form-item>

      <a-form-item label="生日" name="birthday">
        <a-date-picker
          v-model:value="formData.birthday"
          placeholder="请选择生日"
          style="width: 100%"
          value-format="YYYY-MM-DD"
          allow-clear
        />
      </a-form-item>

      <a-form-item label="状态" name="status">
        <a-radio-group v-model:value="formData.status">
          <a-radio :value="1">启用</a-radio>
          <a-radio :value="0">禁用</a-radio>
        </a-radio-group>
      </a-form-item>

      <a-form-item label="备注" name="remark">
        <a-textarea
          v-model:value="formData.remark"
          placeholder="请输入备注"
          :rows="3"
          allow-clear
        />
      </a-form-item>
    </a-form>
  </a-modal>
</template>
