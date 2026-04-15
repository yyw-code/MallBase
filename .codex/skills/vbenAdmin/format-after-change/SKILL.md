---
name: format-after-change
description: 修改 frontend/admin/apps/web-antd 代码后使用局部格式化命令整理本次改动文件，避免把整个前端 monorepo 一起格式化。适用于 Vben Admin 页面、组件、API、路由与 composables 代码修改后的收口阶段。
---

# web-antd 局部格式化

适用场景：

- 修改 `frontend/admin/apps/web-antd` 下的 Vue、TS、路由、API、composables 文件
- 提交前需要统一格式，但不希望扩散到整个前端 monorepo

## 硬规则

1. 修改 `frontend/admin/apps/web-antd` 代码后，提交前必须格式化。
2. 默认只格式化本次改动文件，禁止无边界全量格式化整个 monorepo。
3. 如果仓库主脚本会扩散到其它 package，不使用全仓脚本，改用局部 `eslint --fix` 或 `prettier --write`。
4. 列表页只要存在搜索筛选表单，就必须同时提供显式 `搜索` 和 `重置` 按钮。

## 推荐命令

优先对本次改动文件执行：

```bash
pnpm --dir frontend/admin exec eslint --fix <web-antd改动文件...>
```

如果某类文件不受 eslint 处理，再补：

```bash
pnpm --dir frontend/admin exec prettier --write <目标文件...>
```

## 禁止事项

- 禁止默认执行 `pnpm --dir frontend/admin format`，除非用户明确要求全仓前端格式化。
- 禁止因为格式化带出 `apps/web-antd` 之外的大量无关 diff。
- 禁止跳过格式化直接提交 `web-antd` 代码改动。
