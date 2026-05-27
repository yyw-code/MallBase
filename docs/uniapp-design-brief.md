# MallBase UniApp 设计需求文档

> 本文档用于向 Stitch 描述 MallBase 移动端（UniApp）的功能范围、页面清单、数据结构与设计方向，  
> 确保设计产出与后端接口、业务逻辑完全对齐。

---

## 1. 项目背景

MallBase 是一个开源通用电商基座系统，包含：

- **后端**：ThinkPHP 8.x，提供完整的商品、订单、用户、购物车、退款、物流 API
- **后台管理**：Vben Admin (Vue 3 + Ant Design Vue)，已上线
- **移动端客户端**：UniApp（本次设计目标），当前仅有骨架，需从零设计全部页面

移动端支持的平台：微信小程序、H5、iOS、Android。

---

## 2. 设计方向

- 你自己发挥
---

## 4. 页面清单与功能说明

### 4.1 Tab Bar 结构（底部导航）

| Tab | 图标（描述） | 页面 |
|-----|------------|------|
| 首页 | 房屋轮廓 / 填充 | 首页 |
| 分类 | 方格网格 | 商品分类 |
| 购物车 | 购物袋 + 角标 | 购物车 |
| 我的 | 人形轮廓 | 个人中心 |

---

### 4.2 首页 (Home)

**页面结构（从上到下）：**

1. **顶部搜索栏**
   - 固定在顶部，下滑时收起为小搜索入口
   - 点击跳转到搜索页（非当前页搜索）
   - 右侧：消息图标（预留，角标）

2. **Banner / 轮播**
   - 大图轮播，圆角卡片样式（非全屏出血）
   - 左右留边距（16px），高度约屏幕宽 45%
   - 指示器：底部居中小圆点
   - **数据**：后台设置系统下发（预留位，当前可用占位图）

3. **快捷入口（图标宫格）**
   - 一行 4-5 个图标入口
   - 可配置项示例：新品、热卖、限时活动、全部分类
   - **数据**：后台可配（预留）

4. **推荐商品**
   - 标题："为你推荐" + "查看全部" 链接
   - 横向滚动商品卡片（宽度约 140px）
   - 卡片内容：图片 + 商品名 + 价格
   - **API**: `GET /client/api/goods/recommend`

5. **商品瀑布流**
   - 两列等宽瀑布流
   - 无限滚动加载
   - 卡片：图片 + 名称（两行截断）+ 价格 + 销量
   - **API**: `GET /client/api/goods/list?page=1&limit=10`
**推荐 API**: `GET /client/api/goods/recommend`

**交互：**
- 下拉刷新（原生样式）
- 列表触底自动加载下一页
- 骨架屏加载态

---

### 4.3 商品分类页 (Category)

**布局：左右分栏**

| 区域 | 内容 |
|------|------|
| 左侧 | 一级分类列表，垂直滚动，选中态左侧有品牌色竖条指示 |
| 右侧 | 对应二级分类网格，每个分类展示图标/图片 + 名称 |

**数据结构（两级分类树）：**
```json
{
  "id": 1,
  "name": "手机数码",
  "icon": "url",
  "children": [
    { "id": 11, "name": "手机", "icon": "url" },
    { "id": 12, "name": "耳机", "icon": "url" }
  ]
}
```

**API**: `GET /client/api/goods/category/tree`

**交互：**
- 点击左侧一级分类，右侧平滑切换
- 点击二级分类跳转商品列表页（带分类筛选）
- 顶部搜索入口

---

### 4.4 商品列表页 (Product List)

**入口：** 分类页点击、搜索结果、首页"查看全部"

**页面结构：**

1. **顶部**：返回 + 分类名 / 搜索关键词 + 搜索图标
2. **筛选栏**：综合 | 销量 | 价格（升降箭头）| 筛选按钮（抽屉）
3. **切换视图**：列表模式（大图 + 详情）/ 网格模式（两列瀑布流）
4. **商品列表**

