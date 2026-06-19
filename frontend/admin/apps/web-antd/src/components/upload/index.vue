<script lang="ts" setup>
import type { UploadFile, UploadProps } from 'ant-design-vue';

import type { FileInfo } from './index';

import type { UploadApi, UploadParams } from '#/api/core/upload';
import type { UploadType } from '#/api/core/upload-config-cache';
import type { UploadAssetApi } from '#/api/upload/asset';

import { computed, onMounted, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import {
  getUploadConfigApi,
  uploadBatchApi,
  uploadSingleApi,
} from '#/api/core/upload';
import {
  getUploadConfigCached,
  getUploadOptionsCached,
} from '#/api/core/upload-config-cache';
import {
  getUploadAssetCategoryTreeApi,
  selectUploadAssetsApi,
} from '#/api/upload/asset';

type UploadResponseLike =
  | UploadApi.UploadResponse
  | { data?: UploadApi.UploadResponse };

/**
 * Upload 组件
 *
 * @example
 * <Upload type="image" :value="fileList" module="dynamic_form" :related-id="123" />
 * <Upload type="image" :value="fileList" :custom-upload="handleUpload" />
 */
interface Props {
  /** 上传类型；展示文案、素材类型和多选规则由后端 uploadOptions 解释 */
  type?: 'file' | 'files' | 'image' | 'images' | 'video' | 'videos';
  /** 已上传的文件值 */
  value?: Array<FileInfo | string> | FileInfo | string;
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
  /** 上传模式：direct=直接上传，library=只选素材库，both=素材库选择+直接上传 */
  mode?: 'both' | 'direct' | 'library';
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
  mode: 'both',
});

const emit = defineEmits<{
  (e: 'update:value', value: any): void;
}>();

// ==================== 素材库选择 ====================

type AssetType = UploadAssetApi.AssetItem['type'];

interface AssetCategoryOption {
  id?: number;
  level: number;
  name: string;
}

const assetPickerOpen = ref(false);
const assetLoading = ref(false);
const assetList = ref<UploadAssetApi.AssetItem[]>([]);
const assetCategories = ref<UploadAssetApi.CategoryItem[]>([]);
const selectedAssetIds = ref<number[]>([]);
const selectedAssets = ref<UploadAssetApi.AssetItem[]>([]);
const assetPagination = ref({ current: 1, pageSize: 18, total: 0 });
const assetSearch = ref({
  category_id: undefined as number | undefined,
  driver: undefined as string | undefined,
  keyword: '',
});
const uploadOptions = ref<null | UploadApi.UploadOptions>(null);
const uploadOptionsLoading = ref(false);
const uploadOptionsError = ref('');

const uploadTypeOption = computed(() =>
  uploadOptions.value?.upload_types?.find((item) => item.value === props.type),
);

const pickerAssetType = ref<AssetType>('image');

const useAssetPickerMode = computed(
  () => !props.secure && props.mode !== 'direct',
);
const showAssetPickerTrigger = computed(
  () =>
    useAssetPickerMode.value &&
    !props.disabled &&
    !!uploadTypeOption.value &&
    !uploadOptionsError.value,
);
const showDirectUploadControl = computed(() => !useAssetPickerMode.value);

const loadUploadOptions = async () => {
  if (props.secure) return undefined;
  if (uploadOptions.value) return uploadOptions.value;
  uploadOptionsLoading.value = true;
  uploadOptionsError.value = '';
  try {
    const res = await getUploadOptionsCached();
    uploadOptions.value = res;
    return res;
  } catch (error) {
    console.warn('获取上传公共选项失败:', error);
    uploadOptionsError.value = '上传配置加载失败';
    return undefined;
  } finally {
    uploadOptionsLoading.value = false;
  }
};

const assetDriverOptions = computed(() => [
  { label: '全部', value: undefined },
  ...(uploadOptions.value?.upload_drivers || []).map((item) => ({
    label: item.label,
    value: item.value,
  })),
]);

const loadAssetCategories = async () => {
  if (assetCategories.value.length > 0) return;
  assetCategories.value = await getUploadAssetCategoryTreeApi({ status: 1 });
};

const flattenCategories = (
  items: UploadAssetApi.CategoryItem[],
  level = 0,
): AssetCategoryOption[] =>
  items.flatMap((item) => [
    { id: item.id, level, name: item.name },
    ...flattenCategories(item.children || [], level + 1),
  ]);

const assetCategoryOptions = computed<AssetCategoryOption[]>(() => [
  { id: undefined, level: 0, name: '全部素材' },
  ...flattenCategories(assetCategories.value),
]);

const currentCategoryName = computed(() => {
  const id = assetSearch.value.category_id;
  return (
    assetCategoryOptions.value.find((item) => item.id === id)?.name ||
    '全部素材'
  );
});

const assetTypeLabels = computed<Record<string, string>>(() =>
  Object.fromEntries(
    (uploadOptions.value?.asset_types || []).map((item) => [
      item.value,
      item.label,
    ]),
  ),
);

const pickerAcceptTypes = computed(() => effectiveAcceptTypes.value);

const pickerAccept = computed(() => pickerAcceptTypes.value.join(','));

