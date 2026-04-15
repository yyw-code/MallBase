# Vben 规则：图标选择统一 IconPicker 模式

## 适用范围

涉及“图标字段”的表单页面。

## 强制规则

1. 使用 `IconPicker`，并提供图标集下拉切换。
2. 保持与权限页面实现一致，不自行封装替代组件。
3. 允许直接输入图标值作为补充能力。

## 引入方式

```vue
import { IconPicker } from '@vben/common-ui';
```

## 完整标准模板（必须完整复制此模式）

```vue
<script setup lang="ts">
const iconPrefix = ref('ant-design');
</script>

<template>
  <a-form-item label="图标" name="icon">
    <div class="flex flex-col" style="width: 100%">
      <div class="mb-2">
        <a-select
          v-model:value="iconPrefix"
          style="width: 200px"
          placeholder="选择图标集"
        >
          <a-select-option value="ant-design">Ant Design</a-select-option>
          <a-select-option value="lucide">Lucide</a-select-option>
          <a-select-option value="mdi">Material Design</a-select-option>
          <a-select-option value="carbon">Carbon</a-select-option>
          <a-select-option value="mdi-light">MDI Light</a-select-option>
        </a-select>
        <span class="sm ml-2 text-gray-400">也可直接输入，如：lucide:shield</span>
      </div>
      <IconPicker
        v-model="formData.icon"
        :prefix="iconPrefix"
        placeholder="请选择图标"
        style="width: 100%"
      />
    </div>
  </a-form-item>
</template>
```

### 关键配置说明

- `v-model` 绑定图标名称字符串（注意：使用 `v-model` 而非 `v-model:value`）
- `:prefix="iconPrefix"` 动态绑定图标集前缀（禁止硬编码为 `"lucide"`）
- 必须包含图标集下拉选择器（5 个选项）
- 必须包含提示文字"也可直接输入，如：lucide:shield"

## 禁止

- ❌ 使用 `<a-input>` 让用户手动输入图标名称
- ❌ 自行封装图标选择组件
- ❌ 硬编码 `prefix="lucide"` 而不提供图标集切换
- ❌ 省略图标集下拉选择器

## 参考位置

- `frontend/admin/apps/web-antd/src/views/auth/permission/index.vue`（唯一标准参考）

## 自检清单

- [ ] 图标集切换可用。
- [ ] `IconPicker` 与字段绑定一致。
- [ ] 包含图标集下拉选择器（5 个选项）。
- [ ] 使用 `v-model` 而非 `v-model:value`。