**列表模式卡片：**
- 左侧：商品图（正方形，120x120）
- 右侧：名称（两行）、简短描述、价格（当前价 + 划线原价）、销量

**网格模式卡片：**
- 同首页瀑布流卡片

**API**: `GET /client/api/goods/list?category_id=X&sort=sales&order=desc&page=1&limit=20`

**筛选抽屉（底部弹出）：**
- 价格区间（两个输入框）
- 品牌多选（来自 API）
- 标签多选
- 重置 / 确定 按钮

---

### 4.5 商品详情页 (Product Detail)

**这是最关键的页面，需要精细设计。**

**页面结构（从上到下）：**

1. **商品主图轮播**
   - 全屏宽度，高度约 375px
   - 支持图片和视频（视频有播放按钮覆盖层）
   - 底部页码指示器 "1/5"
   - 返回按钮（左上角浮层，半透明圆形背景）
   - 分享按钮（右上角）

2. **价格与名称区域**
   - 当前价格（大字号，品牌色/强调色）
   - 原价（划线，灰色，小字号）
   - 商品名称（最多两行）
   - 简短卖点/副标题

3. **规格选择预览条**
   - 显示："已选：红色 / 128G" 或 "请选择规格"
   - 点击展开规格选择弹窗

4. **配送信息**（预留）
   - 配送至：(用户收货地址概要) > 箭头
   - 运费：免运费 / ¥X
   - 预估到达时间（预留）

5. **商品详情（富文本/图文）**
   - Tab 切换：商品详情 | 规格参数 | 用户评价
   - 商品详情：富文本 HTML 渲染
   - 规格参数：Key-Value 表格
   - 用户评价：评分概览 + 评论列表

6. **底部操作栏（固定）**
   - 左侧图标组：客服 | 购物车（带角标） | 收藏（心形）
   - 右侧按钮组：加入购物车（次要按钮） | 立即购买（主要按钮）

**规格选择弹窗（底部弹出 Sheet）：**
- 顶部：商品小图 + 价格 + 库存
- 规格组（多组）：每组横向排列可选标签
  - 示例规格组：颜色（红色/蓝色/黑色）、内存（64G/128G/256G）
  - 选中态：品牌色边框 + 浅品牌色背景
  - 不可选态（库存为 0）：灰色 + 删除线
- 数量选择器：- [数量] + （库存上限）
- 底部按钮：加入购物车 / 立即购买

**数据结构：**
```json
{
  "id": 1,
  "name": "iPhone 16 Pro",
  "price": "7999.00",
  "original_price": "8999.00",
  "images": ["url1", "url2"],
  "video": "url",
  "content": "<html>...</html>",
  "spec_meta": [
    {
      "spec_id": 1, "spec_name": "颜色",
      "values": [
        { "id": 1, "value": "沙漠钛金属" },
        { "id": 2, "value": "原色钛金属" }
      ]
    },
    {
      "spec_id": 2, "spec_name": "存储容量",
      "values": [
        { "id": 5, "value": "128GB" },
        { "id": 6, "value": "256GB" }
      ]
    }
  ],
  "skus": [
    {
      "id": 1,
      "spec_value_ids": [1, 5],
      "price": "7999.00",
      "stock": 100,
      "sku_image": "url"
    }
  ]
}
```

**API**: `GET /client/api/goods/info/:id`

---

### 4.6 购物车页 (Cart)

**页面结构：**

1. **顶部**：标题 "购物车" + 编辑/完成 按钮
2. **商品列表**（按店铺分组，MallBase 单店模式则为一组）
   - 每个商品行：
     - 左侧：圆形选择框
     - 商品图（80x80）
     - 商品名（两行截断）
     - 规格标签（灰色小字："红色 / 128G"）
     - 价格
     - 数量选择器 - [n] +
   - 左滑操作：删除（红色按钮）

