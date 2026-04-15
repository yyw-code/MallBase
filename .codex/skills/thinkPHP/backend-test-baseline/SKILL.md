# Skill: backend-test-baseline

## 适用场景

- 任何后端 PHP 代码改动（Controller / Service / Model / Middleware / Route / Config）
- 需要确认后端测试是否可执行、是否回归

## 强制规则

1. 改动后端代码时，默认执行后端测试；除非用户明确跳过。
2. 不允许只口头说“已测试”，必须给出执行命令和结果。
3. 若失败，必须给出首个失败点（文件/行号/错误摘要）和下一步修复方案。

## 标准命令

```bash
composer install --working-dir backend
composer --working-dir backend test
```

说明：
- `composer install` 仅首次或依赖变化时需要全量执行。
- 常规回归可直接跑 `composer --working-dir backend test`。

## 最小交付格式（测试角色）

1. 已执行命令
2. 结果（通过/失败）
3. 失败定位（如失败）
4. 风险结论（是否可继续合并）
