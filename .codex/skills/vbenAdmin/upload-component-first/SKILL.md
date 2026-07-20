---
name: upload-component-first
description: MallBase Vben Admin 上传组件与字段契约规则；实现图片、视频或文件上传，以及处理 FileInfo 回填和提交值时使用。
---

# Vben 规则：上传组件与字段契约

## 适用范围

`frontend/admin/apps/web-antd` 中的图片、视频和文件上传交互，以及编辑回填、预览和提交转换。

## 强制规则

1. 优先使用 `#/components/upload/index.vue`，禁止在业务页面重复实现上传或素材选择逻辑。
2. UI 状态使用 `FileInfo`；`url` 保存可提交值，`full_url` 仅用于预览，`asset_id` 保存素材 ID。
3. 新素材契约优先提交数值型 `asset_id`；只有真实后端接口明确接受存储路径时，才回退提交 `url` 字符串。
4. 后端字段类型是最终依据。商品媒体字段遵循 `GoodsApi.MediaValue = number | string`，其他模块不得未经核对直接照搬。
5. `full_url`、`*_full_url` 和前端拼接的完整地址不得作为持久化提交值。
6. 编辑回填使用后端返回的素材值与完整预览地址构造 `FileInfo`，不在页面硬编码域名。
7. 多图、排序或关联对象必须保留接口要求的数组/对象结构，不能统一粗暴转换成字符串数组。
8. 批量编辑若覆盖媒体字段，必须同时处理回填、预览和提交转换。

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
<Upload v-model:value="formData.file" type="file" module="setting" />
```

- `v-model:value` 单文件绑定类型通常为 `FileInfo | string | undefined`
- `v-model:value` 多文件绑定类型通常为 `FileInfo[]`
- `module` 指定上传模块（如 `"goods"`、`"user"` 等）

## 契约边界示例

```typescript
function toMediaValue(file?: FileInfo | string): number | string {
  if (!file) return '';
  if (typeof file === 'string') {
    return /^\d+$/.test(file) ? Number(file) : file;
  }
  if (file.asset_id) return file.asset_id;
  return /^\d+$/.test(file.url) ? Number(file.url) : file.url;
}
```

该示例只适用于后端接受 `number | string` 的媒体字段。提交前仍需核对对应的 `backend/route/api/admin/*.php`、Controller/Service 校验和前端 API 类型。

## 禁止

- ❌ 使用 `<a-input>` 让用户手动输入图片 URL
- ❌ 手动实现图片上传逻辑
- ❌ 把 `full_url` 提交到只接受素材 ID 或存储路径的字段
- ❌ 看到 `FileInfo.url` 就假设它一定是文件路径

## 参考位置

- `frontend/admin/apps/web-antd/src/components/upload/index.vue`
- `frontend/admin/apps/web-antd/src/components/upload/index.ts`
- `frontend/admin/apps/web-antd/src/views/goods/goods/composables/useGoodsEdit.ts`
- `frontend/admin/apps/web-antd/src/api/goods/goods.ts`

## 自检清单

- [ ] 上传逻辑未重复造轮子。
- [ ] 回填对象包含可靠的提交值、预览地址和文件名。
- [ ] 素材 ID、兼容路径、单图/多图结构与真实后端契约一致。
- [ ] 提交数据不包含 `full_url`，保存后二次进入仍能稳定回显。
