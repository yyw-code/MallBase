# ThinkPHP 规则：IDE 泛型注释与跳转

## 适用范围

适用于继承 `BaseController` / `BaseService` 的所有类。

## 强制规则

1. Controller 类注释必须包含：`@extends BaseController<具体Service>`
2. Service 类注释必须包含：`@extends BaseService<具体Model>`
3. `BaseController::service()` 使用方法级模板，支持传参类型推导
4. `BaseService::model()` 使用方法级模板，支持传参类型推导

## 推荐写法

```php
/**
 * @extends BaseController<UserService>
 */
class UserController extends BaseController {}
```

```php
/**
 * @extends BaseService<User>
 */
class UserService extends BaseService {}
```

## 目标

- 提升 `$this->service()` / `$this->model()` 的 IDE 跳转准确度
- 减少跨类调用时的静态分析误报