3. **空购物车状态**
   - 居中空状态插图
   - "购物车是空的"
   - "去逛逛" 按钮

4. **底部结算栏（固定）**
   - 全选 checkbox + "全选"
   - 合计：¥XXX（实时计算已选商品）
   - 结算按钮（显示已选件数）

**编辑模式：**
- 选择框 + 删除按钮
- 移入收藏（预留）

**数据结构：**
```json
{
  "id": 1,
  "goods_id": 10,
  "sku_id": 20,
  "quantity": 2,
  "is_selected": 1,
  "goods_name": "iPhone 16 Pro",
  "goods_image": "url",
  "sku_spec_text": "沙漠钛金属 / 256GB",
  "price": "8999.00",
  "stock": 50
}
```

**API**:
- `GET /client/api/cart/list`
- `POST /client/api/cart/add` — `{ goods_id, sku_id, quantity }`
- `PUT /client/api/cart/update/:id` — `{ quantity }`
- `DELETE /client/api/cart/delete` — `{ ids: [1, 2] }`
- `POST /client/api/cart/toggleSelected` — `{ ids: [1, 2] }`

---

### 4.7 订单确认页 (Order Confirm)

**入口：** 购物车"结算" 或 商品详情"立即购买"

**页面结构：**

1. **收货地址卡片**
   - 收货人 + 手机号
   - 详细地址
   - 右侧箭头（点击选择/新增地址）
   - 无地址时显示"请添加收货地址"

2. **商品清单**
   - 商品图 + 名称 + 规格 + 单价 x 数量
   - 不可编辑数量（需返回购物车修改）

3. **配送方式**（预留）
   - 快递配送（默认）

4. **订单备注**
   - 单行输入框，placeholder: "选填，请先和商家协商"

5. **费用明细**
   - 商品金额：¥XXX
   - 运费：¥XX / 免运费
   - 优惠（预留）：-¥XX

6. **底部提交栏（固定）**
   - 合计：¥XXX（含运费）
   - 提交订单 按钮

**API**: `POST /client/api/order/create`
```json
{
  "address_id": 1,
  "cart_ids": [1, 2],
  "remark": "备注内容"
}
```

---

### 4.8 支付页 / 支付结果页

**支付页（模态或跳转）：**
- 支付金额展示
- 支付方式选择：微信支付 / 支付宝（带图标的单选列表）
- 确认支付按钮

**支付结果页：**
- 成功：勾号动画 + "支付成功" + 金额 + 订单号
- 按钮：查看订单 | 返回首页
- 推荐商品（预留）

**API**: `POST /client/api/order/pay/:sn` — `{ pay_method: "wechat" | "alipay" | "mock" }`

---

### 4.9 订单列表页 (Order List)

**页面结构：**

1. **顶部 Tab 切换**：
   - 全部 | 待付款(0) | 待发货(10) | 待收货(20) | 待评价(40)
   - Tab 下方细线指示器，可左右滑动

2. **订单卡片**（每个订单一张卡片）：
   - 订单号 + 状态标签（右上角，语义色）
   - 商品列表：图片 + 名称 + 规格 + 数量 + 单价
   - 多商品时折叠显示前 2 个 + "共 N 件"
   - 底部：合计金额 + 操作按钮

3. **各状态对应操作按钮**：

| 状态 | 按钮 |
|------|------|
| 待付款(0) | 取消订单（次要） + 去支付（主要） |
| 待发货(10) | 申请退款（次要） |
| 待收货(20) | 查看物流（次要） + 确认收货（主要） |
| 已完成(40) | 去评价（主要） + 再次购买（次要） |
| 已关闭(90) | 再次购买（次要） |

4. **空状态**：暂无订单 + 去逛逛

**API**: `GET /client/api/order/list?status=0&page=1&limit=10`

