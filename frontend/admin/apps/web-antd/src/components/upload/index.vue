<script lang="ts" setup>
import type { UploadFile, UploadProps } from 'ant-design-vue';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { getUploadConfig } from '#/config/upload';

/** 文件信息对象 */
export interface FileInfo {
  /** 存储路径（相对路径） */
  url: string;
  /** 完整预览/下载 URL（含域名） */
  full_url?: string;
  /** 原始文件名 */
  name: string;
}

/** 根据 URL 提取文件名 */
const extractFileName = (url: string): string => {
  if (!url) return '未知文件';
  const decoded = decodeURIComponent(url);
  const segments = decoded.split('/');
  const lastSegment = segments.pop() || '';
  const name = lastSegment.split('?')[0] || '';
  if (name.length > 40) {
    const ext = name.includes('.') ? `.${name.split('.').pop()}` : '';
    return `${name.slice(0, 30)}...${ext}`;
  }
  return name || '未知文件';
};

/** 根据文件扩展名获取图标 */
const getFileIcon = (name: string): string => {
  const ext = name.includes('.') ? name.split('.').pop()?.toLowerCase() : '';
  const iconMap: Record<string, string> = {
    csv: '📊',
    doc: '📝',
    docx: '📝',
    json: '📋',
    mp3: '🎵',
    mp4: '🎬',
    pdf: '📕',
    ppt: '📊',
    pptx: '📊',
    rar: '📦',
    sql: '🗃️',
    txt: '📄',
    xls: '📊',
    xlsx: '📊',
    zip: '📦',
  };
  return iconMap[ext || ''] || '📎';
};

interface Props {
  /** 上传类型：image, images, file, files */
  type?: 'file' | 'files' | 'image' | 'images';
  /** 显示的值：支持 URL 字符串或 FileInfo 对象 */
  value?: FileInfo | FileInfo[] | string;
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

// 上传配置
const uploadProps = computed<UploadProps>(() => {
  const cfg = config.value;
  return {
    name: 'file',
    maxCount: props.maxCount ?? cfg.maxCount,
    listType: isImageType.value ? 'picture-card' : 'text',
    showUploadList: props.showUploadList
      ? { showDownloadIcon: false, showPreviewIcon: true, showRemoveIcon: true }
      : false,
    beforeUpload: handleBeforeUpload,
    customRequest: handleCustomRequest,
    onRemove: handleRemove,
  };
});

/** 将 value 转为 UploadFile 列表 */
const buildFileList = (): UploadFile[] => {
  const val = props.value;
  if (!val) return [];

  if (Array.isArray(val)) {
    return val.map((item: FileInfo, index: number) => ({
      uid: `${index}`,
      name: item.name || extractFileName(item.url),
      status: 'done' as const,
      url: item.full_url || item.url,
    }));
  }

  if (typeof val === 'object') {
    return [
      {
        uid: '0',
        name: val.name || extractFileName(val.url),
        status: 'done' as const,
        url: val.full_url || val.url,
      },
    ];
  }

  // 兜底：纯字符串
  return [
    {
      uid: '0',
      name: extractFileName(val),
      status: 'done' as const,
      url: val,
    },
  ];
};

// 文件列表
const fileList = ref<UploadFile[]>([]);

watch(
  () => props.value,
  () => {
    fileList.value = buildFileList();
  },
  { immediate: true, deep: true },
);

// 上传前验证
const handleBeforeUpload = (file: File) => {
  const cfg = config.value;
  const maxSize = props.maxSize ?? cfg.maxSize;

  if (file.size / 1024 / 1024 > maxSize) {
    message.error(`文件大小不能超过 ${maxSize}MB`);
    return false;
  }

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
  onError?: (error: Error, file: File) => void;
  onSuccess?: (response: any, file: File) => void;
}) => {
  try {
    await props.customUpload(file as File);
    onSuccess?.({}, file as File);
  } catch (error) {
    console.error('上传失败:', error);
    message.error('上传失败');
    onError?.(error as Error, file as File);
  }
};

// 删除文件
const handleRemove = async (file: UploadFile) => {
  if (props.customRemove) {
    const index = fileList.value.findIndex((item) => item.uid === file.uid);
    await props.customRemove(index === -1 ? undefined : index);
  }
};

// 是否显示上传按钮
const showUploadButton = computed(() => {
  if (props.disabled) return false;
  const val = props.value;
  if (Array.isArray(val)) {
    const max = props.maxCount ?? config.value.maxCount;
    return val.length < max;
  }
  return !val;
});

/** 预览/下载文件 */
const handlePreview = (file: UploadFile) => {
  if (file.url) {
    window.open(file.url, '_blank');
  }
};
</script>

<template>
  <a-upload
    v-bind="uploadProps"
    :file-list="fileList"
    :disabled="disabled"
    @preview="handlePreview"
  >
    <!-- 图片类型上传按钮 -->
    <template v-if="isImageType && showUploadButton">
      <div>
        <span>+</span>
        <div style="margin-top: 8px">
          {{ type === 'image' ? '上传图片' : '添加图片' }}
        </div>
      </div>
    </template>

    <!-- 文件类型上传按钮 -->
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
:deep(.ant-upload-list) {
  margin-top: 8px;
}

:deep(.ant-upload-list-picture-card .ant-upload-list-item) {
  border-radius: 8px;
}

:deep(.ant-upload-list-picture-card .ant-upload-list-item-thumbnail) {
  object-fit: cover;
}

:deep(.ant-upload-list-text .ant-upload-list-item) {
  padding: 4px 8px;
  border-radius: 6px;
  transition: background-color 0.2s;
}

:deep(.ant-upload-list-text .ant-upload-list-item:hover) {
  background-color: #f5f5f5;
}
</style>
