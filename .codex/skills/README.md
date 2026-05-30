# MallBase 项目本地 Skills 说明

本目录是 **项目私有技能库**，仅服务当前仓库，不依赖全局环境。

## 目录约定

- 一级按技术栈或项目治理域分组：`thinkPHP` / `vbenAdmin` / `uniapp` / 通用治理规则
- 二级按规则命名：`<rule-name>/SKILL.md`
- 每个 `SKILL.md` 只描述一条核心规则，避免耦合

## 触发建议

- 修改后端分层、事务、路由：优先看 `thinkPHP/*`
- 修改后台前端页面与 API：优先看 `vbenAdmin/*`
- 修改移动端（后续接入）：优先看 `uniapp/*`
- 执行后端回归：优先看 `thinkPHP/backend-test-baseline`
- 执行后台真实链路 E2E：优先看 `vbenAdmin/e2e-webantd-realapi`
- 涉及演示站分支、preview 合并或反向合并：必须看 `preview-branch-boundary`

## 治理分层

项目本地 skills 当前按「高频核心 / 条件触发 / 预留」三层维护，避免规则数量增加后误触发。

### 高频核心

日常开发中优先独立触发，保持为单独 skill：

- `thinkPHP/architecture-layering`
- `thinkPHP/service-stateless-swoole`
- `thinkPHP/validate-then-transact`
- `thinkPHP/list-query-sync`
- `thinkPHP/list-return-compact`
- `thinkPHP/route-permission-system`
- `vbenAdmin/backend-driven-routing`
- `vbenAdmin/api-path-param`
- `vbenAdmin/upload-component-first`
- `vbenAdmin/upload-field-normalize`
- `vbenAdmin/format-after-change`

### 条件触发

仅在任务明确涉及对应领域时触发，不作为默认背景规则：

- `thinkPHP/build-list-query-only`
- `thinkPHP/ide-generic-annotation`
- `thinkPHP/goods-image-main-sync`
- `thinkPHP/goods-media-contract`
- `thinkPHP/goods-sku-unified-sales-unit`
- `thinkPHP/model-field-accessor-first`
- `thinkPHP/schema-first-no-transition-compat`
- `thinkPHP/region-snapshot-invalid-on-change`
- `thinkPHP/mall-base-boundary`
- `thinkPHP/backend-test-baseline`
- `vbenAdmin/admin-theme-consistency`
- `vbenAdmin/iconpicker-standard`
- `vbenAdmin/modal-form-layout`
- `vbenAdmin/goods-spec-toggle-state`
- `vbenAdmin/e2e-webantd-realapi`
- `docs-linking`
- `open-source-wording`
- `preview-branch-boundary`

### 预留

UniApp 规则仅在明确涉及 `frontend/uniapp` 时触发：

- `uniapp/api-contract`
- `uniapp/auth-token-flow`
- `uniapp/module-structure`

## 索引类 skill

- `thinkPHP/SKILL.md`、`vbenAdmin/SKILL.md`、`uniapp/SKILL.md` 只作为规则导航入口。
- 能确定具体规则时，优先使用子目录 skill，不加载父级索引。
- 新增规则前，先判断是否能并入现有条件触发规则，避免规则碎片化。

## 设计原则

1. 一条规则一个 skill，方便按需启用
2. 规则必须可落地并有自检清单
3. 与 `CLAUDE.md`、`.claude/skills/learned` 保持一致