const pickerCategoryTitle = computed(
  () => `${formatAssetType(pickerAssetType.value)}分类`,
);

const pickerUploadParamType = computed(() => props.type);

const isMultipleUploadType = (type?: Props['type']) =>
  type === 'files' || type === 'images' || type === 'videos';

const isMultiUploadType = computed(() => {
  if (props.multiple !== undefined) return props.multiple;
  if (uploadTypeOption.value) return uploadTypeOption.value.multiple === true;
  return isMultipleUploadType(props.type);
});

const loadAssets = async () => {
  assetLoading.value = true;
  try {
    const res = await selectUploadAssetsApi({
      ...assetSearch.value,
      type: pickerAssetType.value,
      page: assetPagination.value.current,
      limit: assetPagination.value.pageSize,
    });
    assetList.value = res.list || [];
    assetPagination.value.total = res.total || 0;
  } finally {
    assetLoading.value = false;
  }
};

const openAssetPicker = async () => {
  // uploadOptions 是字典接口：当前 type -> asset_type/multiple，以及驱动/素材类型 label。
  await loadUploadOptions();
  const option = uploadTypeOption.value;
  if (!option) {
    message.error(uploadOptionsError.value || '上传配置加载失败');
    return;
  }
  pickerAssetType.value = option.asset_type;
  selectedAssetIds.value = [];
  selectedAssets.value = [];
  assetPickerOpen.value = true;
  // 打开素材弹窗时按需刷新 uploadConfig，让上传提示和弹窗上传区绑定。
  await Promise.all([
    ensureRemoteConfig(true),
    loadAssetCategories(),
    loadAssets(),
  ]);
};

const assetToFileInfo = (asset: UploadAssetApi.AssetItem): FileInfo => ({
  url: String(asset.id),
  asset_id: asset.id,
  full_url: asset.full_url || '',
  name: asset.original_name || asset.name || `asset-${asset.id}`,
  original_name: asset.original_name,
});

const assetDisplayName = (asset: UploadAssetApi.AssetItem) =>
  asset.original_name || asset.name || `asset-${asset.id}`;

const isAssetSelected = (assetId: number) =>
  selectedAssetIds.value.includes(assetId);

const currentValueCount = () => {
  const val = props.value;
  if (Array.isArray(val)) return val.length;
  return val ? 1 : 0;
};

const canSelectMore = (includePending = false) =>
  effectiveMaxCount.value === undefined ||
  currentValueCount() +
    selectedAssets.value.length +
    (includePending ? pendingCount.value : 0) <
    effectiveMaxCount.value;

const toggleAssetSelection = (asset: UploadAssetApi.AssetItem) => {
  const assetId = Number(asset.id);
  if (!isMultiUploadType.value) {
    selectedAssetIds.value = [assetId];
    selectedAssets.value = [asset];
    return;
  }

  if (isAssetSelected(assetId)) {
    selectedAssetIds.value = selectedAssetIds.value.filter(
      (id) => id !== assetId,
    );
    selectedAssets.value = selectedAssets.value.filter(
      (item) => item.id !== assetId,
    );
    return;
  }

  if (!canSelectMore()) {
    message.warning(`最多上传 ${effectiveMaxCount.value ?? 0} 个文件`);
    return;
  }

  selectedAssetIds.value = [...selectedAssetIds.value, assetId];
  selectedAssets.value = [...selectedAssets.value, asset];
};

const confirmAssetSelection = () => {
  if (selectedAssets.value.length === 0) {
    message.warning('请选择素材');
    return;
  }

  const selectedFiles = selectedAssets.value.map((asset) =>
    assetToFileInfo(asset),
  );
  if (isMultiUploadType.value) {
    const current = Array.isArray(props.value) ? [...props.value] : [];
    const maxRemain =
      effectiveMaxCount.value === undefined
        ? selectedFiles.length
        : effectiveMaxCount.value - current.length;
    if (effectiveMaxCount.value !== undefined && maxRemain <= 0) {
      message.warning(`最多上传 ${effectiveMaxCount.value} 个文件`);
      return;
    }
    const next = [...current, ...selectedFiles.slice(0, maxRemain)];
    emit('update:value', next);
    fileList.value = buildFileList(next);
  } else {
    emit('update:value', selectedFiles[0]);
    fileList.value = buildFileList(selectedFiles[0]);
  }

  assetPickerOpen.value = false;
};

const setAssetCategory = (categoryId?: number) => {
  assetSearch.value.category_id = categoryId;
  assetPagination.value.current = 1;
  loadAssets();
};

const handlePickerDriverChange = () => {
  assetPagination.value.current = 1;
  loadAssets();
};

const handleAssetKeywordSearch = () => {
  assetSearch.value.keyword = assetSearch.value.keyword.trim();
  assetPagination.value.current = 1;
  loadAssets();
};

const handleAssetKeywordChange = () => {
  if (assetSearch.value.keyword === '') {
    assetPagination.value.current = 1;
    loadAssets();
  }
};

const handleAssetPageChange = (page: number, pageSize: number) => {
  assetPagination.value.current = page;
  assetPagination.value.pageSize = pageSize;
  loadAssets();
};