**订单状态机：**
```
待付款(0) → 已支付(10) → 已发货(20) → 已收货(30) → 已完成(40)
     ↓                                                    
  已关闭(90)  ←  用户取消 / 超时未付
```

---

### 4.10 订单详情页 (Order Detail)

**页面结构：**

1. **状态头部**（品牌色/语义色背景）
   - 状态大字
   - 补充说明（如 "请在 XX:XX 前完成支付"）

2. **物流信息**（待收货时显示）
   - 最新物流节点 + 时间
   - 点击查看完整物流轨迹

3. **收货地址**
   - 收货人 + 手机号 + 完整地址

4. **商品列表**
   - 同订单卡片中的商品展示

5. **费用信息**
   - 商品金额 / 运费 / 优惠 / 实付金额

6. **订单信息**
   - 订单号（可复制）
   - 创建时间
   - 支付方式
   - 支付时间
   - 交易流水号

7. **底部操作按钮**（同订单列表中的按钮逻辑）

**API**: `GET /client/api/order/detail/:id`

---

### 4.11 退款申请 / 退款列表 / 退款详情

**退款申请页：**
- 选择退款商品（如部分退款）
- 退款原因（下拉选择）
- 退款说明（文本输入）
- 上传凭证图片（最多 3 张）
- 退款金额（自动计算，不可编辑）
- 提交按钮

**退款列表：**
- 卡片式列表，显示退款单号、商品信息、退款金额、退款状态
- 状态：待审核 / 已同意 / 已拒绝 / 已退款 / 已取消

**退款详情：**
- 退款状态时间线
- 商品信息
- 退款原因 + 说明 + 凭证
- 退款金额与方式
- 操作按钮（取消退款申请等）

**API**:
- `POST /client/api/refund/apply` — 申请退款
- `POST /client/api/refund/cancel/:id` — 取消退款申请
- `GET /client/api/refund/list` — 退款列表
- `GET /client/api/refund/detail/:id` — 退款详情
- `GET /client/api/refund/reasonOptions` — 获取退款原因选项列表

---

### 4.12 个人中心页 (Profile / "我的")

**页面结构：**

1. **用户信息卡片**
   - 头像（圆形，80px）+ 昵称 + 手机号（部分遮蔽）
   - 未登录态：头像占位 + "点击登录"
   - 点击进入个人资料编辑页

2. **订单快捷入口**
   - "我的订单" + "查看全部" 箭头
   - 图标行：待付款 | 待发货 | 待收货 | 待评价 | 退款/售后
   - 每个图标可显示数量角标

3. **功能列表**（分组 cell list）
   - 收货地址管理
   - 我的收藏（预留）
   - 我的优惠券（预留）
   - 浏览记录（预留）
   - 主题设置（Light / Dark / 跟随系统）
   - 关于 MallBase
   - 退出登录

**用户信息 API**:

| 方法 | 路径 | 请求参数 | 说明 |
|------|------|---------|------|
| GET | `/client/api/user/my/info` | — | 获取当前用户信息 |
| PUT | `/client/api/user/my/info` | `{ nickname?, real_name?, gender?, birthday?, province?, city?, district?, bio?, avatar? }` | 修改个人资料 |
| PUT | `/client/api/user/my/password` | `{ old_password, password }` | 修改密码 |
| POST | `/client/api/user/my/logout` | — | 退出登录 |

---

### 4.13 登录 / 注册页

**当前后端仅支持手机号注册（不支持 email 公开注册），同时支持用户名注册。**

**登录页（主界面）：**
- Logo + 应用名称
- 手机号输入框（带国际区号选择，默认 +86）
- 验证码输入框 + 获取验证码按钮（60 秒倒计时）
- 登录按钮（主要）
- 切换入口："密码登录" 文字链接
- 底部：微信一键登录（微信小程序环境显示）
- 底部：用户协议 + 隐私政策 链接

