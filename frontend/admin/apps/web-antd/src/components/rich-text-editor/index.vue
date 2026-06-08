<script lang="ts" setup>
import type { IEditorConfig, IDomEditor, IToolbarConfig } from '@wangeditor/editor';

import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

import { message } from 'ant-design-vue';
import { Editor, Toolbar } from '@wangeditor/editor-for-vue';

import { uploadSingleApi } from '#/api/core/upload';
import { getUploadConfigCached } from '#/api/core/upload-config-cache';

import '@wangeditor/editor/dist/css/style.css';

interface Props {
  modelValue?: string;
  placeholder?: string;
  height?: number;
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
const html = ref(props.modelValue);
const imageUploadConfig = ref<{
  acceptTypes: string[];
  maxSize: number;
} | null>(null);
const videoUploadConfig = ref<{
  acceptTypes: string[];
  maxSize: number;
} | null>(null);

const loadUploadConfig = async () => {
  try {
    const [imageConfig, videoConfig] = await Promise.all([
      getUploadConfigCached('image'),
      getUploadConfigCached('video'),
    ]);
    imageUploadConfig.value = {
      acceptTypes: imageConfig.accept_types || [],
      maxSize: imageConfig.max_size || 5,
    };
    videoUploadConfig.value = {
      acceptTypes: videoConfig.accept_types || [],
      maxSize: videoConfig.max_size || 200,
    };
  } catch (error) {
    console.warn('富文本上传规则获取失败，使用后端兜底校验:', error);
  }
};

watch(
  () => props.modelValue,
  (value) => {
    const nextValue = value ?? '';
    if (nextValue !== html.value) {
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

const normalizeHtml = (value: string) => {
  const trimmed = value.trim();
  if (
    trimmed === '' ||
    trimmed === '<p><br></p>' ||
    trimmed === '<p></p>'
  ) {
    return '';
  }

  return value;
};

const validateFile = (
  file: File,
  config: null | { acceptTypes: string[]; maxSize: number },
  label: string,
) => {
  if (!config) {
    return true;
  }

  if (
    config.acceptTypes.length > 0 &&
    !config.acceptTypes.includes(file.type)
  ) {
    message.error(`不支持的${label}类型`);
    return false;
  }

  if (file.size / 1024 / 1024 > config.maxSize) {
    message.error(`${label}大小不能超过 ${config.maxSize}MB`);
    return false;
  }

  return true;
};

const escapeHtmlAttr = (value: string) =>
  value
    .replaceAll('&', '&amp;')
    .replaceAll('"', '&quot;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');

const uploadModule = () => props.module || 'rich_text';

const insertImageHtml = (
  result: { asset_id?: number; full_url?: string; name?: string; url?: string },
  insertFn: (url: string, alt?: string, href?: string) => void,
) => {
  const url = result.full_url || result.url || '';
  if (!url) {
    message.error('图片上传返回为空');
    return;
  }

  if (result.asset_id && editorRef.value && typeof (editorRef.value as any).dangerouslyInsertHtml === 'function') {
    (editorRef.value as any).dangerouslyInsertHtml(
      `<img src="${escapeHtmlAttr(url)}" alt="${escapeHtmlAttr(result.name || '')}" data-asset-id="${result.asset_id}">`,
    );
    return;
  }

  insertFn(url, result.name, url);
};

const insertVideoHtml = (
  result: { asset_id?: number; full_url?: string; url?: string },
  insertFn: (src: string, poster: string) => void,
) => {
  const url = result.full_url || result.url || '';
  if (!url) {
    message.error('视频上传返回为空');
    return;
  }

  if (result.asset_id && editorRef.value && typeof (editorRef.value as any).dangerouslyInsertHtml === 'function') {
    (editorRef.value as any).dangerouslyInsertHtml(
      `<video src="${escapeHtmlAttr(url)}" controls data-asset-id="${result.asset_id}"></video>`,
    );
    return;
  }

  insertFn(url, '');
};

const toolbarConfig: Partial<IToolbarConfig> = {
  excludeKeys: ['fullScreen'],
};

const editorConfig: Partial<IEditorConfig> = {
  MENU_CONF: {
    uploadImage: {
      async customUpload(file: File, insertFn: (url: string, alt?: string, href?: string) => void) {
        try {
          if (!validateFile(file, imageUploadConfig.value, '图片')) {
            return;
          }
          const result = await uploadSingleApi(file, {
            type: 'image',
            module: uploadModule(),
            related_id: props.relatedId,
          });
          insertImageHtml(result, insertFn);
        } catch (error) {
          console.error('富文本图片上传失败:', error);
          message.error('图片上传失败');
        }
      },
    },
    uploadVideo: {
      async customUpload(file: File, insertFn: (src: string, poster: string) => void) {
        try {
          if (!validateFile(file, videoUploadConfig.value, '视频')) {
            return;
          }
          const result = await uploadSingleApi(file, {
            type: 'video',
            module: uploadModule(),
            related_id: props.relatedId,
          });
          insertVideoHtml(result, insertFn);
        } catch (error) {
          console.error('富文本视频上传失败:', error);
          message.error('视频上传失败');
        }
      },
    },
  },
  placeholder: props.placeholder,
};

onMounted(loadUploadConfig);

const handleCreated = (editor: IDomEditor) => {
  editorRef.value = editor;
  if (props.disabled) {
    editor.disable();
  }
};

const handleChange = (editor: IDomEditor) => {
  const nextValue = normalizeHtml(editor.getHtml());
  html.value = nextValue;
  emit('update:modelValue', nextValue);
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
    <Editor
      v-model="html"
      :default-config="editorConfig"
      class="rich-text-editor__body"
      mode="default"
      :style="{ height: `${height}px` }"
      @onBlur="emit('blur')"
      @onChange="handleChange"
      @onCreated="handleCreated"
    />
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
}

.rich-text-editor__toolbar {
  border-bottom: 1px solid var(--editor-border);
  background: var(--editor-bg-sub);
}

.rich-text-editor__body {
  overflow-y: auto;
  color: var(--editor-text);
  background: var(--editor-bg);
}

.rich-text-editor :deep(.w-e-text-container [data-slate-editor]) {
  padding: 12px 14px;
  background: var(--editor-bg);
}

.rich-text-editor :deep(.w-e-textarea-video-container) {
  background-image:
    linear-gradient(45deg, hsl(var(--popover)) 25%, transparent 0, transparent 75%, hsl(var(--popover)) 0, hsl(var(--popover))),
    linear-gradient(45deg, hsl(var(--popover)) 25%, hsl(var(--card)) 0, hsl(var(--card)) 75%, hsl(var(--popover)) 0);
}

</style>
