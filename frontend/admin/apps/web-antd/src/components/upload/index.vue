<script lang="ts" setup>
import type { UploadFile, UploadProps } from 'ant-design-vue';

import type { UploadApi, UploadParams } from '#/api/core/upload';

import { computed, onMounted, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { uploadBatchApi, uploadSingleApi } from '#/api/core/upload';
import { getUploadConfigCached } from '#/api/core/upload-config-cache';

/** 文件信息对象 */
export interface FileInfo {
  url: string;
  full_url?: string;
  name: string;
}

/**
 * Upload 组件
 *
 * @example
 * <Upload type="image" :value="fileList" module="dynamic_form" :related-id="123" />
 * <Upload type="image" :value="fileList" :custom-upload="handleUpload" />
 */
interface Props {
  /** 上传类型：image=单图 | images=多图 | file=单文件 | files=多文件 | video=单视频 | videos=多视频，默认 image */
  type?: 'file' | 'files' | 'image' | 'images' | 'video' | 'videos';
  /** 已上传的文件值 */
  value?: FileInfo | FileInfo[] | string;
  /** 是否禁用上传，默认 false */
  disabled?: boolean;
  /** 文件大小上限（MB），不传则从后端获取 */
  maxSize?: number;
  /** 最大上传数量，不传则从后端获取 */
  maxCount?: number;
  /** 允许的 MIME 类型数组，不传则从后端获取 */
  accept?: string[];
  /** 是否显示已上传文件列表，默认 true */
  showUploadList?: boolean;
  /**
   * 模块名：dynamic_form / admin 等
   * 不传 customUpload 时，组件内部调用 uploadSingleApi 会带上此参数
   */
  module?: string;
  /**
   * 关联 ID（如设置项 ID）
   * 配合 module 使用
   */
  relatedId?: number | string;
  /** 是否支持上传文件夹，默认根据 type 自动判断（files 类型为 true），传了以传的为准 */
  directory?: boolean;
  /** 是否支持多选，默认根据 type 自动判断（files/images 为 true），传了以传的为准 */
  multiple?: boolean;
  /**
   * 自定义上传方法（可选）
   * 不传则组件内部自动调用 uploadSingleApi
   */
  customUpload?: (
    file: File,
    params: UploadParams,
  ) => Promise<FileInfo | undefined>;
  /** 自定义删除方法 */
  customRemove?: (index?: number) => Promise<void>;
  /**
   * 私有/证书模式：文件不进 public 目录，没有外网可访问 URL。
   * 开启后：不渲染预览/打开链接，跳过基于 MIME 的前端校验，
   * 上传响应里的 full_url 强制忽略，文件名保留用户原始选择的文件名。
   * 配合 module='cert' 使用。
   */
  secure?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  type: 'image',
  value: undefined,
  disabled: false,
  maxCount: undefined,
  accept: undefined,
  maxSize: undefined,
  showUploadList: true,
  module: undefined,
  relatedId: undefined,
  directory: undefined,
  multiple: undefined,
  customUpload: undefined,
  customRemove: undefined,
  secure: false,
});

const emit = defineEmits<{
  (e: 'update:value', value: any): void;
}>();

// ==================== 批量上传队列（多文件类型使用 uploadBatchApi） ====================

interface BatchQueueItem {
  file: File;
  onSuccess: (response: any, file: File) => void;
  onError: (error: any) => void;
}

/** 批量上传待处理队列 */
const batchQueue: BatchQueueItem[] = [];
let batchTimer: null | ReturnType<typeof setTimeout> = null;
/** 正在上传/排队中的文件数量（用于 beforeUpload 计数校验） */
const pendingCount = ref(0);

// ==================== 后端配置 ====================

const remoteConfig = ref<null | {
  acceptTypes: string[];
  maxCount: number;
  maxSize: number;
  warnings: string[];
}>(null);