const formatAssetType = (type: AssetType) =>
  assetTypeLabels.value[type] || type;

const formatAssetSize = (size?: number) => {
  const bytes = Number(size || 0);
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';

  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let unitIndex = 0;
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex++;
  }

  const displayValue =
    unitIndex === 0 || value >= 10
      ? Math.round(value)
      : Number(value.toFixed(1));
  return `${displayValue} ${units[unitIndex]}`;
};

const currentUploadDriver = computed(() =>
  uploadOptions.value?.upload_drivers?.find((item) => item.enabled),
);

const matchesCurrentDriverFilter = (asset: UploadAssetApi.AssetItem) => {
  const driver = assetSearch.value.driver;
  return !driver || asset.driver === driver;
};

const addUploadedAssetToPicker = (asset: UploadAssetApi.AssetItem) => {
  if (
    asset.type === pickerAssetType.value &&
    matchesCurrentDriverFilter(asset)
  ) {
    const exists = assetList.value.some((item) => item.id === asset.id);
    assetList.value = [
      asset,
      ...assetList.value.filter((item) => item.id !== asset.id),
    ];
    if (!exists) {
      assetPagination.value.total += 1;
    }
  }
  toggleAssetSelection(asset);
};

const uploadedAssetFromResponse = (
  response: undefined | UploadResponseLike,
  fileInfo: FileInfo | undefined,
  file: File,
): undefined | UploadAssetApi.AssetItem => {
  const res = unwrapUploadResponse(response);
  const assetId =
    fileInfo?.asset_id || res?.asset_id || Number(fileInfo?.url || 0);
  if (!assetId) return undefined;

  const name = res?.name || fileInfo?.name || file.name;
  const ext = name.includes('.')
    ? name.split('.').pop()?.toLowerCase() || ''
    : '';

  return {
    id: assetId,
    category_id: Number(res?.category_id || assetSearch.value.category_id || 0),
    category_name: assetSearch.value.category_id
      ? currentCategoryName.value
      : undefined,
    type: pickerAssetType.value,
    name,
    original_name: file.name,
    mime: res?.mime || file.type,
    ext,
    size: Number(res?.size ?? file.size),
    module: props.module,
    status: 1,
    driver: res?.driver,
    path: res?.path,
    full_url: res?.full_url || fileInfo?.full_url || '',
  };
};

const isPickerFileAccepted = (file: File) => {
  const fileExt = (file.name.split('.').pop() || '').toLowerCase();
  const acceptTypes = pickerAcceptTypes.value;
  if (acceptTypes.length === 0) return true;

  return acceptTypes.some((acceptType) => {
    const normalized = acceptType.trim().toLowerCase();
    if (normalized === '') return false;
    if (normalized.startsWith('.')) return normalized.slice(1) === fileExt;
    return normalized === file.type;
  });
};

const handlePickerBeforeUpload = async (file: File) => {
  if (!(await ensureRemoteConfigBeforeUpload())) return false;

  if (!canSelectMore(true)) {
    message.error(`最多上传 ${effectiveMaxCount.value ?? 0} 个文件`);
    return false;
  }

  const maxSize = effectiveMaxSize.value;
  if (maxSize !== undefined && file.size / 1024 / 1024 > maxSize) {
    message.error(`文件大小不能超过 ${maxSize}MB`);
    return false;
  }

  if (!isPickerFileAccepted(file)) {
    message.error('不支持的文件类型');
    return false;
  }

  pendingCount.value++;
  return true;
};

const handlePickerUploadRequest = async (options: any) => {
  const { file, onError, onSuccess } = options;
  try {
    const uploadFile = file as File;
    const params: UploadParams = {
      ...buildUploadParams(),
      type: pickerUploadParamType.value,
      category_id: assetSearch.value.category_id,
    };
    const response = await uploadSingleApi(uploadFile, params);
    const fileInfo = toFileInfo(response, uploadFile);
    const asset = uploadedAssetFromResponse(response, fileInfo, uploadFile);
    if (asset) {
      addUploadedAssetToPicker(asset);
    }
    message.success('上传成功');
    onSuccess(fileInfo || {}, file);
  } catch (error) {
    console.error('上传失败:', error);
    message.error('上传失败');
    onError(error);
  } finally {
    pendingCount.value--;
    if (pendingCount.value < 0) pendingCount.value = 0;
  }
};

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
const uploadConfigError = ref('');

const loadRemoteConfig = async (force = false) => {
  // 私有/证书模式：accept/maxSize 全部走 dynamic-form 传入的 props，
  // 后端 cert module 没有公开的上传规则配置接口，不需要远程拉取。
  if (props.secure) {
    return undefined;
  }
  try {
    const res = force
      ? await getUploadConfigApi(props.type)
      : await getUploadConfigCached(props.type as UploadType);
    if (res) {
      uploadConfigError.value = '';
      remoteConfig.value = {
        maxSize: res.max_size,
        maxCount: res.max_count,
        acceptTypes: res.accept_types,
        warnings: res.warnings || [],
      };
    }
    return res;
  } catch (error) {
    console.warn('获取上传配置失败:', error);
    uploadConfigError.value = '上传配置加载失败';
    return undefined;
  }
};

