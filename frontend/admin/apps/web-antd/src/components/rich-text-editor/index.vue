<script lang="ts" setup>
import type {
  IEditorConfig,
  IDomEditor,
  IToolbarConfig,
} from '@wangeditor/editor';

import type { FileInfo } from '#/components/upload';

import {
  computed,
  nextTick,
  onBeforeUnmount,
  ref,
  shallowRef,
  watch,
} from 'vue';

import { message } from 'ant-design-vue';
import { IconifyIcon } from '@vben/icons';
import { DomEditor, SlateTransforms } from '@wangeditor/editor';
import { Editor, Toolbar } from '@wangeditor/editor-for-vue';

import Upload from '#/components/upload/index.vue';

import '@wangeditor/editor/dist/css/style.css';

interface Props {
  modelValue?: string;
  placeholder?: string;
  height?: number | string;
  module?: string;
  relatedId?: number | string;
  disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: '',
  placeholder: '请输入内容',
  height: 360,
  module: undefined,
  relatedId: undefined,
  disabled: false,
});

const emit = defineEmits<{
  (e: 'blur'): void;
  (e: 'update:modelValue', value: string): void;
}>();

const editorRef = shallowRef<IDomEditor>();
const html = ref(props.modelValue ?? '');
const lastEmittedValue = ref(normalizeHtml(props.modelValue ?? ''));
const uploadModalType = ref<'image' | 'video'>('image');
const uploadValue = ref<FileInfo | FileInfo[] | string | undefined>();
const uploadRef = ref<{ open: () => Promise<void> | void }>();

watch(
  () => props.modelValue,
  (value) => {
    const nextValue = value ?? '';
    lastEmittedValue.value = normalizeHtml(nextValue);
    if (normalizeHtml(nextValue) !== normalizeHtml(html.value)) {
      html.value = nextValue;
    }
  },
);

watch(
  () => props.disabled,
  (disabled) => {
    const editor = editorRef.value;
    if (!editor) {
      return;
    }

    if (disabled) {
      editor.disable();
    } else {
      editor.enable();
    }
  },
);

function normalizeHtml(value: string) {
  const trimmed = value.trim();
  if (trimmed === '' || trimmed === '<p><br></p>' || trimmed === '<p></p>') {
    return '';
  }

  return value;
}

const emitModelValue = (value: string) => {
  const nextValue = normalizeHtml(value);
  if (nextValue === lastEmittedValue.value) {
    return;
  }

  lastEmittedValue.value = nextValue;
  emit('update:modelValue', nextValue);
};

const escapeHtmlAttr = (value: string) =>
  value
    .replaceAll('&', '&amp;')
    .replaceAll('"', '&quot;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');

const uploadModule = () => props.module || 'rich_text';

const uploadFieldType = computed(() =>
  uploadModalType.value === 'image' ? 'images' : 'video',
);
const editorHeight = computed(() => {
  if (typeof props.height === 'number') {
    return `${Math.max(props.height, 300)}px`;
  }

  const height = props.height.trim();
  return height ? `max(300px, ${height})` : '360px';
});

const selectedUploadFiles = computed<FileInfo[]>(() => {
  const value = uploadValue.value;
  if (!value) {
    return [];
  }

  const items = Array.isArray(value) ? value : [value];
  return items
    .filter((item): item is FileInfo | string => !!item)
    .map((item) =>
      typeof item === 'string' ? { name: item, url: item } : item,
    );
});

const assetIdOf = (file: FileInfo) => {
  if (file.asset_id) {
    return file.asset_id;
  }

  if (/^\d+$/.test(file.url || '')) {
    return Number(file.url);
  }

  return undefined;
};

const fullUrlOf = (file: FileInfo) => {
  if (file.full_url) {
    return file.full_url;
  }

  if (/^\d+$/.test(file.url || '')) {
    return '';
  }

  return file.url || '';
};

const buildMediaHtml = (file: FileInfo, type: 'image' | 'video') => {
  const url = fullUrlOf(file);
  if (!url) {
    return '';
  }

  const assetId = assetIdOf(file);
  const assetAttr = assetId ? ` data-asset-id="${assetId}"` : '';
  const name = escapeHtmlAttr(file.original_name || file.name || '');
  const src = escapeHtmlAttr(url);

  if (type === 'video') {
    return `<p><video src="${src}" controls${assetAttr}></video></p>`;
  }

  return `<p><img src="${src}" alt="${name}"${assetAttr}></p>`;
};

