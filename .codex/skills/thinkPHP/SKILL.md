---
name: thinkphp
description: MallBase ThinkPHP 后端规则导航；仅在后端任务涉及多个场景、需要查找项目规则，或无法确定应读取哪个更具体的 ThinkPHP skill 时使用。
---

# MallBase ThinkPHP 规则导航

优先读取与任务直接匹配的具体 skill，不要把本文件当成后端规则全集。

## 快速选择

- 处理 Controller、Service、Model、Swoole 状态或 IDE 泛型时，读取 `architecture-layering`。
- 处理写操作、事务、行锁或并发校验时，读取 `validate-then-transact`。
- 处理分页、筛选、统计、导出或 `{total, list}` 返回时，读取 `list-query-sync`。
- 处理后台菜单、路由名、路径参数或权限同步时，读取 `route-permission-system`。
- 处理 schema、正式字段、已有库升级或发布迁移时，读取 `dev-schema-upgrade-sql`。

## 高风险与领域规则

支付、商品媒体、SKU、地区快照和 `backend/mall_base/` 边界都有独立 skill。先根据 frontmatter 的 `description` 选择，不要只套通用规则。

需要查看当前可用规则时，从项目根目录执行：

```bash
rg -n '^description:' .codex/skills/thinkPHP/*/SKILL.md
```

任务同时命中多个场景时，读取所有直接相关的 skill，并以更具体、风险更高的规则为准。
