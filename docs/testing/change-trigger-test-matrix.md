# 迭代 0+4 测试基线与变更触发矩阵

## 1. 可执行测试入口

### 后端（ThinkPHP）

- 安装依赖（首次或 `composer.json` 变更后）：
  - `composer install --working-dir backend`
- 运行基线测试：
  - `composer --working-dir backend test`

说明：
- `composer test` 已映射到 `vendor/bin/phpunit -c phpunit.xml`
- 当前包含 1 个最小 smoke 骨架（含 1 个待补测试占位）

### 前端（Admin Web-Antd E2E）

- 安装前端依赖（首次）：
  - `pnpm --dir frontend/admin install`
- 安装 Playwright 浏览器（E2E 前置）：
  - `pnpm --dir frontend/admin run test:e2e:install`
- 运行 E2E：
  - `pnpm --dir frontend/admin run test:e2e`

说明：
- 默认 `test:e2e` 已切换为 `@vben/web-antd`（真实业务前端）。
- 如需运行框架示例 E2E：`pnpm --dir frontend/admin run test:e2e:play`。

## 2. 变更触发测试矩阵

| 变更范围 | 触发级别 | 必跑命令 | 目的 |
| --- | --- | --- | --- |
| `backend/app/**` 控制器/服务/模型逻辑 | 必跑 | `composer --working-dir backend test` | 快速确认框架加载与基础测试入口可用 |
| `backend/config/**`、`backend/route/**` | 必跑 | `composer --working-dir backend test` | 防止基础配置变更导致测试入口不可执行 |
| `backend/composer.json`、`backend/phpunit.xml`、`backend/tests/**` | 必跑 | `composer install --working-dir backend` + `composer --working-dir backend test` | 验证依赖与测试配置一致 |
| `frontend/admin/apps/web-antd/**` 的路由、页面、API 调用 | 必跑 | `pnpm --dir frontend/admin run test:e2e:install` + `pnpm --dir frontend/admin run test:e2e` | 验证真实业务前端与后端接口链路 |
| `frontend/admin/playground/**` | 可选 | `pnpm --dir frontend/admin run test:e2e:play` | 验证 vben 示例功能，不作为业务回归主入口 |
| `frontend/admin/package.json`、`frontend/admin/apps/web-antd/package.json` | 必跑 | `pnpm --dir frontend/admin run test:e2e:install` | 验证 Web-Antd E2E 脚本可用 |
| 仅文档（`docs/**`）变更 | 可选 | 无强制；建议抽样执行后端或前端一项 | 保持 CI 成本可控 |

## 3. 当前最小 smoke 覆盖范围

- 文件：`backend/tests/Smoke/ApplicationSmokeTest.php`
- 已覆盖：
  - ThinkPHP 核心类可加载（`class_exists(think\App::class)`）
- 已预留待补：
  - HTTP `/health` 或 `/ping` 端点 smoke（`markTestIncomplete`）