const focusEditorEnd = (editor: IDomEditor) => {
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      if (!editor.isDestroyed) {
        editor.focus(true);
      }
    });
  });
};

const insertMediaFiles = (files: FileInfo[], type: 'image' | 'video') => {
  const editor = editorRef.value;
  if (!editor || typeof (editor as any).dangerouslyInsertHtml !== 'function') {
    message.error('编辑器尚未初始化');
    return false;
  }

  const mediaHtml = files
    .map((file) => buildMediaHtml(file, type))
    .filter(Boolean)
    .join('');

  if (!mediaHtml) {
    message.error(type === 'image' ? '图片地址为空' : '视频地址为空');
    return false;
  }

  editor.focus();
  (editor as any).dangerouslyInsertHtml(mediaHtml);
  if (type === 'video') {
    focusEditorEnd(editor);
  }
  return true;
};

const openUploadPicker = async (type: 'image' | 'video') => {
  if (props.disabled) {
    return;
  }

  editorRef.value?.focus();
  uploadModalType.value = type;
  uploadValue.value = type === 'image' ? [] : undefined;
  await nextTick();
  await uploadRef.value?.open();
};

const removeSelectedMedia = () => {
  if (props.disabled) {
    return;
  }

  const editor = editorRef.value;
  if (!editor) {
    message.error('编辑器尚未初始化');
    return;
  }

  const selectedNode =
    DomEditor.getSelectedNodeByType(editor, 'image') ||
    DomEditor.getSelectedNodeByType(editor, 'video');
  if (!selectedNode) {
    message.warning('请先选中要删除的图片或视频');
    return;
  }

  SlateTransforms.removeNodes(editor, {
    at: DomEditor.findPath(editor, selectedNode),
    voids: true,
  });
  editor.focus();
};

const handleUploadValueChange = (
  value: FileInfo | FileInfo[] | string | undefined,
) => {
  uploadValue.value = value;
  const files = selectedUploadFiles.value;
  if (files.length === 0) {
    return;
  }

  if (insertMediaFiles(files, uploadModalType.value)) {
    uploadValue.value = uploadModalType.value === 'image' ? [] : undefined;
  }
};

const toolbarConfig: Partial<IToolbarConfig> = {
  excludeKeys: [
    'group-image',
    'insertImage',
    'uploadImage',
    'group-video',
    'insertVideo',
    'uploadVideo',
  ],
};

const editorConfig: Partial<IEditorConfig> = {
  placeholder: props.placeholder,
};

const handleCreated = (editor: IDomEditor) => {
  editorRef.value = editor;
  if (props.disabled) {
    editor.disable();
  }
};

const handleEditorValueUpdate = (value: string) => {
  html.value = value;
  emitModelValue(value);
};

onBeforeUnmount(() => {
  const editor = editorRef.value;
  if (editor) {
    editor.destroy();
  }
});
</script>

<template>
  <div class="rich-text-editor">
    <Toolbar
      :editor="editorRef"
      :default-config="toolbarConfig"
      class="rich-text-editor__toolbar"
      mode="default"
    />
    <div class="rich-text-editor__media-tools">
      <a-tooltip title="插入图片">
        <button
          type="button"
          class="rich-text-editor__tool-button"
          :disabled="disabled"
          @click="openUploadPicker('image')"
        >
          <IconifyIcon icon="lucide:image-plus" />
        </button>
      </a-tooltip>
      <a-tooltip title="插入视频">
        <button
          type="button"
          class="rich-text-editor__tool-button"
          :disabled="disabled"
          @click="openUploadPicker('video')"
        >
          <IconifyIcon icon="lucide:video" />
        </button>
      </a-tooltip>
      <a-tooltip title="删除选中图片或视频">
        <button
          type="button"
          class="rich-text-editor__tool-button"
          :disabled="disabled"
          @click="removeSelectedMedia"
        >
          <IconifyIcon icon="lucide:trash-2" />
        </button>
      </a-tooltip>
    </div>
    <Editor
      :model-value="html"
      :default-config="editorConfig"
      class="rich-text-editor__body"
      mode="default"
      :style="{ height: editorHeight }"
      @update:model-value="handleEditorValueUpdate"
      @onBlur="emit('blur')"
      @onCreated="handleCreated"
    />

    <a-form-item-rest>
      <Upload
        ref="uploadRef"
        :module="uploadModule()"
        :related-id="relatedId"
        :type="uploadFieldType"
        :value="uploadValue"
        trigger="manual"
        @update:value="handleUploadValueChange"
      />
    </a-form-item-rest>
  </div>