**密码登录（切换后）：**
- 手机号 / 用户名 输入框
- 密码输入框（带显示/隐藏切换）
- 登录按钮
- 切换入口："验证码登录" 文字链接
- "忘记密码"（预留）

**注册页：**
- 手机号输入框
- 密码输入框
- 昵称输入框（选填）
- 注册按钮
- 底部："已有账号？去登录"

**微信小程序登录流程：**
1. 用户点击"微信登录" → 前端调 `wx.login()` 拿 code → 调用 `POST /client/api/user/auth/wechat` 传 `{ code }`
2. 后端返回两种情况：
   - **直接登录成功**：返回 `{ access_token, refresh_token, expires_in, refresh_expires_in }`
   - **需绑定手机号**：返回 `{ need_mobile: true, openid, unionid?, session_key, force_phone_number: true }`
     - 前端根据 `force_phone_number: true` 优先展示微信获取手机号按钮
3. 绑定手机号两种方式：
   - 方式 A（推荐）：微信获取手机号按钮（`open-type="getPhoneNumber"`）→ 拿到 phone_code → 调用 `POST /client/api/user/auth/wechat/bindMobileByPhoneCode` 传 `{ openid, phone_code }`
   - 方式 B（兜底）：用户手动输入手机号 + 短信验证码 → 先调 `POST /client/api/user/auth/sms/send` 传 `{ mobile, scene: "bind_mobile" }` → 再调 `POST /client/api/user/auth/wechat/bindMobile` 传 `{ openid, mobile, code }`
4. 绑定成功后返回 `{ access_token, refresh_token, expires_in, refresh_expires_in }`

**微信公众号登录流程（H5 环境）：**
1. 前端跳转微信 OAuth 授权页
2. 授权回调拿到 code → 调用 `POST /client/api/user/auth/wechat/official` 传 `{ code }`
3. 后端返回两种情况：
   - **直接登录成功**：返回 `{ access_token, refresh_token, expires_in, refresh_expires_in }`
   - **需绑定手机号**：返回 `{ need_mobile: true, openid, unionid?, sms_required: true }`
     - 前端根据 `sms_required: true` 展示手机号 + 短信验证码输入表单
4. 绑定手机号：先调 `POST /client/api/user/auth/sms/send` 传 `{ mobile, scene: "wechat_official_bind" }` → 再调 `POST /client/api/user/auth/wechat/official/bindMobile` 传 `{ openid, mobile, code }`
5. 绑定成功后返回 `{ access_token, refresh_token, expires_in, refresh_expires_in }`

**登录成功统一响应结构：**
```json
{
  "code": 200,
  "message": "登录成功",
  "data": {
    "access_token": "eyJ...",
    "refresh_token": "eyJ...",
    "expires_in": 7200,
    "refresh_expires_in": 2592000
  },
  "timestamp": 1745654321
}
```

**需绑定手机号响应示例（小程序）：**
```json
{
  "code": 200,
  "message": "登录成功",
  "data": {
    "need_mobile": true,
    "openid": "oXXXX",
    "unionid": null,
    "session_key": "xxxxx",
    "force_phone_number": true
  },
  "timestamp": 1745654321
}
```

**需绑定手机号响应示例（公众号）：**
```json
{
  "code": 200,
  "message": "登录成功",
  "data": {
    "need_mobile": true,
    "openid": "oXXXX",
    "unionid": null,
    "sms_required": true
  },
  "timestamp": 1745654321
}
```

**认证 API 完整清单：**

