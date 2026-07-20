---
name: mall-base-boundary
description: MallBase ThinkPHP 框架边界规则；新增或修改 backend/mall_base 基类、驱动、日志、队列、框架服务，判断业务服务放置位置或扩展 DriverManager 驱动时使用。
---

# `backend/mall_base` 边界

`backend/mall_base/` 放跨业务域可复用的框架基础设施；依赖具体业务表、状态机、场景配置或后台规则的逻辑放在 `backend/app/`。

## 目录判断

| 内容 | 位置 |
|---|---|
| Controller、领域 Service、Model、业务事件 | `backend/app/` |
| 通用基类 | `backend/mall_base/base/` |
| 第三方供应商驱动及驱动管理 | `backend/mall_base/drivers/` |
| 通用日志、队列、框架异常 | `backend/mall_base/{log,queue,exception}/` |
| 与具体用户、订单、商品、设置表有关的服务 | `backend/app/service/` |

不要仅因一个类“可能复用”就下沉到 `mall_base`。若它需要读取 `mb_*` 业务表、识别业务状态或组织业务流程，默认属于 `backend/app/`。

## 驱动扩展

1. 在 `backend/mall_base/drivers/<type>/` 复用现有 `Base<Type>Driver` 和具体实现结构。
2. 在 `backend/app/AppService.php` 注册可用驱动和启动默认值。
3. 让驱动只封装供应商协议、SDK 和底层请求；把驱动选择、场景绑定、频控、缓存和异常转换留给 `backend/app/service/`。
4. 按当前调用场景选择 `DriverManager::create()` 或 `driver()`；不要把带请求配置或可变上下文的驱动无条件缓存到常驻进程。
5. 只有确有容器替换或构造依赖时才在 `backend/app/provider.php` 增加绑定。

## 禁止项

- 不在 `backend/mall_base/` 新建订单、商品、会员等业务模块。
- 不把业务 Service、状态机、频控或设置表读取放入驱动。
- 不绕开现有 `DriverManager` 再造一套并行驱动注册机制。
- 不为移动目录而大范围重构现有框架基础设施。

## 自检

- [ ] 新类是否真正跨领域且不依赖业务数据。
- [ ] 驱动是否只处理供应商能力，业务策略仍在 `backend/app/`。
- [ ] 注册位置和实例生命周期与 Swoole 常驻进程兼容。
