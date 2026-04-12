<script lang="ts" setup>
import type { IEditorConfig, IDomEditor, IToolbarConfig } from '@wangeditor/editor';

import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

import { message } from 'ant-design-vue';
import { Editor, Toolbar } from '@wangeditor/editor-for-vue';

import { getUploadConfigApi, uploadSingleApi } from '#/api/core/upload';

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
const uploadConfig = ref<{
  acceptTypes: string[];
  maxSize: number;
} | null>(null);

const loadUploadConfig = async () => {
  try {
    const result = await getUploadConfigApi('image');
    uploadConfig.value = {
      acceptTypes: result.accept_types || [],
      maxSize: result.max_size || 5,
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

const validateImageFile = (file: File) => {
  const config = uploadConfig.value;
  if (!config) {
    return true;
  }

  if (
    config.acceptTypes.length > 0 &&
    !config.acceptTypes.includes(file.type)
  ) {
    message.error('不支持的图片类型');
    return false;
  }

  if (file.size / 1024 / 1024 > config.maxSize) {
    message.error(`图片大小不能超过 ${config.maxSize}MB`);
    return false;
  }

  return true;
};

const toolbarConfig: Partial<IToolbarConfig> = {
  excludeKeys: ['fullScreen', 'group-video'],
};

const editorConfig: Partial<IEditorConfig> = {
  MENU_CONF: {
    uploadImage: {
      async customUpload(file: File, insertFn: (url: string, alt?: string, href?: string) => void) {
        try {
          if (!validateImageFile(file)) {
            return;
          }
          const result = await uploadSingleApi(file, {
            type: 'image',
            module: props.module,
            related_id: props.relatedId,
          });
          insertFn(result.full_url || result.url, result.name, result.full_url || result.url);
        } catch (error) {
          console.error('富文本图片上传失败:', error);
          message.error('图片上传失败');
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
  overflow: hidden;
  border: 1px solid var(--ant-colorBorder, #d9d9d9);
  border-radius: 8px;
  color: var(--ant-colorText, #262626);
  background: var(--ant-colorBgContainer, #fff);
}

.rich-text-editor__toolbar {
  border-bottom: 1px solid var(--ant-colorBorderSecondary, #f0f0f0);
  background: var(--ant-colorBgContainer, #fff);
}

.rich-text-editor__body {
  overflow-y: auto;
  color: var(--ant-colorText, #262626);
  background: var(--ant-colorBgContainer, #fff);
}

.rich-text-editor :deep(.w-e-text-container [data-slate-editor]) {
  padding: 12px 14px;
  color: var(--ant-colorText, #262626);
  background: transparent;
}

.rich-text-editor :deep(.w-e-text-container) {
  background: transparent;
}

.rich-text-editor :deep(.w-e-bar) {
  background: transparent;
}

.rich-text-editor :deep(.w-e-bar-item button),
.rich-text-editor :deep(.w-e-bar-item .menu-item) {
  color: var(--ant-colorText, #262626);
}

.rich-text-editor :deep(.w-e-bar-divider) {
  border-left-color: var(--ant-colorBorderSecondary, #f0f0f0);
}
</style>
