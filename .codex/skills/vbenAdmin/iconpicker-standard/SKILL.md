---
name: iconpicker-standard
description: MallBase Vben Admin IconPicker 规范；实现或调整后台图标选择、菜单图标和图标表单字段时使用。
---

# Vben 规则：图标选择统一 IconPicker 模式

## 适用范围

涉及“图标字段”的表单页面。

## 强制规则

1. 使用 `IconPicker`，并提供图标集下拉切换。
2. 保持与权限页面实现一致，不自行封装替代组件。
3. 保留 `IconPicker` 自带的直接输入能力作为补充，不另建纯文本字段替代选择器。
4. `IconPicker` 使用 `v-model` 绑定图标字符串，图标集选择器使用 `v-model:value` 绑定 `iconPrefix`。
5. 图标集和提示文案跟随当前权限页真实实现，不在 Skill 中复制整段易漂移模板。

## 最小接入要点

- 引入：`import { IconPicker } from '@vben/common-ui';`
- 默认前缀：`const iconPrefix = ref('ant-design');`
- 绑定：`<IconPicker v-model="formData.icon" :prefix="iconPrefix" />`
- 图标集选项以权限页为准，当前包括 `ant-design`、`lucide`、`mdi`、`carbon`、`mdi-light`。
- 保留“也可直接输入”提示，让已有图标值和其他合法前缀可被编辑。

## 禁止

- ❌ 仅使用 `<a-input>` 让用户手动输入图标名称并替代 `IconPicker`
- ❌ 自行封装图标选择组件
- ❌ 硬编码 `prefix="lucide"` 而不提供图标集切换
- ❌ 从 Skill 复制旧模板而不核对当前参考页面

## 参考位置

- `frontend/admin/apps/web-antd/src/views/auth/permission/index.vue`（权限页主参考）
- `frontend/admin/apps/web-antd/src/views/settings/group/group-modal.vue`（弹窗接入参考）

## 自检清单

- [ ] 图标集切换可用。
- [ ] `IconPicker` 与字段绑定一致。
- [ ] 使用 `v-model` 而非 `v-model:value`。
- [ ] 实现与当前权限页一致，没有复制过期模板。
