<script lang="ts" setup>
import type { UploadFile, UploadProps } from 'ant-design-vue';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { getUploadConfig } from '#/config/upload';

interface Props {
  /** 上传类型：image, images, file, files */
  type?: 'file' | 'files' | 'image' | 'images';
  /** 显示的值（只读） */
  value?: string | string[];
  /** 是否只读 */
  disabled?: boolean;
  /** 自定义文件大小限制（MB） */
  maxSize?: number;
  /** 自定义最大上传数量 */
  maxCount?: number;
  /** 自定义接受的文件类型 */
  accept?: string[];
  /** 是否显示上传列表 */
  showUploadList?: boolean;
  /** 自定义上传方法（必须） */
  customUpload: (file: File) => Promise<void>;
  /** 自定义删除方法（可选） */
  customRemove?: (index?: number) => Promise<void>;
}

const props = withDefaults(defineProps<Props>(), {
  type: 'image',
  disabled: false,
  showUploadList: true,
});

// 获取配置
const config = computed(() => getUploadConfig(props.type));

// 判断是否为图片类型
const isImageType = computed(() => ['image', 'images'].includes(props.type));

// 判断是否为多选
const isMultiple = computed(() => ['files', 'images'].includes(props.type));

// 上传配置
const uploadProps = computed<UploadProps>(() => {
  const cfg = config.value;
  return {
    name: 'file',
    maxCount: props.maxCount ?? cfg.maxCount,
    // 图片类型使用 picture-card，文件类型使用 text
    listType: ['image', 'images'].includes(props.type) ? 'picture-card' : 'text',
    showUploadList: props.showUploadList,
    beforeUpload: handleBeforeUpload,
    customRequest: handleCustomRequest,
    onRemove: handleRemove,
  };
});

// 文件列表
const fileList = ref<UploadFile[]>([]);

// 监听 value 变化
watch(
  () => props.value,
  (val) => {
    if (Array.isArray(val)) {
      fileList.value = val.map((url, index) => ({
        uid: `${index}`,
        name: isImageType.value ? `image-${index}` : `file-${index}`,
        status: 'done' as const,
        url,
      }));
    } else if (val) {
      fileList.value = [
        {
          uid: '0',
          name: isImageType.value ? 'avatar' : 'file',
          status: 'done' as const,
          url: val,
        },
      ];
    } else {
      fileList.value = [];
    }
  },
  { immediate: true },
);

// 上传前验证
const handleBeforeUpload = (file: File) => {
  const cfg = config.value;
  const maxSize = props.maxSize ?? cfg.maxSize;

  // 文件大小验证
  if (file.size / 1024 / 1024 > maxSize) {
    message.error(`文件大小不能超过 ${maxSize}MB`);
    return false;
  }

  // 文件类型验证
  if (props.accept) {
    if (!props.accept.includes(file.type)) {
      message.error('不支持的文件类型');
      return false;
    }
  } else if (!cfg.acceptTypes.includes(file.type)) {
    message.error('不支持的文件类型');
    return false;
  }

  return true;
};

// 自定义上传
const handleCustomRequest = async ({
  file,
  onSuccess,
  onError,
}: {
  file: File;
  onSuccess?: (response: any, file: File) => void;
  onError?: (error: Error, file: File) => void;
}) => {
  try {
    // 调用父组件传入的上传方法
    await props.customUpload(file);
    // 上传成功，通知 Upload 组件
    onSuccess?.({}, file);
  } catch (error) {
    console.error('上传失败:', error);
    message.error('上传失败');
    // 上传失败，通知 Upload 组件
    onError?.(error as Error, file);
  }
};

// 删除文件
const handleRemove = async (file: UploadFile) => {
  if (props.customRemove) {
    // 如果有自定义删除方法，使用它
    const index = fileList.value.findIndex((item) => item.uid === file.uid);
    await props.customRemove(index === -1 ? undefined : index);
  }
  // 如果没有自定义删除方法，什么都不做（由父组件通过 value 控制）
};

// 是否显示上传按钮
const showUploadButton = computed(() => {
  if (props.disabled) return false;

  if (Array.isArray(props.value)) {
    const max = props.maxCount ?? config.value.maxCount;
    return props.value.length < max;
  }

  return !props.value;
});
</script>

<template>
  <a-upload
    v-bind="uploadProps"
    :file-list="fileList"
    :disabled="disabled"
  >
    <!-- 图片类型上传按钮（使用原生样式） -->
    <template v-if="isImageType && showUploadButton">
      <div>
        <span>+</span>
        <div style="margin-top: 8px">
          {{ type === 'image' ? '上传图片' : '添加图片' }}
        </div>
      </div>
    </template>

    <!-- 文件类型上传按钮（使用原生样式） -->
    <template v-else-if="showUploadButton">
      <a-button>
        <template #icon>
          <span>📤</span>
        </template>
        {{ type === 'file' ? '上传文件' : '添加文件' }}
      </a-button>
    </template>
  </a-upload>
</template>

<style scoped>
/* ========== 自定义文件列表项样式 ========== */
:deep(.ant-upload-list) {
  margin-top: 12px;
}

:deep(.ant-upload-list-picture-card .ant-upload-list-item) {
  border-radius: 8px;
}

:deep(.ant-upload-list-picture-card .ant-upload-list-item-thumbnail) {
  object-fit: cover;
}
</style>
