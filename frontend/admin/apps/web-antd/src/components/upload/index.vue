<script lang="ts" setup>
import type { UploadFile, UploadProps } from 'ant-design-vue';

import { computed, onMounted, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { getUploadConfigApi } from '#/api/core/upload';

/** 文件信息对象 */
export interface FileInfo {
  url: string;
  full_url?: string;
  name: string;
}

/**
 * Upload 组件属性
 *
 * @example
 * <Upload type="image" :value="fileList" :custom-upload="handleUpload" :custom-remove="handleRemove" />
 */
interface Props {
  /** 上传类型：image=单图 | images=多图 | file=单文件 | files=多文件，默认 image */
  type?: 'file' | 'files' | 'image' | 'images';
  /** 已上传的文件值，支持 FileInfo 对象、数组或 URL 字符串 */
  value?: FileInfo | FileInfo[] | string;
  /** 是否禁用上传，默认 false */
  disabled?: boolean;
  /** 文件大小上限（MB），不传则从后端获取，兜底 5MB */
  maxSize?: number;
  /** 最大上传数量，不传则从后端获取，image=1 / images=9 / file=1 / files=5 */
  maxCount?: number;
  /** 允许的 MIME 类型数组，如 ['image/png']，不传则从后端获取 */
  accept?: string[];
  /** 是否显示已上传文件列表，默认 true */
  showUploadList?: boolean;
  /** 自定义上传方法（必填），接收 File，需调用上传接口 */
  customUpload: (file: File) => Promise<void>;
  /** 自定义删除方法，接收被删除文件的索引 */
  customRemove?: (index?: number) => Promise<void>;
}

const props = withDefaults(defineProps<Props>(), {
  type: 'image',
  value: undefined,
  disabled: false,
  maxCount: undefined,
  accept: undefined,
  maxSize: undefined,
  showUploadList: true,
  customRemove: undefined,
});

// ==================== 后端配置 ====================

const remoteConfig = ref<null | {
  acceptTypes: string[];
  maxCount: number;
  maxSize: number;
}>(null);

const loadRemoteConfig = async () => {
  // 如果所有配置都已通过 props 传入，则跳过后端接口调用
  if (
    props.maxSize !== undefined &&
    props.maxCount !== undefined &&
    props.accept !== undefined
  ) {
    return;
  }
  try {
    const res = await getUploadConfigApi(props.type);
    if (res) {
      remoteConfig.value = {
        maxSize: res.max_size,
        maxCount: res.max_count,
        acceptTypes: res.accept_types,
      };
    }
  } catch (error) {
    console.warn('获取上传配置失败，使用前端兜底配置:', error);
  }
};

onMounted(loadRemoteConfig);

// ==================== 合并后的配置 ====================

const fallbackConfig: Record<string, { maxCount: number; maxSize: number }> = {
  file: { maxCount: 1, maxSize: 10 },
  files: { maxCount: 5, maxSize: 10 },
  image: { maxCount: 1, maxSize: 2 },
  images: { maxCount: 9, maxSize: 5 },
};

const effectiveMaxSize = computed(() => {
  if (props.maxSize !== undefined) return props.maxSize;
  return (
    remoteConfig.value?.maxSize ?? fallbackConfig[props.type]?.maxSize ?? 5
  );
});

const effectiveMaxCount = computed(() => {
  if (props.maxCount !== undefined) return props.maxCount;
  return (
    remoteConfig.value?.maxCount ?? fallbackConfig[props.type]?.maxCount ?? 1
  );
});

const effectiveAcceptTypes = computed(() => {
  if (props.accept) return props.accept;
  return remoteConfig.value?.acceptTypes ?? [];
});

const isImageType = computed(() => ['image', 'images'].includes(props.type));

// ==================== 工具函数 ====================

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

// ==================== 上传配置 ====================

const uploadProps = computed<UploadProps>(() => ({
  name: 'file',
  maxCount: effectiveMaxCount.value,
  listType: isImageType.value ? 'picture-card' : 'text',
  showUploadList: props.showUploadList
    ? { showDownloadIcon: false, showPreviewIcon: true, showRemoveIcon: true }
    : false,
  beforeUpload: handleBeforeUpload,
  customRequest: handleCustomRequest,
  onRemove: handleRemove,
}));

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

  return [
    {
      uid: '0',
      name: extractFileName(val),
      status: 'done' as const,
      url: val,
    },
  ];
};

const fileList = ref<UploadFile[]>([]);

watch(
  () => props.value,
  () => {
    fileList.value = buildFileList();
  },
  { immediate: true, deep: true },
);

// ==================== 事件处理 ====================

const handleBeforeUpload = (file: File) => {
  const maxSize = effectiveMaxSize.value;
  if (file.size / 1024 / 1024 > maxSize) {
    message.error(`文件大小不能超过 ${maxSize}MB`);
    return false;
  }
  const acceptTypes = effectiveAcceptTypes.value;
  if (acceptTypes.length > 0 && !acceptTypes.includes(file.type)) {
    message.error('不支持的文件类型');
    return false;
  }
  return true;
};

const handleCustomRequest = async (options: any) => {
  const { file, onSuccess, onError } = options;
  try {
    await props.customUpload(file as File);
    onSuccess?.({}, file);
  } catch (error) {
    console.error('上传失败:', error);
    message.error('上传失败');
    onError?.(error);
  }
};

const handleRemove = async (file: UploadFile) => {
  if (props.customRemove) {
    const index = fileList.value.findIndex((item) => item.uid === file.uid);
    await props.customRemove(index === -1 ? undefined : index);
  }
};

const showUploadButton = computed(() => {
  if (props.disabled) return false;
  const val = props.value;
  if (Array.isArray(val)) {
    return val.length < effectiveMaxCount.value;
  }
  return !val;
});

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
    <!-- 图片类型：缩略图卡片 -->
    <template v-if="isImageType && showUploadButton">
      <div>
        <span>+</span>
        <div style="margin-top: 8px">
          {{ type === 'image' ? '上传图片' : '添加图片' }}
        </div>
      </div>
    </template>

    <!-- 文件类型：按钮上传 -->
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
