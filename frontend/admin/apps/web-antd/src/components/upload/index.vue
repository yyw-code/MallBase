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

interface Props {
  type?: 'file' | 'files' | 'image' | 'images';
  value?: FileInfo | FileInfo[] | string;
  disabled?: boolean;
  maxSize?: number;
  maxCount?: number;
  accept?: string[];
  showUploadList?: boolean;
  customUpload: (file: File) => Promise<void>;
  customRemove?: (index?: number) => Promise<void>;
}

const props = withDefaults(defineProps<Props>(), {
  type: 'image',
  disabled: false,
  showUploadList: true,
});

// ==================== 后端配置 ====================

const remoteConfig = ref<null | {
  acceptTypes: string[];
  fileIcons: Record<string, string>;
  maxCount: number;
  maxSize: number;
}>(null);

const loadRemoteConfig = async () => {
  try {
    const res = await getUploadConfigApi(props.type);
    if (res) {
      const iconMap: Record<string, string> = {};
      if (res.file_icons && Array.isArray(res.file_icons)) {
        for (const item of res.file_icons) {
          iconMap[item.ext] = item.icon;
        }
      }
      remoteConfig.value = {
        maxSize: res.max_size,
        maxCount: res.max_count,
        acceptTypes: res.accept_types,
        fileIcons: iconMap,
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

const getFileIcon = (name: string): string => {
  const ext = name.includes('.') ? name.split('.').pop()?.toLowerCase() : '';
  if (!ext) return '📎';
  if (remoteConfig.value?.fileIcons?.[ext]) {
    return remoteConfig.value.fileIcons[ext];
  }
  const fallback: Record<string, string> = {
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
  return fallback[ext] || '📎';
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

    <!-- 文件列表项（仅文件类型，图片用默认缩略图） -->
    <template v-if="!isImageType" #itemRender="{ file, actions }">
      <div class="upload-item">
        <span class="upload-item-icon">{{ getFileIcon(file.name) }}</span>
        <a
          v-if="file.url"
          :href="file.url"
          target="_blank"
          class="upload-item-name"
          @click.prevent="handlePreview(file)"
        >
          {{ file.name }}
        </a>
        <span v-else class="upload-item-name">{{ file.name }}</span>
        <span class="upload-item-actions">
          <span
            v-if="!disabled"
            class="upload-item-action upload-item-remove"
            title="删除文件"
            @click="actions.remove"
          >
            🗑
          </span>
        </span>
      </div>
    </template>
  </a-upload>
</template>

<style scoped>
.upload-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 4px 0;
  line-height: 1.5;
}

.upload-item-icon {
  flex-shrink: 0;
  font-size: 16px;
}

.upload-item-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 260px;
  font-size: 13px;
  color: #1677ff;
  cursor: pointer;
  text-decoration: none;
  transition: color 0.2s;
}

.upload-item-name:hover {
  color: #4096ff;
  text-decoration: underline;
}

.upload-item-actions {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-left: 8px;
  flex-shrink: 0;
}

.upload-item-action {
  cursor: pointer;
  font-size: 14px;
  color: #999;
  transition: color 0.2s;
  user-select: none;
}

.upload-item-remove:hover {
  color: #ff4d4f;
}

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
