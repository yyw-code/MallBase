---
name: dev-schema-upgrade-sql
description: MallBase schema 真相源与幂等升级 SQL 规则；升级 SQL 仅在用户明确要求已有库升级时使用。
---

# Skill: dev-schema-upgrade-sql

## 适用场景

- 在 `deploy/install/data/schema/*.sql` 增加/修改字段、表、初始数据
- 在 `deploy/install/data/demo/*.sql` 增加/修改演示数据
- 任何 `mb_setting` / `mb_goods` / `mb_user` 等表的 seed 调整

## 边界

已有库升级 SQL 只有在用户明确要求考虑“已有库升级”“升级 SQL”
“测试服/线上升级”或等价场景时才纳入任务。未明确要求时，
不要主动规划、创建或提醒 `deploy/install/data/upgrade/` / `backend/install/data/upgrade/`
升级 SQL；先按当前任务真相源收口。

如需判断是否应该考虑升级 SQL，先读取并遵循
`thinkPHP/upgrade-sql-explicit-request`。

## 背景

全新安装会按顺序执行 `schema/*.sql`。但**已部署环境**（测试服 / 线上）不会重装，
新增的 schema 文件不会自动补到这些库上 —— 典型故障：代码引用了新表，
线上报 `Base table or view not found`。但这类已有库升级只在用户明确要求时纳入任务。

## 强制规则

1. **schema 文件是唯一真相源**：所有表/字段/seed 变更**必须**直接改
   `deploy/install/data/schema/*.sql`，保证全新安装即正确。
2. **按需产出增量升级 SQL**：只有用户明确要求处理已有库升级时，才在
   `deploy/install/data/upgrade/` 下新增一个对应的增量文件，
   命名 `YYYY_MM_DD_<简述>.sql`（如 `2026_05_20_payment_log.sql`）。
3. **升级 SQL 必须幂等**，可重复执行无副作用：
   - 建表用 `CREATE TABLE IF NOT EXISTS`，**禁止** `DROP TABLE`。
   - 加字段 / 加索引前先判断是否已存在再 `ALTER`，或用等效幂等写法。
   - seed 用 `INSERT ... ON DUPLICATE KEY UPDATE` 或先 `DELETE` 再 `INSERT`。
4. **升级文件不入库**：`deploy/install/data/upgrade/` 已在 `.gitignore`，
   增量 SQL 属于按环境手动执行的运维产物，不提交 git。
5. 升级文件结构须与对应 schema 文件保持一致（同一张表的定义两边不能漂移）。

## 暂不做

- **不写统一升级脚本**：升级动作可能不止 SQL（静态文件、配置等），
  暂由人工按需执行，不强行抽象成一个脚本。
- **不建升级追踪表**：靠 SQL 自身幂等保证可重复执行，不维护 `mb_schema_upgrade` 之类的记录表。

## 标准动作

1. 改结构：编辑 `deploy/install/data/schema/<对应>.sql`（真相源）。
2. 若用户明确要求已有库升级：在 `deploy/install/data/upgrade/` 新增幂等的 `YYYY_MM_DD_<简述>.sql`。
3. 若产出了升级 SQL，再通知用户两件事：
   - 本地 / 全新环境：清库重装即可拿到新结构。
   - 已部署环境（测试服 / 线上）：手动执行对应升级 SQL，例如
     `mysql -h<HOST> -u<USER> -p <DB> < deploy/install/data/upgrade/<文件>.sql`
4. commit / PR / 文档只提及 schema 改动，不提及 upgrade 文件（其不入库）。

## 反例

```
❌ 用户明确要求已有库升级时，只改 schema/10_xxx.sql，不补 upgrade 增量
❌ upgrade 文件里写 DROP TABLE                  ← 重复执行会丢数据，非幂等
❌ 把 deploy/install/data/upgrade/*.sql 提交 git ← 属于运维产物，不入库
✅ schema/10_mb_payment_log.sql + upgrade/2026_05_20_payment_log.sql（CREATE TABLE IF NOT EXISTS）
```
