# ThinkPHP 规则：Service 无状态（Swoole）

## 适用范围

涉及 `backend/app/*/service/*.php` 与 `mall_base/base/BaseService.php` 使用场景。

## 强制规则

1. Service 不持有请求级状态。
2. Model 实例必须通过 `$this->model()` 动态获取。
3. 禁止在属性里缓存 Model 实例。
4. 禁止 static/全局变量存储请求级数据。

## 原因

项目运行在 Swoole 常驻内存环境，请求间状态污染会导致隐蔽错误。

## 自检清单

- [ ] Service 类中无 Model 缓存属性。
- [ ] 无 static 请求态变量。
- [ ] 业务流程内按需调用 `$this->model()`。
