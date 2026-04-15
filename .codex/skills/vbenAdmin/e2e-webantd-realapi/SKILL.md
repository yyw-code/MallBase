# Skill: e2e-webantd-realapi

## 适用场景

- `frontend/admin/apps/web-antd/**` 页面、路由、API 调用改动
- 需要验证前端是否真实打通后端接口（非 playground mock）

## 强制规则

1. 默认使用 `web-antd` 作为 E2E 主入口，不以 playground 结果替代业务回归。
2. 登录类用例必须校验真实接口请求与响应，而不是只校验页面跳转。
3. 失败时必须区分：UI 操作失败 / 网络失败 / CORS / 账号密码错误。

## 标准命令

```bash
pnpm --dir frontend/admin run test:e2e:install
pnpm --dir frontend/admin run test:e2e
```

说明：
- 首次执行先安装 Playwright 浏览器。
- 默认脚本已指向 `@vben/web-antd`。

## 用例落地约束

1. 用例目录：`frontend/admin/apps/web-antd/__tests__/e2e/`
2. 登录页使用 `?e2e=1` 进入测试模式（用于跳过滑块等非核心链路阻塞）
3. 对登录请求至少断言：
   - 请求路径包含 `/auth/admin/login`
   - 响应存在且可读
   - 业务码 `code === 200`

## 最小交付格式（测试角色）

1. 已执行命令
2. 通过数/失败数
3. 首个失败摘要（如失败）
4. 需要开发配合项（如 CORS、环境变量、账号）
