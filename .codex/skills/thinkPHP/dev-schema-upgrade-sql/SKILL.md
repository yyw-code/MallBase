---
name: dev-schema-upgrade-sql
description: MallBase 数据库真相源、正式字段与升级 SQL 规则；修改 backend/install/data/schema 或 demo seed、新增/改名字段、移除运行时字段探测，或用户明确要求已有库升级、本地 upgrade SQL、发布迁移和 SimpleSqlMigrationService 时使用。
---

# Schema、正式字段与升级 SQL

## 真相源

1. 全新安装的表结构和初始配置以 `backend/install/data/schema/*.sql` 为真相源；演示数据放在 `backend/install/data/demo/*.sql`。
2. 修改字段或 seed 时先更新真相源，并同步 Controller 参数、Validate 场景、Model、Service、前端类型和测试中实际受影响的契约。
3. `schema/*.sql` 面向全新安装，可能包含 `DROP TABLE`；不要把整份安装 schema 直接用于已有业务库。

## 正式字段

字段结构定型后，业务代码直接依赖正式 schema。不要为了绕过未执行迁移而增加以下长期兼容：

- `SHOW COLUMNS`、`DESCRIBE` 或 `information_schema` 运行时探测。
- `has*Column()`、`filter*Data()` 一类按列存在性静默丢字段。
- “有新字段就写，没有就降级”的双轨持久化。

若真实数据库缺字段，修复部署或迁移，不在请求链路隐藏结构不一致。仅输入/输出归一化且不探测数据库结构的协议兼容可以保留，并应有清晰边界。

## 已有库边界

只有用户明确要求“已有库升级”“升级 SQL”“测试服/线上迁移”或等价目标时，才规划和生成增量 SQL。普通功能、schema、seed 或 UI 任务不要主动扩大到已有库升级，也不要把它列成默认风险项。

### 本地手工 SQL

`backend/install/data/upgrade/` 是 `.gitignore` 中的本地运维目录，不是发布迁移真相源：

1. 仅在用户明确要求本地或指定环境升级 SQL 时创建。
2. 使用 `YYYY_MM_DD_<summary>.sql` 一类可识别名称。
3. 写成可安全重复执行的目标 MySQL SQL；建表、加列、加索引和 seed 都要使用目标版本支持的合法幂等方式，并在对应 MySQL 版本验证。
4. 不提交该目录，不把它与正式发布迁移混为一谈。

### 发布升级迁移

`backend/app/service/upgrade/SimpleSqlMigrationService.php` 执行发布流程已经暂存到升级根目录的迁移：

- 运行时路径必须是 `upgrade/staging/<jobId>/migrations/<migrationId>.sql` 对应的 `migrations/<migrationId>.sql`。
- 服务校验 migration ID、版本、SHA-256，并在 `upgrade/run/simple-migrations.json` 记录语句级检查点。
- 多条语句用独占行 `-- mallbase:statement-breakpoint` 分隔；每段只能是一条 SQL。
- 不在迁移文件中使用 `DELIMITER`、`SOURCE`、`LOAD DATA LOCAL` 或自行控制 `BEGIN/COMMIT/ROLLBACK/AUTOCOMMIT`。
- 迁移语句仍应可安全重试，不能依赖本地 `backend/install/data/upgrade/` 被发布系统自动发现。

只有任务明确涉及发布包或升级平台时，才把变更接入这条发布迁移链路。

## 自检

- [ ] 全新安装 schema 已包含最终结构。
- [ ] 请求链路没有运行时字段探测或静默丢字段。
- [ ] 已有库 SQL 确实来自用户明确要求。
- [ ] 本地手工 SQL 与发布迁移的来源、路径和提交边界没有混用。
- [ ] SQL 已在目标 MySQL 版本验证语法与幂等性。
