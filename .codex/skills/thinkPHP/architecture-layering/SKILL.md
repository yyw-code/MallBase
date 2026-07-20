---
name: architecture-layering
description: MallBase ThinkPHP 后端分层、Swoole Service 无状态与 IDE 泛型规则；开发或重构 backend/app 下的 Controller、Service、Model，调整 BaseController/BaseService、service()/model() 调用、构造注入或协程安全状态时使用。
---

# ThinkPHP 分层与无状态规则

## 当前目录

- Controller：`backend/app/controller/{admin,client,...}/`
- Service：`backend/app/service/{admin,client,...}/`
- Model：`backend/app/model/`
- 基类：`backend/mall_base/base/BaseController.php`、`BaseService.php`

## 分层职责

1. 让 Controller 只负责读取请求参数、调用验证器、调用 Service 和返回响应。
2. 把业务判断、跨模型编排、事务边界和结果组装放在 Service。
3. 把表映射、关系、类型转换和字段访问语义放在 Model。
4. 优先经所属领域的 Service 复用业务不变量；只有纯数据访问且不会绕过领域约束时，才直接使用其它 Model。
5. 保持 `Controller -> Service -> Model` 为主调用方向，不在 Model 中反向调用 Controller 或请求对象。

## Swoole 无状态

1. 不在 Service 属性、`static` 或全局变量中保存请求参数、当前用户、查询构造器或 Model 实例。
2. 通过 `$this->model()` 按需创建 Model；不要缓存其返回值供后续请求复用。
3. 允许构造注入无请求态的只读协作者，例如 `private readonly AssetResolver $resolver`。
4. SDK、驱动或客户端若含签名上下文、请求头或可变配置，按其专用 skill 决定生命周期，不默认做容器单例。

## 泛型与跳转

新增或修改继承基类的类时，保持具体泛型和默认类名一致：

```php
/** @extends BaseController<GoodsService> */
class GoodsController extends BaseController
{
    protected string $serviceClass = GoodsService::class;
}

/** @extends BaseService<Goods> */
class GoodsService extends BaseService
{
    protected string $modelClass = Goods::class;
}
```

`BaseController::service()` 和 `BaseService::model()` 已提供方法级模板；传入具体 `::class` 时保留类型推导，不再增加无收益的取实例包装。

## 自检

- [ ] Controller 中没有业务编排或直接持久化。
- [ ] Service 没有请求级可变状态和 Model/查询缓存。
- [ ] 业务约束没有被跨领域直接写表绕过。
- [ ] `@extends`、`$serviceClass`、`$modelClass` 指向同一具体类型。