</template>

<style scoped>
.rich-text-editor {
  --editor-bg: hsl(var(--card));
  --editor-bg-sub: hsl(var(--card));
  --editor-border: hsl(var(--border));
  --editor-text: hsl(var(--foreground));
  --editor-text-muted: hsl(var(--muted-foreground));

  /* 使用 wangEditor 官方 CSS 变量做主题适配 */
  --w-e-textarea-bg-color: var(--editor-bg);
  --w-e-textarea-color: var(--editor-text);
  --w-e-textarea-border-color: var(--editor-border);
  --w-e-textarea-slight-border-color: var(--editor-border);
  --w-e-textarea-slight-color: var(--editor-text-muted);
  --w-e-textarea-slight-bg-color: hsl(var(--popover));
  --w-e-toolbar-color: var(--editor-text);
  --w-e-toolbar-bg-color: var(--editor-bg-sub);
  --w-e-toolbar-active-color: var(--editor-text);
  --w-e-toolbar-active-bg-color: hsl(var(--popover));
  --w-e-toolbar-disabled-color: var(--editor-text-muted);
  --w-e-toolbar-border-color: var(--editor-border);
  --w-e-modal-button-bg-color: hsl(var(--popover));
  --w-e-modal-button-border-color: var(--editor-border);

  overflow: hidden;
  border: 1px solid var(--editor-border);
  border-radius: 8px;
  color: var(--editor-text);
  background: var(--editor-bg);
  position: relative;
}

.rich-text-editor__toolbar {
  min-width: 0;
  padding-right: 128px;
  border-bottom: 1px solid var(--editor-border);
  background: var(--editor-bg-sub);
}

.rich-text-editor__media-tools {
  position: absolute;
  top: 0;
  right: 0;
  z-index: 2;
  display: flex;
  align-items: center;
  gap: 2px;
  min-height: 40px;
  padding: 4px 8px;
  border-left: 1px solid var(--editor-border);
  background: var(--editor-bg-sub);
}

.rich-text-editor__tool-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  padding: 0;
  border: 0;
  border-radius: 4px;
  color: var(--editor-text);
  background: transparent;
  cursor: pointer;
}

.rich-text-editor__tool-button:not(:disabled):hover {
  background: hsl(var(--popover));
}

.rich-text-editor__tool-button:disabled {
  color: var(--editor-text-muted);
  cursor: not-allowed;
  background: transparent;
}

.rich-text-editor__body {
  min-height: 0;
  overflow: hidden;
  color: var(--editor-text);
  background: var(--editor-bg);
}

.rich-text-editor.w-e-full-screen-container {
  position: fixed !important;
  inset: 0 !important;
  display: flex !important;
  flex-direction: column !important;
  width: 100% !important;
  height: 100% !important;
  margin: 0 !important;
  padding: 0 !important;
  z-index: 2100;
  border: 0;
  border-radius: 0;
  box-shadow: none;
}

.rich-text-editor.w-e-full-screen-container .rich-text-editor__body {
  flex: 1;
  min-height: 0;
}

.rich-text-editor :deep(.w-e-text-container),
.rich-text-editor :deep(.w-e-scroll) {
  height: 100%;
  min-height: 0;
}

.rich-text-editor :deep(.w-e-text-container) {
  overflow: hidden;
}

.rich-text-editor :deep(.w-e-scroll) {
  overflow-y: auto;
}

.rich-text-editor :deep([data-slate-editor]) {
  box-sizing: border-box;
  height: 100%;
  min-height: 100%;
  overflow-y: auto;
}

.rich-text-editor :deep(.w-e-text-container [data-slate-editor]) {
  padding: 12px 14px;
  background: var(--editor-bg);
}

.rich-text-editor :deep(.w-e-text-container [data-slate-editor] > p) {
  min-height: 24px;
}

.rich-text-editor :deep(.w-e-textarea-video-container) {
  background-image:
    linear-gradient(
      45deg,
      hsl(var(--popover)) 25%,
      transparent 0,
      transparent 75%,
      hsl(var(--popover)) 0,
      hsl(var(--popover))
    ),
    linear-gradient(
      45deg,
      hsl(var(--popover)) 25%,
      hsl(var(--card)) 0,
      hsl(var(--card)) 75%,
      hsl(var(--popover)) 0
    );
}
</style>