| 方法 | 路径 | 请求参数 | 说明 |
|------|------|---------|------|
| POST | `/client/api/user/auth/sms/send` | `{ mobile, scene }` | 发送短信验证码；scene 可选值：`login` / `register` / `reset_password` / `bind_mobile` / `wechat_official_bind`，默认 `login` |
| POST | `/client/api/user/auth/login/sms` | `{ mobile, code }` | 手机号 + 短信验证码登录 |
| POST | `/client/api/user/auth/login` | `{ account, password }` | 手机号 + 密码登录 |
| POST | `/client/api/user/auth/login/username` | `{ username, password }` | 用户名 + 密码登录 |
| POST | `/client/api/user/auth/register` | `{ mobile, password, nickname? }` | 手机号注册（无需短信验证码） |
| POST | `/client/api/user/auth/register/username` | `{ username, password, nickname? }` | 用户名注册 |
| POST | `/client/api/user/auth/wechat` | `{ code }` | 微信小程序登录（wx.login 获取的 code） |
| POST | `/client/api/user/auth/wechat/bindMobile` | `{ openid, mobile, code }` | 小程序手动绑定手机号（code 为短信验证码，scene=`bind_mobile`） |
| POST | `/client/api/user/auth/wechat/bindMobileByPhoneCode` | `{ openid, phone_code }` | 小程序通过 getPhoneNumber 按钮的 code 快捷绑定 |
| POST | `/client/api/user/auth/wechat/official` | `{ code }` | 微信公众号 OAuth 登录（H5 环境） |
| POST | `/client/api/user/auth/wechat/official/bindMobile` | `{ openid, mobile, code }` | 公众号登录后绑定手机号（code 为短信验证码，scene=`wechat_official_bind`） |

---

### 4.14 收货地址管理

**地址列表页：**
- 卡片式地址列表
- 每张卡片：收货人 + 手机号 + 完整地址 + 默认标签
- 左滑删除
- 编辑按钮
- 底部：新增收货地址 按钮

**地址编辑/新增页：**
- 收货人（输入框）
- 手机号（输入框）
- 所在地区（省/市/区三级联动选择器）
- 详细地址（多行输入框）
- 设为默认地址（开关）
- 保存按钮
- 删除地址（编辑模式，底部红色文字按钮）
- 设为默认地址操作

**数据结构：**
```json
{
  "id": 1,
  "name": "张三",
  "phone": "13800138000",
  "province": "广东省",
  "city": "深圳市",
  "district": "南山区",
  "address": "科技园南路XX号",
  "is_default": 1
}
```

**API**:
- `GET /client/api/user/address/list` — 地址列表
- `GET /client/api/user/address/info/:id` — 地址详情
- `POST /client/api/user/address/create` — 新增地址
- `PUT /client/api/user/address/update/:id` — 修改地址
- `DELETE /client/api/user/address/delete/:id` — 删除地址
- `PUT /client/api/user/address/setDefault/:id` — 设为默认地址

---

### 4.15 搜索页

**页面结构：**

1. **搜索栏**（自动聚焦）
   - 输入框 + 取消按钮
   - 输入时实时显示搜索建议（预留）

2. **搜索历史**
   - 标签流式布局
   - 清除历史 按钮

3. **热门搜索**（预留）
   - 标签流式布局

4. **搜索结果**
   - 复用商品列表页的布局和筛选

---

### 4.16 用户评价页

**评价列表（嵌入商品详情 Tab）：**
- 综合评分（星级）
- 评价统计：好评(X) | 中评(X) | 差评(X) | 有图(X)
- 评价卡片：
  - 用户头像 + 昵称 + 评分星级 + 时间
  - 评价内容
  - 评价图片（可点击放大）
  - 规格信息
  - 商家回复（灰色背景块）

**发表评价页（预留）：**
- 商品信息卡片
- 星级评分（5 星可点选）
- 评价内容（多行输入）
- 上传图片（最多 6 张）
- 匿名评价 开关
- 提交按钮

---

### 4.17 设置与主题切换页

**设置页：**
- 分组列表样式
- 个人资料（头像、昵称修改）
- 账户安全（修改密码等）
- 主题设置
- 清除缓存
- 关于我们
- 版本号
- 退出登录（红色文字）

**主题切换面板：**
- 三选一：浅色模式 | 深色模式 | 跟随系统
- 每个选项带缩略预览图
- 切换动画：整页 crossfade 过渡

