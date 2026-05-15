---
name: dev-no-upgrade-sql
description: MallBase 开发阶段数据库变更只改 schema/demo seed，不维护 upgrade 增量；新增字段、表、初始数据时使用。
---

# Skill: dev-no-upgrade-sql

## 适用场景

- 在 `deploy/install/data/schema/*.sql` 增加/修改字段、表、初始数据
- 在 `deploy/install/data/demo/*.sql` 增加/修改演示数据
- 任何 `mb_setting` / `mb_goods` / `mb_user` 等表的 seed 调整

## 强制规则

1. **不创建任何 upgrade / migration 增量 SQL**（如 `2026_05_14_xxx.sql`）。
2. 所有变更**直接修改对应的 schema 或 demo 文件**，让全新安装就能拿到正确结果。
3. 不允许新建 `deploy/install/data/upgrade/`、`backend/migrations/`、`backend/database/migrations/` 等增量目录。
4. 若发现仓库里已存在 upgrade SQL，**应该删除**并把内容合并回 schema/demo。

## 原因

- MallBase 当前处于开发阶段，开发者随时清库重装：`php think install --fresh` 或 admin 安装向导重跑。
- 维护 upgrade 链路在开发期纯增加负担：每加一个字段写两份（schema + upgrade），没有任何线上数据需要保护。
- 上线后再切换策略由项目主导决定，不在本 skill 范围内。

## 反例

```
❌ deploy/install/data/upgrade/2026_05_14_splash.sql   ← 不允许
❌ backend/database/migrations/add_splash_config.php   ← 不允许
✅ 直接在 deploy/install/data/schema/03_mb_setting.sql 末尾追加 INSERT
✅ 直接在 deploy/install/data/demo/02_demo_goods.sql 追加商品
```

## 标准动作

1. 改字段：编辑 `deploy/install/data/schema/<对应>.sql`
2. 改 seed：编辑 `deploy/install/data/schema/<对应>.sql` 或 `deploy/install/data/demo/<对应>.sql`
3. 改完通知用户「清库重装即可」，不需要执行任何升级脚本
4. 不在 commit message / PR / 文档中提及 upgrade / migration

## 例外

仅当用户**明确要求**「这是给已部署环境用的升级」时，才生成独立的 upgrade SQL。默认一律按本 skill 执行。
