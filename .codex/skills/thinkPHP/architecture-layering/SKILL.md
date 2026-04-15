# ThinkPHP 规则：三层架构边界

## 适用范围

涉及 `backend/app/*/(controller|service|model)` 的功能开发。

## 强制规则

1. `Controller -> Service -> Model` 单向调用。
2. Controller 只处理参数、校验、响应。
3. Service 只处理业务逻辑，不做请求层职责。
4. Model 只处理数据访问。

## 禁止项

- ❌ Controller 直接调用 Model。
- ❌ Controller 编写业务逻辑。
- ❌ Service 直接跨模块访问对方 Model。

## 自检清单

- [ ] 控制器中无业务分支逻辑。
- [ ] 控制器只调用 `$this->service()`。
- [ ] 数据查询/写入都在 Model 或 Service 内完成。