---

## 5. 通用组件清单

以下组件需在设计系统中统一定义：

| 组件 | 说明 |
|------|------|
| NavigationBar | 自定义导航栏（大标题 + 小标题两种模式） |
| TabBar | 底部导航（毛玻璃、角标） |
| ProductCard | 商品卡片（网格版 + 列表版） |
| PriceDisplay | 价格展示（当前价 + 划线原价） |
| QuantityStepper | 数量选择器（- n +） |
| SpecSelector | 规格选择弹窗 |
| AddressCard | 地址卡片 |
| OrderCard | 订单卡片 |
| StatusBadge | 状态标签（语义色） |
| EmptyState | 空状态（图标 + 文案 + 操作按钮） |
| SearchBar | 搜索栏 |
| SkeletonScreen | 骨架屏加载态 |
| ActionSheet | 底部操作面板 |
| Toast | 轻提示 |
| Dialog | 确认弹窗 |
| ImageViewer | 图片预览（支持缩放、滑动） |
| Badge | 角标（数字/红点） |
| Tag | 标签（规格、状态、分类） |
| Divider | 分隔线 / 分隔块 |
| CellGroup | 分组列表行 |
| SwipeAction | 左滑操作 |

---

## 6. 数据接口总览

### 6.1 公开接口（无需登录）

| 方法 | 路径 | 用途 |
|------|------|------|
| GET | `/client/api/goods/list` | 商品列表（分页、筛选、排序） |
| GET | `/client/api/goods/info/:id` | 商品详情（含 SKU、规格） |
| GET | `/client/api/goods/recommend` | 推荐商品 |
| GET | `/client/api/goods/category/tree` | 分类树（两级） |
| GET | `/client/api/goods/category/list` | 分类列表 |
| GET | `/client/api/region/children` | 获取下级地区列表 |
| GET | `/client/api/region/path/:id` | 获取地区路径 |
| GET | `/client/api/setting/basic` | 站点基础配置 |

### 6.2 认证接口（无需登录）

| 方法 | 路径 | 请求参数 | 用途 |
|------|------|---------|------|
| POST | `/client/api/user/auth/sms/send` | `{ mobile, scene? }` | 发送短信验证码（scene: login/register/reset_password/bind_mobile/wechat_official_bind） |
| POST | `/client/api/user/auth/login` | `{ account, password }` | 手机号 + 密码登录 |
| POST | `/client/api/user/auth/login/username` | `{ username, password }` | 用户名 + 密码登录 |
| POST | `/client/api/user/auth/login/sms` | `{ mobile, code }` | 手机号 + 短信验证码登录 |
| POST | `/client/api/user/auth/register` | `{ mobile, password, nickname? }` | 手机号注册 |
| POST | `/client/api/user/auth/register/username` | `{ username, password, nickname? }` | 用户名注册 |
| POST | `/client/api/user/auth/wechat` | `{ code }` | 微信小程序登录 |
| POST | `/client/api/user/auth/wechat/bindMobile` | `{ openid, mobile, code }` | 小程序手动绑定手机号（code 为 SMS 验证码） |
| POST | `/client/api/user/auth/wechat/bindMobileByPhoneCode` | `{ openid, phone_code }` | 小程序 getPhoneNumber 快捷绑定 |
| POST | `/client/api/user/auth/wechat/official` | `{ code }` | 微信公众号 OAuth 登录（H5） |
| POST | `/client/api/user/auth/wechat/official/bindMobile` | `{ openid, mobile, code }` | 公众号绑定手机号（code 为 SMS 验证码） |

### 6.3 需登录接口（JWT 鉴权）

**用户信息：**

