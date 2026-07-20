---
name: uniapp
description: MallBase UniApp 核心规则；修改 frontend/uniapp 的页面、组件、接口、登录态或跨端行为时使用。
---

# MallBase UniApp 核心规则

## 适用范围

`frontend/uniapp` 当前 H5、微信小程序及后续同架构客户端的页面、组件、API、状态和公共能力。

## 接口协议

1. 统一通过 `frontend/uniapp/api/request.js` 发起请求，不在页面重复包装 `uni.request`、Token 注入、刷新或错误提示。
2. 后端响应协议为 `code/message/data`；请求层在 `code === 200` 时直接返回 `data`，业务 API 和页面不得再次按完整响应对象解包。
3. 接口路径、HTTP 方法、字段名和参数位置必须与真实后端一致，避免额外别名映射导致契约漂移。
4. 非协议响应必须按异常处理，不能把 HTML、空响应或第三方原始结构伪装成成功数据。
5. 维护状态、静默请求等特殊行为复用请求层已有选项，不在业务页面复制分支。

## 认证与 Token

1. Access Token 和 Refresh Token 的存储键统一为 `mb_access_token`、`mb_refresh_token`。
2. `api/request.js` 负责 Bearer Token 注入、单飞刷新、重试和最终的 401 清理/跳转；禁止页面各自实现刷新流程。
3. `store/user.js` 负责用户态与本地 Token 状态同步，登出统一调用 `clearAuth()`。
4. `utils/auth.js` 只提供页面进入前的登录判断与跳转，不替代请求层的认证处理。
5. 登录跳转需要保留合法的原页面地址时，使用现有 redirect 机制，禁止拼接未编码的用户输入。

## 模块结构与复用

1. 主包页面放在 `pages/`，分包业务页面放在 `pages-sub/<module>/`，并同步维护 `pages.json`。
2. API 按业务模块放在 `api/<module>/`；跨页面业务逻辑放在 `utils/` 或 `store/`，不复制到多个页面。
3. 可复用 UI 放在 `components/mb-*`，优先复用现有价格、按钮、空状态、支付、规格、商品卡片等组件。
4. 页面只承担当前流程的展示与编排；认证、支付、路由、主题、装修和扩展槽等公共规则由已有公共模块负责。
5. 使用平台专属 API 时必须通过 UniApp 条件编译或现有平台适配层隔离，不能让 H5、微信小程序互相引入不可用能力。

## 真实参考

- 请求协议：`frontend/uniapp/api/request.js`
- 登录态：`frontend/uniapp/store/user.js`、`frontend/uniapp/utils/auth.js`
- 页面注册：`frontend/uniapp/pages.json`
- 公共组件：`frontend/uniapp/components/mb-*`
- 业务 API：`frontend/uniapp/api/<module>/`

## 自检清单

- [ ] API 调用复用统一请求层，返回值按 `data` 使用。
- [ ] Token 刷新、401 和登出没有页面级重复实现。
- [ ] 页面、API、组件和公共逻辑放在对应模块目录。
- [ ] H5 与微信小程序的条件分支均可构建或完成相关验证。
- [ ] 新能力优先复用现有 `mb-*` 组件和公共工具。
