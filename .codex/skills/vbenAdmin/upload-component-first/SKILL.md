# Vben 规则：上传优先复用 Upload 组件

## 适用范围

所有图片/文件上传交互。

## 强制规则

1. 优先使用 `#/components/upload/index.vue`。
2. 禁止让用户手填文件 URL 代替上传能力。
3. 提交前按后端协议做 `FileInfo -> string/数组` 转换。

## 引入方式

```vue
import type { FileInfo } from '#/components/upload';
import Upload from '#/components/upload/index.vue';
```

## 用法示例

```vue
<!-- 单图上传 -->
<Upload v-model:value="formData.image" type="image" module="goods" />

<!-- 多图上传 -->
<Upload v-model:value="formData.images" type="images" module="goods" :max-count="10" />

<!-- 文件上传 -->
<Upload v-model:value="formData.file" type="file" module="xxx" />
```

- `v-model:value` 单图绑定类型：`FileInfo | string | undefined`
- `v-model:value` 多图绑定类型：`FileInfo[]`
- `module` 指定上传模块（如 `"goods"`、`"user"` 等）

## 提交时类型转换

```typescript
const submitData = {
  ...formData,
  main_image: typeof formData.main_image === 'object'
    ? formData.main_image?.url || ''
    : formData.main_image || '',
  images: formData.images.map((img, index) => ({
    url: typeof img === 'object' ? img.url : img,
    sort: index,
  })),
};
```

## 禁止

- ❌ 使用 `<a-input>` 让用户手动输入图片 URL
- ❌ 手动实现图片上传逻辑

## 参考位置

- `frontend/admin/apps/web-antd/src/components/upload/index.vue`

## 自检清单

- [ ] 上传逻辑未重复造轮子。
- [ ] 单图/多图数据结构与后端一致。