const loadRemoteConfig = async () => {
  // 私有/证书模式：accept/maxSize 全部走 dynamic-form 传入的 props，
  // 后端 cert module 没有公开的上传规则配置接口，不需要远程拉取。
  if (props.secure) {
    return;
  }
  if (
    props.maxSize !== undefined &&
    props.maxCount !== undefined &&
    props.accept !== undefined
  ) {
    return;
  }
  try {
    const res = await getUploadConfigCached(props.type);
    if (res) {
      remoteConfig.value = {
        maxSize: res.max_size,
        maxCount: res.max_count,
        acceptTypes: res.accept_types,
        warnings: res.warnings || [],
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
  video: { maxCount: 1, maxSize: 200 },
  videos: { maxCount: 5, maxSize: 200 },
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

const effectiveAccept = computed(() => effectiveAcceptTypes.value.join(','));
const uploadWarnings = computed(() => remoteConfig.value?.warnings ?? []);

const isImageType = computed(() => ['image', 'images'].includes(props.type));
const isVideoType = computed(() => ['video', 'videos'].includes(props.type));

/** 多选：files/images 自动开启，传了 multiple 以传的为准 */
const effectiveMultiple = computed(() => {
  if (props.multiple !== undefined) return props.multiple;
  return ['files', 'images', 'videos'].includes(props.type);
});

/** 文件夹上传：默认关闭，传了 directory 以传的为准 */
const effectiveDirectory = computed(() => {
  if (props.directory !== undefined) return props.directory;
  return false;
});

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

const toFullUrl = (path: string) => {
  if (!path) return '';
  if (path.startsWith('http://') || path.startsWith('https://')) return path;
  const base = import.meta.env.VITE_GLOB_API_URL || '';
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  if (
    normalizedPath.startsWith('/uploads/') ||
    normalizedPath.startsWith('/static/')
  ) {
    try {
      return `${new URL(base, window.location.origin).origin}${normalizedPath}`;
    } catch {
      return normalizedPath;
    }
  }
  return `${base.replace(/\/$/, '')}${normalizedPath}`;
};

// ==================== 上传配置 ====================

const uploadProps = computed<UploadProps>(() => ({
  name: 'file',
  maxCount: effectiveMaxCount.value,
  accept: effectiveAccept.value || undefined,
  // 私有/证书模式：强制 text 列表（无缩略图），无预览图标（没有公开 URL）
  listType:
    props.secure
      ? 'text'
      : isImageType.value || isVideoType.value
        ? 'picture-card'
        : 'text',
  showUploadList: props.showUploadList
    ? {
        showDownloadIcon: false,
        showPreviewIcon: !props.secure,
        showRemoveIcon: true,
      }
    : false,
  directory: effectiveDirectory.value || undefined,
  multiple: effectiveMultiple.value,
  beforeUpload: handleBeforeUpload,
  customRequest: handleCustomRequest,
  onRemove: handleRemove,
}));

const buildFileList = (): UploadFile[] => {
  const val = props.value;
  if (!val) return [];

  // 私有/证书模式：不把 url 写到 UploadFile 上，避免 antd 渲染成可点击链接（链接 404）
  const resolveItemUrl = (item: FileInfo): string | undefined => {
    if (props.secure) return undefined;
    const url = item.full_url || item.url;
    return url ? toFullUrl(url) : undefined;
  };

  if (Array.isArray(val)) {
    return val.map((item: FileInfo, index: number) => ({
      uid: `${index}`,
      name: item.name || extractFileName(item.url),
      status: 'done' as const,
      url: resolveItemUrl(item),
    }));
  }

  if (typeof val === 'object') {
    return [
      {
        uid: '0',
        name: (val as FileInfo).name || extractFileName((val as FileInfo).url),
        status: 'done' as const,
        url: resolveItemUrl(val as FileInfo),
      },
    ];
  }

  return [
    {
      uid: '0',
      name: extractFileName(val),
      status: 'done' as const,
      url: props.secure ? undefined : toFullUrl(val),
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
  // 数量校验：已上传 + 正在上传/排队中 不能超过 maxCount
  const maxCount = effectiveMaxCount.value;
  const currentCount = fileList.value.length + pendingCount.value;
  if (currentCount >= maxCount) {
    message.error(`最多上传 ${maxCount} 个文件`);
    return false;
  }

  // 大小校验
  const maxSize = effectiveMaxSize.value;
  if (file.size / 1024 / 1024 > maxSize) {
    message.error(`文件大小不能超过 ${maxSize}MB`);
    return false;
  }

  // 类型校验
  const acceptTypes = effectiveAcceptTypes.value;
  if (acceptTypes.length > 0) {
    if (props.secure) {
      // 私有/证书模式：accept 是扩展名列表（如 ['.pem', '.key']），按扩展名匹配
      const ext = (file.name.split('.').pop() || '').toLowerCase();
      const allowed = acceptTypes.map((t) =>
        t.startsWith('.') ? t.slice(1).toLowerCase() : t.toLowerCase(),
      );
      if (ext === '' || !allowed.includes(ext)) {
        message.error(`仅支持 ${acceptTypes.join('、')} 文件`);
        return false;
      }
    } else if (!acceptTypes.includes(file.type)) {
      message.error('不支持的文件类型');
      return false;
    }
  }

  // 校验通过，计入待上传数
  pendingCount.value++;
  return true;
};

/** 构建 UploadParams */
const buildUploadParams = (): UploadParams => ({
  type: props.type,
  module: props.module,
  related_id: props.relatedId,
});

/** 单文件上传（image/file 类型） */
const executeSingleUpload = async (
  file: File,
  onSuccess: (response: any, file: File) => void,
  onError: (error: any) => void,
) => {
  try {
    const params = buildUploadParams();
    let fileInfo: FileInfo | undefined;

    if (props.customUpload) {
      fileInfo = await props.customUpload(file, params);
    } else {
      const res = await uploadSingleApi(file, params);
      if (res) {
        fileInfo = {
          url: res.url,
          // 私有/证书模式：不拼 full_url，文件不公开
          full_url: props.secure ? '' : res.full_url || toFullUrl(res.url),
          // 私有模式优先保留用户原始文件名，方便后台辨认（"apiclient_key.pem"）
          name: props.secure ? file.name : res.name || file.name,
        };
      }
    }

    if (fileInfo) {
      emit('update:value', fileInfo);
    }

    onSuccess({}, file);
  } catch (error) {
    console.error('上传失败:', error);
    message.error('上传失败');
    onError(error);
  } finally {
    pendingCount.value--;
  }
};

/** 批量上传（files/images 类型），使用 uploadBatchApi 一次请求 */
const executeBatchUpload = async () => {
  const items = [...batchQueue];
  batchQueue.length = 0;
  if (items.length === 0) return;

  try {
    const files = items.map((item) => item.file);

    if (props.customUpload) {
      // 用户自定义上传：逐个调用（串行，避免并发）
      for (const item of items) {
        try {
          const params = buildUploadParams();
          const fileInfo = await props.customUpload(item.file, params);

          if (fileInfo) {
            const current = Array.isArray(props.value) ? [...props.value] : [];
            current.push(fileInfo);
            emit('update:value', current);
          }

          item.onSuccess({}, item.file);
        } catch (error) {
          console.error('上传失败:', error);
          message.error('上传失败');
          item.onError(error);
        } finally {
          pendingCount.value--;
        }
      }
    } else {
      // 内置上传：调用 uploadBatchApi 批量上传
      const params = buildUploadParams();
      const res = await uploadBatchApi(files, params);

      // 后端返回格式：{ results: UploadResponse[], errors: any[] }
      const uploadedFiles = res?.results || [];
      if (uploadedFiles.length > 0) {
        const newFiles: FileInfo[] = uploadedFiles.map(
          (item: UploadApi.UploadResponse) => ({
            url: item.url,
            full_url: item.full_url || toFullUrl(item.url),
            name: item.name,
          }),
        );

        const current = Array.isArray(props.value) ? [...props.value] : [];
        current.push(...newFiles);
        emit('update:value', current);
      }

      // 所有文件标记为成功
      for (const item of items) {
        item.onSuccess({}, item.file);
      }
    }
  } catch (error) {
    console.error('批量上传失败:', error);
    message.error('批量上传失败');
    // 所有文件标记为失败
    for (const item of items) {
      item.onError(error);
    }
  } finally {
    pendingCount.value -= items.length;
    if (pendingCount.value < 0) pendingCount.value = 0;
  }
};

/** ant-design-vue customRequest 入口 */
const handleCustomRequest = (options: any) => {
  const { file, onSuccess, onError } = options;

  if (['files', 'images', 'videos'].includes(props.type)) {
    // 多文件类型：收集到队列，同一批选择结束后统一调用 uploadBatchApi
    batchQueue.push({
      file: file as File,
      onSuccess,
      onError,
    });

    // 利用 setTimeout(0) 等同一批次的 customRequest 全部触发后，再统一上传
    if (batchTimer) clearTimeout(batchTimer);
    batchTimer = setTimeout(() => {
      batchTimer = null;
      executeBatchUpload();
    }, 0);
  } else {
    // 单文件类型：直接调用 uploadSingleApi
    executeSingleUpload(file as File, onSuccess, onError);
  }
};

const handleRemove = async (file: UploadFile) => {
  if (props.customRemove) {
    const index = fileList.value.findIndex((item) => item.uid === file.uid);
    await props.customRemove(index === -1 ? undefined : index);
  } else if (['files', 'images', 'videos'].includes(props.type)) {
    const index = fileList.value.findIndex((item) => item.uid === file.uid);
    if (index !== -1) {
      const current = Array.isArray(props.value) ? [...props.value] : [];
      current.splice(index, 1);
      emit('update:value', current);
    }
  } else {
    emit('update:value', undefined);
  }
};

const showUploadButton = computed(() => {
  if (props.disabled) return false;
  const val = props.value;
  let currentCount = 0;
  if (Array.isArray(val)) {
    currentCount = val.length;
  } else if (val) {
    currentCount = 1;
  }
  // 已上传 + 正在上传/排队中 < maxCount 时才显示上传按钮
  return currentCount + pendingCount.value < effectiveMaxCount.value;
});

/** 上传按钮文字 */
const uploadButtonText = computed(() => {
  if (props.directory) return '上传文件夹';
  if (props.type === 'video') return '上传视频';
  if (props.type === 'videos') return '添加视频';
  return props.type === 'file' ? '上传文件' : '添加文件';
});

const videoPreviewOpen = ref(false);
const videoPreviewUrl = ref('');
const videoPreviewTitle = ref('');

const isVideoFile = (file: UploadFile) => {
  const fileType = (file.type || '').toLowerCase();
  if (fileType.startsWith('video/')) return true;
  const name = (file.name || file.url || '').toLowerCase();
  return /\.(mp4|mov|avi|mkv|flv|wmv|webm|ts)$/i.test(name);
};

const inlineVideoList = computed(() =>
  fileList.value
    .filter((file) => isVideoFile(file) && !!file.url)
    .map((file) => ({
      uid: file.uid,
      name: file.name || '视频文件',
      url: file.url as string,
    })),
);

const handlePreview = (file: UploadFile) => {
  // 私有/证书模式：没有可访问的公开 URL，预览图标已隐藏；这里再加一道防线
  if (props.secure) return;
  if (isVideoFile(file) && file.url) {
    videoPreviewUrl.value = file.url;
    videoPreviewTitle.value = file.name || '视频预览';
    videoPreviewOpen.value = true;
    return;
  }
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
    <!-- 图片/视频类型：缩略图卡片 -->
    <template v-if="(isImageType || isVideoType) && showUploadButton">
      <div class="upload-trigger-icon" :title="isVideoType ? '上传视频' : '上传图片'">
        <span class="upload-trigger-icon__symbol">{{ isVideoType ? '▶' : '+' }}</span>
      </div>
    </template>

    <!-- 文件类型：按钮上传 -->
    <template v-else-if="showUploadButton">
      <a-button>
        <template #icon>
          <span>↑</span>
        </template>
        {{ uploadButtonText }}
      </a-button>
    </template>
  </a-upload>

  <div v-if="uploadWarnings.length > 0" class="upload-warning-text">
    {{ uploadWarnings.join('；') }}
  </div>

  <div v-if="isVideoType && inlineVideoList.length > 0" class="video-inline-preview">
    <div
      v-for="item in inlineVideoList"
      :key="item.uid"
      class="video-inline-preview__item"
    >
      <video
        :src="item.url"
        controls
        preload="metadata"
        class="video-inline-preview__player"
      />
      <div class="video-inline-preview__name" :title="item.name">{{ item.name }}</div>
    </div>
  </div>

  <a-modal
    v-model:open="videoPreviewOpen"
    :title="videoPreviewTitle"
    :footer="null"
    width="760px"
    destroy-on-close
  >
    <video
      v-if="videoPreviewUrl"
      :src="videoPreviewUrl"
      controls
      style="display: block; width: 100%; max-height: 70vh; border-radius: 8px; background: #000"
    />
  </a-modal>
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

:deep(.ant-upload-list-picture-card-container) {
  width: 108px;
  height: 108px;
}

:deep(.ant-upload-list-picture-card .ant-upload-list-item) {
  background: hsl(var(--popover));
  border: 1px solid hsl(var(--border));
}

:deep(.ant-upload-select-picture-card) {
  background: hsl(var(--card));
  border: 1px dashed hsl(var(--border));
}

:deep(.ant-upload-select-picture-card .ant-upload) {
  display: flex;
  align-items: center;
  justify-content: center;
}

.upload-trigger-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}

.upload-trigger-icon__symbol {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 999px;
  font-size: 20px;
  line-height: 1;
  color: hsl(var(--muted-foreground));
}

.video-inline-preview {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 12px;
  margin-top: 12px;
}

.video-inline-preview__item {
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  background: hsl(var(--card));
  padding: 8px;
}

.video-inline-preview__player {
  display: block;
  width: 100%;
  height: 140px;
  border-radius: 6px;
  background: #000;
}

.video-inline-preview__name {
  margin-top: 8px;
  font-size: 12px;
  color: hsl(var(--foreground) / 0.8);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.upload-warning-text {
  margin-top: 8px;
  font-size: 12px;
  color: hsl(var(--warning));
}
</style>
