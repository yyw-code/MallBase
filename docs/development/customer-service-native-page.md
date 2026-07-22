# UniApp 原生客服页接入

## 目标

MallBase UniApp 由自有页面承载客服会话，H5 和微信小程序共用同一套业务逻辑。运行时不打开 Customer Service Widget，也不将上下文凭证放入 URL。

Customer Service 仓库仍是外部会话 REST 和 Socket.IO 协议的真相源，MallBase 仅实现访客端。

## 数据流

1. 商品、订单或其他业务页向 MallBase 后端请求短期 `context_token`。
2. 入口将 `context_token`、`api_base` 和 `socket_base` 写入一次性本地交接区，再进入 `pages-sub/customer-service/index`。
3. 原生客服页进入时立即读取并删除交接数据，通过 `POST /api/conversations/external` 换取 `conversationId` 和 `visitorToken`。
4. 历史消息、已读和附件上传通过 REST 完成；加入会话、发送和接收消息通过 Socket.IO 完成。
5. 页面卸载时断开实时连接，`visitorToken` 仅保留在当前页面内存中。

## 安全边界

- `context_token` 不得放入页面 URL、日志或长期存储。
- Customer Service 请求不携带 MallBase Bearer Token，外部 DTO 必须单独校验，不套用 MallBase `{code,message,data}` 协议。
- 客服资源卡片只允许使用数字 `externalId` 进入 MallBase 商品或订单详情页。不执行消息中的任意 URL、内部路由或 Connector Action。
- 发送消息使用 Socket.IO `message:send`，不使用仅写库的 REST `visitor-messages` 作为实时发送替代。

## 配置

后台客户端配置中需要：

- 客服模式：`在线客服系统`。
- 客服 API 基础地址：例如 `https://customer.example.com/api`。
- Socket.IO 服务地址：例如 `https://customer.example.com`，不带 `/api` 或 `/socket.io`。
- 上下文 Key ID 与密钥。

`customer_service_widget_url` 仅作为旧版配置兼容字段保留，不再是 UniApp 原生客服页的启用条件。

## 小程序要求

生产环境必须使用有效 HTTPS/WSS 证书，并在微信公众平台配置：

- `request` 合法域名：客服 API 域名。
- `uploadFile` 合法域名：客服附件上传域名。
- `socket` 合法域名：Socket.IO 服务域名。
- 若客服资源卡展示远程图片，对应图片域名也必须符合小程序下载规则。

H5 还需 Customer Service 服务端 CORS/Web Origin 允许 MallBase H5 站点。

## 当前能力边界

原生页已支持文本、图片、语音播放、商品/订单资源卡、历史消息、已读和断线重连。当前不提供小程序内语音录制、WebRTC 语音/视频通话和服务评价提交。

## 验证

```bash
node --test frontend/uniapp/utils/customer-service*.test.mjs
composer --working-dir backend test -- --filter CustomerService
npm --prefix frontend/uniapp run build:h5
npm --prefix frontend/uniapp run build:mp-weixin
```

构建通过只能证明编译契约成立。发布前仍需在微信开发者工具和真机中验证 HTTPS/WSS 合法域名、连接重试、前后台收发消息和图片上传。