| 方法 | 路径 | 请求参数 | 用途 |
|------|------|---------|------|
| GET | `/client/api/user/my/info` | — | 获取用户信息 |
| PUT | `/client/api/user/my/info` | `{ nickname?, real_name?, gender?, birthday?, province?, city?, district?, bio?, avatar? }` | 修改个人资料 |
| PUT | `/client/api/user/my/password` | `{ old_password, password }` | 修改密码 |
| POST | `/client/api/user/my/logout` | — | 退出登录 |

**收货地址：**

| 方法 | 路径 | 请求参数 | 用途 |
|------|------|---------|------|
| GET | `/client/api/user/address/list` | — | 地址列表 |
| GET | `/client/api/user/address/info/:id` | — | 地址详情 |
| POST | `/client/api/user/address/create` | `{ name, phone, province, city, district, address, is_default? }` | 新增地址 |
| PUT | `/client/api/user/address/update/:id` | `{ name?, phone?, province?, city?, district?, address?, is_default? }` | 修改地址 |
| DELETE | `/client/api/user/address/delete/:id` | — | 删除地址 |
| PUT | `/client/api/user/address/setDefault/:id` | — | 设为默认地址 |

**购物车：**

| 方法 | 路径 | 请求参数 | 用途 |
|------|------|---------|------|
| GET | `/client/api/cart/list` | — | 购物车列表 |
| POST | `/client/api/cart/add` | `{ goods_id, sku_id, quantity }` | 加入购物车 |
| PUT | `/client/api/cart/update/:id` | `{ quantity }` | 更新购物车商品数量 |
| DELETE | `/client/api/cart/delete` | `{ ids: [1, 2] }` | 批量删除购物车商品 |
| POST | `/client/api/cart/toggleSelected` | `{ ids: [1, 2] }` | 切换选中状态 |

**订单：**

| 方法 | 路径 | 请求参数 | 用途 |
|------|------|---------|------|
| POST | `/client/api/order/create` | `{ address_id, cart_ids, remark? }` | 创建订单 |
| POST | `/client/api/order/pay/:sn` | `{ pay_method }` | 支付订单（pay_method: `wechat` / `alipay` / `mock`） |
| POST | `/client/api/order/cancel/:id` | — | 取消订单 |
| POST | `/client/api/order/confirmReceive/:id` | — | 确认收货 |
| GET | `/client/api/order/list` | `?status=&page=&limit=` | 订单列表 |
| GET | `/client/api/order/detail/:id` | — | 订单详情 |

**退款：**

| 方法 | 路径 | 请求参数 | 用途 |
|------|------|---------|------|
| POST | `/client/api/refund/apply` | `{ order_item_id, quantity, type, reason, remark? }` | 申请退款 |
| POST | `/client/api/refund/cancel/:id` | — | 取消退款申请 |
| GET | `/client/api/refund/list` | `?page=&limit=` | 退款列表 |
| GET | `/client/api/refund/detail/:id` | — | 退款详情 |
| GET | `/client/api/refund/reasonOptions` | — | 获取退款原因选项列表 |

---

## 7. 页面优先级（设计顺序建议）

### P0 — 核心购物流程（优先设计）

1. 首页
2. 商品详情页（含规格选择弹窗）
3. 购物车
4. 订单确认页
5. 登录页
6. Tab Bar + 底部导航

### P1 — 完整购物体验

7. 商品分类页
8. 商品列表页
9. 订单列表页
10. 订单详情页
11. 个人中心页
12. 收货地址管理

### P2 — 补充功能

13. 搜索页
14. 支付结果页
15. 退款相关页面（申请、列表、详情）
16. 用户评价
17. 设置页与主题切换

---

## 8. 设计交付格式

- 输出格式：Stitch 项目中的 Screen 组
- 分组方式：按功能模块分组（首页、商品、购物车、订单、用户、设置）
- 每个页面标注：
  - 使用的 Token 名称
  - 组件名称
  - 交互说明（点击跳转目标、弹窗触发条件等）
- 关键页面同时输出 Light + Dark 版本