const shouldLoadRemoteConfig = () =>
  !props.secure &&
  (props.maxSize === undefined ||
    props.maxCount === undefined ||
    props.accept === undefined);

const ensureRemoteConfig = async (force = false) => {
  if (!shouldLoadRemoteConfig()) return true;
  if (!force && remoteConfig.value) return true;
  await loadRemoteConfig(force);
  return !!remoteConfig.value;
};

const ensureRemoteConfigBeforeUpload = async () => {
  const loaded = await ensureRemoteConfig();
  if (!loaded) {
    message.error(uploadConfigError.value || '上传配置加载失败，请稍后重试');
    return false;
  }
  return true;
};

onMounted(() => {
  // uploadOptions 是公共字典：用于把 type 映射成素材类型/多选规则，并渲染驱动 label。
  loadUploadOptions();
});

// ==================== 合并后的配置 ====================

const effectiveMaxSize = computed(() => {
  if (props.maxSize !== undefined) return props.maxSize;
  return remoteConfig.value?.maxSize;
});

const effectiveMaxCount = computed(() => {
  if (props.maxCount !== undefined) return props.maxCount;
  if (remoteConfig.value?.maxCount !== undefined)
    return remoteConfig.value.maxCount;
  return isMultiUploadType.value ? undefined : 1;
});

const effectiveAcceptTypes = computed(() => {
  if (props.accept) return props.accept;
  return remoteConfig.value?.acceptTypes ?? [];
});

const effectiveAccept = computed(() => effectiveAcceptTypes.value.join(','));
const uploadWarnings = computed(() => remoteConfig.value?.warnings ?? []);
const normalizeNoticeLines = (lines: string[]) => [
  ...new Set(lines.map((line) => line.trim()).filter(Boolean)),
];
const uploadConfigNoticeLines = computed(() =>
  normalizeNoticeLines([...uploadWarnings.value, uploadConfigError.value]),
);
const inlineUploadNoticeLines = computed(() => {
  if (useAssetPickerMode.value) {
    return normalizeNoticeLines([uploadOptionsError.value]);
  }

  return normalizeNoticeLines([
    uploadOptionsError.value,
    ...uploadConfigNoticeLines.value,
  ]);
});
const formatUploadLimitSize = (value?: number) => {
  if (value === undefined) return '';
  return Number.isInteger(value)
    ? String(value)
    : String(Number(value.toFixed(2)));
};
const assetPickerUploadLimitTip = computed(() => {
  if (!remoteConfig.value) return '';

  const tips = [];
  const typeLabel = uploadTypeOption.value?.label || props.type;
  const maxSize = formatUploadLimitSize(effectiveMaxSize.value);
  if (typeLabel) tips.push(`上传类型 ${typeLabel}`);
  if (maxSize) tips.push(`单个文件不超过 ${maxSize}MB`);
  if (effectiveMaxCount.value !== undefined)
    tips.push(`最多 ${effectiveMaxCount.value} 个`);

  return tips.join('，');
});
const assetPickerUploadNoticeLines = computed(() =>
  normalizeNoticeLines([
    assetPickerUploadLimitTip.value,
    ...uploadConfigNoticeLines.value,
  ]),
);

const isImageType = computed(
  () =>
    uploadTypeOption.value?.asset_type === 'image' ||
    props.type === 'image' ||
    props.type === 'images',
);
const isVideoType = computed(
  () =>
    uploadTypeOption.value?.asset_type === 'video' ||
    props.type === 'video' ||
    props.type === 'videos',
);

