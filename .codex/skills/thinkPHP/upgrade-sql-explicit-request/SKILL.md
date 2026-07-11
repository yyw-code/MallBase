---
name: upgrade-sql-explicit-request
description: MallBase 已有库升级 SQL 边界规则；只有用户明确要求考虑已有库升级、测试服/线上升级或 upgrade SQL 时使用。
---

# Skill: upgrade-sql-explicit-request

## 适用场景

- 用户明确提到“已有库升级”“升级 SQL”“upgrade SQL”“测试服/线上升级”“需要兼容现有数据库”
- 任务涉及 `backend/install/data/schema/*.sql`、seed 数据或页面库初始数据，但用户没有要求考虑已有库升级时，用本规则约束不要主动扩展范围
- 与 `dev-schema-upgrade-sql` 同时相关时，本规则先限定是否需要讨论或产出升级 SQL

## 强制规则

1. 未经用户明确指出，不主动规划、创建或提醒 `backend/install/data/upgrade/` 升级 SQL。
2. 默认只考虑当前任务范围内的真相源改动，例如 schema、seed 或代码本身。
3. 不把“现有库需要升级 SQL”作为常规规划项、风险项或结论项。
4. 如果用户明确要求升级，再按 `dev-schema-upgrade-sql` 处理幂等升级 SQL。
5. 不因为发现已有库可能存在旧数据，就自动扩大到升级方案；先保持当前任务边界。

## 判断标准

- 可以考虑升级 SQL：用户说“需要升级现有库”“补一个升级 SQL”“线上库怎么处理”“测试库要迁移”等。
- 不考虑升级 SQL：用户只是在讨论页面分类、初始安装数据、功能规划、局部 UI 或普通代码改动。

## 示例

用户说：“选择跳转目标里积分商城应该归到营销页面。”

正确处理：

- 分析 `mb_client_page.category` 和选择器分组。
- 如需实现，只改当前真相源或代码。
- 不主动补充“现有库需要升级 SQL”。

用户说：“这个分类改完已有库也要同步。”

正确处理：

- 再读取 `dev-schema-upgrade-sql`。
- 规划幂等 `UPDATE mb_client_page ...` 升级 SQL。
