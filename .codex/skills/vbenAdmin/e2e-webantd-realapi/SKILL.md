---
name: e2e-webantd-realapi
description: MallBase Vben Admin 局部格式化与真实后端 E2E 收口规则；修改、测试或回归 web-antd 代码时使用。
---

# Vben 规则：局部格式化与真实后端 E2E

## 适用场景

- `frontend/admin/apps/web-antd/**` 页面、路由、API 调用改动
- 需要验证前端是否真实打通后端接口（非 playground mock）

## 强制规则

1. 修改 `web-antd` 代码后必须先对本次改动文件做局部格式化，不默认格式化整个前端 monorepo。
2. 默认使用 `web-antd` 作为 E2E 主入口，不以 playground 或 mock 结果替代真实业务回归。
3. 登录类用例必须校验真实接口请求与响应，而不是只校验页面跳转。
4. 失败时必须区分 UI 操作、网络、CORS、环境配置、账号密码和业务断言问题。

## 局部格式化

优先对本次改动文件执行：

```bash
pnpm --dir frontend/admin exec eslint --fix <web-antd改动文件...>
```

不受 ESLint 处理的文件再补：

```bash
pnpm --dir frontend/admin exec prettier --write <目标文件...>
```

禁止默认执行 `pnpm --dir frontend/admin format`，避免带出 `apps/web-antd` 之外的大量无关差异。格式化后必须复查 diff，确认没有扩散修改。

## E2E 标准命令

```bash
pnpm --dir frontend/admin run test:e2e:install
pnpm --dir frontend/admin run test:e2e
```

首次执行先安装 Playwright 浏览器；默认脚本已指向 `@vben/web-antd`。纯文案或无行为变化的样式调整可按风险执行相关页面抽样验证，但必须说明未跑全量 E2E 的理由。

## 用例落地约束

1. 用例目录：`frontend/admin/apps/web-antd/__tests__/e2e/`
2. 登录页使用 `?e2e=1` 进入测试模式（用于跳过滑块等非核心链路阻塞）
3. 对登录请求至少断言：
   - 请求路径包含 `/auth/admin/login`
   - 响应存在且可读
   - 业务码 `code === 200`

## 最小交付格式（测试角色）

1. 已执行的格式化、测试命令
2. 通过数/失败数
3. 首个失败摘要（如失败）
4. 未执行项目及原因
5. 需要开发配合项（如 CORS、环境变量、账号）