/** 多选：files/images 自动开启，传了 multiple 以传的为准 */
const effectiveMultiple = computed(() => {
  return isMultiUploadType.value;
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
  if (/^\d+$/.test(path)) return '';
  if (/^(?:https?:)?\/\//.test(path)) {
    return path.startsWith('//') ? `${window.location.protocol}${path}` : path;
  }

  const apiBase = import.meta.env.VITE_GLOB_API_URL || '';
  let origin = '';
  try {
    origin = new URL(apiBase, window.location.origin).origin;
  } catch {
    origin = window.location.origin;
  }

  if (path.startsWith('/')) return `${origin}${path}`;
  return `${origin}/${path}`;
};

const unwrapUploadResponse = (
  response: undefined | UploadResponseLike,
): undefined | UploadApi.UploadResponse => {
  if (!response) return undefined;
  if ('data' in response && response.data) return response.data;
  return response as UploadApi.UploadResponse;
};

const toFileInfo = (
  response: undefined | UploadResponseLike,
  file: File,
): FileInfo | undefined => {
  const res = unwrapUploadResponse(response);
  if (!res) return undefined;

  const urlIsAbsolute = !!res.url && /^(?:https?:)?\/\//.test(res.url);
  const storagePath =
    urlIsAbsolute && res.path ? res.path : res.url || res.path || '';
  const submitUrl = res.asset_id ? String(res.asset_id) : storagePath;
  const previewUrl = props.secure
    ? ''
    : res.full_url || (urlIsAbsolute ? res.url : toFullUrl(storagePath));

  return {
    // url 保存提交值；新素材模式优先保存 asset_id，旧路径继续兼容。
    url: submitUrl,
    full_url: previewUrl,
    name: props.secure
      ? file.name
      : res.original_name ||
        file.name ||
        res.name ||
        extractFileName(storagePath),
    asset_id: res.asset_id,
    original_name: res.original_name || file.name,
  };
};

// ==================== 上传配置 ====================

const uploadListType = computed<UploadProps['listType']>(() => {
  if (props.secure) return 'text';
  if (isImageType.value || isVideoType.value) return 'picture-card';
  return 'text';
});

const uploadProps = computed<UploadProps>(() => ({
  name: 'file',
  maxCount: effectiveMaxCount.value,
  accept: effectiveAccept.value || undefined,
  // 私有/证书模式：强制 text 列表（无缩略图），无预览图标（没有公开 URL）
  listType: uploadListType.value,
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

const previewUploadProps = computed<UploadProps>(() => ({
  name: 'file',
  listType: uploadProps.value.listType,
  showUploadList: props.showUploadList
    ? {
        showDownloadIcon: false,
        showPreviewIcon: !props.secure,
        showRemoveIcon: !props.disabled,
      }
    : false,
  onRemove: handleRemove,
}));

const buildFileList = (value: Props['value'] = props.value): UploadFile[] => {
  const val = value;
  if (!val) return [];

  // 私有/证书模式：不把 url 写到 UploadFile 上，避免 antd 渲染成可点击链接（链接 404）
  const resolveItemUrl = (item: FileInfo): string | undefined => {
    if (props.secure) return undefined;
    if (/^\d+$/.test(item.url) && !item.full_url) return undefined;
    return item.full_url || toFullUrl(item.url);
  };

  if (Array.isArray(val)) {
    const visibleItems = val.map((item: FileInfo | string, index: number) => {
      const fileInfo =
        typeof item === 'object'
          ? item
          : { url: item, name: extractFileName(item) };
      const url = resolveItemUrl(fileInfo);
      return {
        uid: `${index}`,
        name: fileInfo.name || extractFileName(fileInfo.url),
        status: 'done' as const,
        thumbUrl: url,
        url,
      };
    });

    return visibleItems;
  }

  if (typeof val === 'object') {
    const url = resolveItemUrl(val as FileInfo);
    return [
      {
        uid: '0',
        name: (val as FileInfo).name || extractFileName((val as FileInfo).url),
        status: 'done' as const,
        thumbUrl: url,
        url,
      },
    ];
  }

  const url = props.secure || /^\d+$/.test(val) ? undefined : toFullUrl(val);
  return [
    {
      uid: '0',
      name: extractFileName(val),
      status: 'done' as const,
      thumbUrl: url,
      url,
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

const handleBeforeUpload = async (file: File) => {
  if (!(await ensureRemoteConfigBeforeUpload())) return false;

  // 数量校验：已上传 + 正在上传/排队中 不能超过 maxCount
  const maxCount = effectiveMaxCount.value;
  const currentCount = fileList.value.length + pendingCount.value;
  if (maxCount !== undefined && currentCount >= maxCount) {
    message.error(`最多上传 ${maxCount} 个文件`);
    return false;
  }

  // 大小校验
  const maxSize = effectiveMaxSize.value;
  if (maxSize !== undefined && file.size / 1024 / 1024 > maxSize) {
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
  if (props.mode === 'library') {
    message.info('请从素材库选择文件');
    onError(new Error('library mode'));
    pendingCount.value--;
    if (pendingCount.value < 0) pendingCount.value = 0;
    return;
  }

  try {
    const params = buildUploadParams();
    const fileInfo = props.customUpload
      ? await props.customUpload(file, params)
      : toFileInfo(await uploadSingleApi(file, params), file);

    if (fileInfo) {
      fileList.value = buildFileList(fileInfo);
      emit('update:value', fileInfo);
    }

    onSuccess(fileInfo || {}, file);
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
  if (props.mode === 'library') {
    for (const item of items) {
      item.onError(new Error('library mode'));
    }
    pendingCount.value -= items.length;
    if (pendingCount.value < 0) pendingCount.value = 0;
    message.info('请从素材库选择文件');
    return;
  }

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
            fileList.value = buildFileList(current);
            emit('update:value', current);
          }

          item.onSuccess(fileInfo || {}, item.file);
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
        const newFiles: FileInfo[] = uploadedFiles
          .map((item: UploadApi.UploadResponse, index: number) =>
            toFileInfo(item, files[index]!),
          )
          .filter((item): item is FileInfo => !!item);

        const current = Array.isArray(props.value) ? [...props.value] : [];
        current.push(...newFiles);
        fileList.value = buildFileList(current);
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

  if (effectiveMultiple.value) {
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
  } else if (effectiveMultiple.value) {
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
  return (
    effectiveMaxCount.value === undefined ||
    currentCount + pendingCount.value < effectiveMaxCount.value
  );
});

/** 上传按钮文字 */
const uploadButtonText = computed(() => {
  if (props.directory) return '上传文件夹';
  const assetType = uploadTypeOption.value?.asset_type;
  if (assetType === 'video')
    return effectiveMultiple.value ? '添加视频' : '上传视频';
  if (assetType === 'file')
    return effectiveMultiple.value ? '添加文件' : '上传文件';
  return effectiveMultiple.value ? '添加图片' : '上传图片';
});

const videoPreviewOpen = ref(false);
const videoPreviewUrl = ref('');
const videoPreviewTitle = ref('');

const isVideoFile = (file: UploadFile) => {
  const fileType = (file.type || '').toLowerCase();
  if (fileType.startsWith('video/')) return true;
  const name = (file.name || file.url || '').toLowerCase();
  return /\.(?:mp4|mov|avi|mkv|flv|wmv|webm|ts)$/i.test(name);
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
  <div
    class="upload-field"
    :class="{ 'upload-field--compact': !props.showUploadList }"
  >
    <template v-if="useAssetPickerMode">
      <div
        class="upload-picker-inline"
        :class="{
          'upload-picker-inline--picture': isImageType || isVideoType,
        }"
      >
        <a-upload
          v-if="props.showUploadList && fileList.length > 0"
          v-bind="previewUploadProps"
          :file-list="fileList"
          @preview="handlePreview"
        />

        <button
          v-if="showAssetPickerTrigger && showUploadButton"
          type="button"
          :class="[
            isImageType || isVideoType
              ? 'asset-picker-trigger asset-picker-trigger--card'
              : 'asset-picker-trigger asset-picker-trigger--button',
          ]"
          @click="openAssetPicker"
        >
          <span v-if="isImageType || isVideoType" class="upload-trigger-icon">
            <span class="upload-trigger-icon__symbol">{{
              isVideoType ? '▶' : '+'
            }}</span>
          </span>
          <span v-else>{{ uploadButtonText }}</span>
        </button>
      </div>
    </template>

    <a-upload
      v-else-if="showDirectUploadControl"
      v-bind="uploadProps"
      :file-list="fileList"
      :disabled="disabled"
      @preview="handlePreview"
    >
      <template v-if="(isImageType || isVideoType) && showUploadButton">
        <div
          class="upload-trigger-icon"
          :title="isVideoType ? '上传视频' : '上传图片'"
        >
          <span class="upload-trigger-icon__symbol">{{
            isVideoType ? '▶' : '+'
          }}</span>
        </div>
      </template>

      <template v-else-if="showUploadButton">
        <a-button>
          <template #icon>
            <span>↑</span>
          </template>
          {{ uploadButtonText }}
        </a-button>
      </template>
    </a-upload>
  </div>

  <div v-if="inlineUploadNoticeLines.length > 0" class="upload-warning-text">
    <span
      v-for="line in inlineUploadNoticeLines"
      :key="line"
      class="upload-warning-text__line"
    >
      {{ line }}
    </span>
  </div>

  <div
    v-if="isVideoType && inlineVideoList.length > 0"
    class="video-inline-preview"
  >
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
      ></video>
      <div class="video-inline-preview__name" :title="item.name">
        {{ item.name }}
      </div>
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
      style="
        display: block;
        width: 100%;
        max-height: 70vh;
        border-radius: 8px;
        background: #000;
      "
    ></video>
  </a-modal>

  <a-modal
    v-model:open="assetPickerOpen"
    title="选择素材"
    width="960px"
    @ok="confirmAssetSelection"
  >
    <div class="asset-picker">
      <aside class="asset-picker__sidebar">
        <div class="asset-picker__sidebar-title">{{ pickerCategoryTitle }}</div>
        <button
          v-for="category in assetCategoryOptions"
          :key="category.id || 'all'"
          type="button"
          class="asset-picker__category"
          :class="{
            'asset-picker__category--active':
              assetSearch.category_id === category.id,
          }"
          :style="{ paddingLeft: `${12 + category.level * 14}px` }"
          @click="setAssetCategory(category.id)"
        >
          {{ category.name }}
        </button>
      </aside>

      <section class="asset-picker__content">
        <div class="asset-picker__header">
          <div class="asset-picker__current">
            当前：{{ currentCategoryName }} /
            {{ formatAssetType(pickerAssetType) }}
            <span v-if="currentUploadDriver">
              / 当前驱动：{{ currentUploadDriver.label }}
            </span>
          </div>
          <div
            v-if="props.mode !== 'library'"
            class="asset-picker__upload-area"
          >
            <a-upload
              :accept="pickerAccept"
              :before-upload="handlePickerBeforeUpload"
              :custom-request="handlePickerUploadRequest"
              :multiple="isMultiUploadType"
              :show-upload-list="false"
            >
              <a-button type="primary">上传新素材</a-button>
            </a-upload>
          </div>
        </div>

        <div
          v-if="assetPickerUploadNoticeLines.length > 0"
          class="asset-picker__warning"
        >
          <span class="asset-picker__warning-title">上传提示</span>
          <span class="asset-picker__warning-content">
            <span
              v-for="line in assetPickerUploadNoticeLines"
              :key="line"
              class="asset-picker__warning-line"
            >
              {{ line }}
            </span>
          </span>
        </div>

        <div class="asset-picker__filters">
          <div class="asset-picker__filter-row">
            <span class="asset-picker__filter-label">关键字</span>
            <a-input-search
              v-model:value="assetSearch.keyword"
              allow-clear
              class="asset-picker__search"
              placeholder="搜索文件名、原始名、Hash"
              @change="handleAssetKeywordChange"
              @search="handleAssetKeywordSearch"
            />
          </div>
          <div class="asset-picker__filter-row">
            <span class="asset-picker__filter-label">上传驱动</span>
            <a-radio-group
              v-model:value="assetSearch.driver"
              button-style="solid"
              @change="handlePickerDriverChange"
            >
              <a-radio-button
                v-for="item in assetDriverOptions"
                :key="item.value || 'all'"
                :value="item.value"
              >
                {{ item.label }}
              </a-radio-button>
            </a-radio-group>
          </div>
        </div>

        <a-spin :spinning="assetLoading">
          <div v-if="assetList.length > 0" class="asset-picker__grid">
            <button
              v-for="asset in assetList"
              :key="asset.id"
              type="button"
              class="asset-picker__card"
              :class="{
                'asset-picker__card--active': isAssetSelected(asset.id),
              }"
              @click="toggleAssetSelection(asset)"
            >
              <span class="asset-picker__check">✓</span>
              <span class="asset-picker__preview">
                <img
                  v-if="asset.type === 'image' && asset.full_url"
                  :src="asset.full_url"
                  :alt="assetDisplayName(asset)"
                />
                <span v-else class="asset-picker__placeholder">
                  {{ formatAssetType(asset.type) }}
                </span>
              </span>
              <span class="asset-picker__meta">
                <span
                  class="asset-picker__name"
                  :title="assetDisplayName(asset)"
                >
                  {{ assetDisplayName(asset) }}
                </span>
                <span class="asset-picker__size">
                  {{ formatAssetSize(asset.size) }}
                </span>
              </span>
            </button>
          </div>
          <a-empty v-else />
        </a-spin>

        <div class="asset-picker__bottom">
          <div class="asset-picker__selected">
            <span class="asset-picker__selected-count">
              已选 {{ selectedAssets.length }} 个
            </span>
            <span
              v-for="asset in selectedAssets"
              :key="asset.id"
              class="asset-picker__selected-item"
              :title="assetDisplayName(asset)"
            >
              <img
                v-if="asset.type === 'image' && asset.full_url"
                :src="asset.full_url"
                :alt="assetDisplayName(asset)"
              />
              <span v-else>{{ formatAssetType(asset.type).slice(0, 1) }}</span>
            </span>
          </div>

          <a-pagination
            size="small"
            :current="assetPagination.current"
            :page-size="assetPagination.pageSize"
            :total="assetPagination.total"
            show-size-changer
            @change="handleAssetPageChange"
            @show-size-change="handleAssetPageChange"
          />
        </div>
      </section>
    </div>
  </a-modal>
</template>

<style scoped>
.upload-field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.upload-field--compact {
  width: 100%;
  height: 100%;
}

.upload-picker-inline {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: flex-start;
}

.upload-field--compact .upload-picker-inline {
  width: 100%;
  height: 100%;
}

.upload-picker-inline--picture :deep(.ant-upload-wrapper) {
  flex: 0 1 auto;
  width: auto;
  max-width: 100%;
}

.upload-picker-inline--picture :deep(.ant-upload-list) {
  margin-top: 0;
}

.upload-picker-inline--picture :deep(.ant-upload-list-picture-card) {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.upload-picker-inline--picture :deep(.ant-upload-list-picture-card-container) {
  margin: 0;
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
  background-color: hsl(var(--accent));
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

.asset-picker-trigger {
  cursor: pointer;
  color: hsl(var(--foreground));
  background: hsl(var(--card));
  border: 1px dashed hsl(var(--border));
  transition:
    border-color 0.2s,
    color 0.2s,
    background-color 0.2s;
}

.asset-picker-trigger:hover {
  color: hsl(var(--primary));
  border-color: hsl(var(--primary));
}

.asset-picker-trigger--card {
  box-sizing: border-box;
  flex: 0 0 108px;
  width: 108px;
  height: 108px;
  padding: 0;
  border-radius: 8px;
}

.asset-picker-trigger--button {
  width: fit-content;
  min-height: 32px;
  padding: 4px 15px;
  border-radius: 6px;
}

.upload-field--compact .asset-picker-trigger--card {
  flex: 1 1 auto;
  width: 100%;
  min-width: 0;
  height: 100%;
  min-height: 0;
}

.upload-field--compact .upload-trigger-icon__symbol {
  width: 20px;
  height: 20px;
  font-size: 16px;
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
  display: grid;
  gap: 2px;
  margin-top: 8px;
  font-size: 12px;
  line-height: 1.5;
  color: hsl(var(--warning));
}

.upload-warning-text__line {
  min-width: 0;
  overflow-wrap: anywhere;
}

.asset-picker {
  display: grid;
  grid-template-columns: 168px minmax(0, 1fr);
  max-height: 70vh;
  min-height: 0;
  overflow: hidden;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.asset-picker__sidebar {
  padding: 12px 8px;
  overflow-y: auto;
  background: hsl(var(--muted) / 0.28);
  border-right: 1px solid hsl(var(--border));
}

.asset-picker__sidebar-title {
  padding: 4px 10px 10px;
  font-size: 13px;
  font-weight: 600;
  color: hsl(var(--foreground));
}

.asset-picker__category {
  display: block;
  width: 100%;
  min-height: 34px;
  padding-top: 6px;
  padding-right: 10px;
  padding-bottom: 6px;
  margin-bottom: 2px;
  overflow: hidden;
  font-size: 13px;
  color: hsl(var(--muted-foreground));
  text-align: left;
  text-overflow: ellipsis;
  white-space: nowrap;
  cursor: pointer;
  background: transparent;
  border: 0;
  border-radius: 6px;
}

.asset-picker__category:hover,
.asset-picker__category--active {
  color: hsl(var(--primary));
  background: hsl(var(--primary) / 0.1);
}

.asset-picker__content {
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  padding: 14px;
}

.asset-picker__header {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 12px;
}

.asset-picker__current {
  min-width: 0;
  overflow: hidden;
  font-size: 13px;
  font-weight: 600;
  color: hsl(var(--foreground));
  text-overflow: ellipsis;
  white-space: nowrap;
}

.asset-picker__upload-area {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  flex: 0 0 auto;
  gap: 8px;
}

.asset-picker__warning {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  padding: 8px 10px;
  width: 100%;
  margin-bottom: 12px;
  font-size: 12px;
  line-height: 1.5;
  color: hsl(var(--warning));
  background: hsl(var(--warning) / 0.08);
  border: 1px solid hsl(var(--warning) / 0.18);
  border-radius: 6px;
}

.asset-picker__warning-title {
  flex: 0 0 auto;
  font-weight: 600;
}

.asset-picker__warning-content {
  display: grid;
  flex: 1;
  min-width: 0;
  gap: 2px;
}

.asset-picker__warning-line {
  min-width: 0;
  overflow-wrap: anywhere;
}

.asset-picker__filters {
  display: grid;
  gap: 10px;
  padding-bottom: 12px;
  margin-bottom: 12px;
  border-bottom: 1px solid hsl(var(--border));
}

.asset-picker__filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.asset-picker__filter-label {
  flex: 0 0 64px;
  font-size: 13px;
  color: hsl(var(--muted-foreground));
}

.asset-picker__search {
  width: min(360px, 100%);
}

.asset-picker__grid {
  display: grid;
  grid-auto-rows: 138px;
  grid-template-columns: repeat(auto-fill, minmax(112px, 1fr));
  gap: 10px;
  max-height: 418px;
  min-height: 0;
  padding-right: 4px;
  overflow-y: auto;
}

.asset-picker__card {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 6px;
  height: 138px;
  min-width: 0;
  padding: 8px;
  text-align: left;
  cursor: pointer;
  background: hsl(var(--card));
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  transition:
    border-color 0.2s,
    box-shadow 0.2s,
    background-color 0.2s;
}

.asset-picker__card:hover,
.asset-picker__card--active {
  border-color: hsl(var(--primary));
  box-shadow: 0 0 0 2px hsl(var(--primary) / 0.12);
}

.asset-picker__check {
  position: absolute;
  top: 8px;
  right: 8px;
  z-index: 1;
  display: none;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  font-size: 12px;
  color: hsl(var(--primary-foreground));
  background: hsl(var(--primary));
  border-radius: 999px;
}

.asset-picker__card--active .asset-picker__check {
  display: inline-flex;
}

.asset-picker__preview {
  display: flex;
  flex: 0 0 84px;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: hsl(var(--muted) / 0.35);
  border-radius: 6px;
}

.asset-picker__preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.asset-picker__placeholder {
  font-size: 13px;
  color: hsl(var(--muted-foreground));
}

.asset-picker__meta {
  display: grid;
  gap: 1px;
  min-width: 0;
}

.asset-picker__name {
  overflow: hidden;
  font-size: 13px;
  color: hsl(var(--foreground));
  text-overflow: ellipsis;
  white-space: nowrap;
}

.asset-picker__size {
  overflow: hidden;
  font-size: 12px;
  line-height: 1.3;
  color: hsl(var(--muted-foreground));
  text-overflow: ellipsis;
  white-space: nowrap;
}

.asset-picker__bottom {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  padding-top: 12px;
  margin-top: auto;
  border-top: 1px solid hsl(var(--border));
}

.asset-picker__selected {
  display: flex;
  flex: 1;
  gap: 6px;
  align-items: center;
  min-width: 0;
  overflow: hidden;
}

.asset-picker__selected-count {
  flex: 0 0 auto;
  margin-right: 4px;
  font-size: 13px;
  color: hsl(var(--muted-foreground));
}

.asset-picker__selected-item {
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  overflow: hidden;
  font-size: 12px;
  color: hsl(var(--muted-foreground));
  background: hsl(var(--muted) / 0.35);
  border: 1px solid hsl(var(--border));
  border-radius: 6px;
}

.asset-picker__selected-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
</style>
