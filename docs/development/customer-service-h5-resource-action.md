# UniApp 客服 H5 资源跳转设计

## 目标

MallBase UniApp H5 以顶层页面打开客服 Widget，并向 Widget 提供由 MallBase 控制的商品和订单地址模板。客服资源卡片通过模板生成 MallBase 页面地址，不依赖 iframe `postMessage` 动作桥。

## 范围

- H5 使用顶层 Widget 页面。
- 支持商品和订单资源跳转。
- 非 H5 平台继续使用 `web-view` 打开客服地址。
- 客服系统仓库作为协议真相源，本次不修改其代码。

## 方案

UniApp 获取 `contextToken` 后生成 Widget URL。H5 额外附加 `resourceUrlTemplates`：

```json
{
  "product": "<MallBase H5 地址>#/pages-sub/goods/detail?id={externalId}",
  "order": "<MallBase H5 地址>#/pages-sub/order/detail?id={externalId}"
}
```

模板完全由 MallBase 构造，Widget 只替换资源的 `externalId`。客服桥接页从短期本地缓存读取完整 Widget URL，校验为 HTTP 或 HTTPS 地址，清理缓存后通过 `window.location.replace` 切换到顶层 Widget。

不保留独立的 `CONNECTOR_ACTION` 适配器。顶层 Widget 不存在可供 MallBase 监听的子 iframe，继续保留该适配器会形成无法触发的误导实现。

## 数据流

1. 商品或订单页向 MallBase 后端请求客服 `contextToken`。
2. UniApp 生成带 `contextToken`、平台编码和 `resourceUrlTemplates` 的 Widget URL。
3. URL 写入短期本地缓存，随后进入客服桥接页。
4. H5 桥接页仅接受缓存中的 URL，校验协议并清理缓存。
5. 浏览器通过 `window.location.replace` 打开顶层 Widget。
6. Widget 使用 MallBase 提供的模板生成商品或订单详情地址。

## 安全边界

- H5 不接受查询参数直接提供的顶层跳转地址，只接受五分钟内写入本地缓存的地址。
- 顶层跳转仅允许 HTTP 和 HTTPS 协议。
- 商品和订单页面模板由 MallBase 固定生成，不接受客服消息提供任意内部路由。
- `contextToken` 仍由服务端签名并限制有效期。

## 验证

```bash
node --test frontend/uniapp/utils/customer-service-h5-contract.test.mjs
corepack pnpm@10.28.2 --dir frontend/uniapp run build:h5
```

真实链路需分别验证商品与订单资源卡片能够返回 MallBase 对应详情页，并确认过期缓存、非法协议和直接传入 URL 不会触发顶层跳转。
