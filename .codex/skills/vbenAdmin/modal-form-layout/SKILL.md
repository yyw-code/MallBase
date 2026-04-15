# Vben 规则：Modal 表单布局一致

## 适用范围

后台管理弹窗表单（新增/编辑）。

## 强制规则

1. 统一采用水平布局（标签左、控件右）。
2. 保持统一标签宽度与间距风格。
3. 避免在不同页面出现明显布局漂移。

## 标准配置

```vue
<a-form
  ref="formRef"
  :model="formData"
  :rules="rules"
  :label-col="{ style: { width: '100px' } }"
  class="pt-4"
>
```

- `:label-col="{ style: { width: '100px' } }"` — 标签固定 100px 宽度
- `class="pt-4"` — 顶部内边距
- 不设置 `layout="vertical"`（Ant Design 默认即 horizontal）

## 禁止

- ❌ 使用 `layout="vertical"`（标签在控件上方）
- ❌ 使用 `class="mt-4"` 作为表单容器类（应使用 `pt-4`）

## 参考位置

- `frontend/admin/apps/web-antd/src/views/user/user-modal.vue`

## 自检清单

- [ ] 同类页面表单观感一致。
- [ ] 无随意切换到垂直布局的情况。
- [ ] 标签宽度统一（`100px`）。
