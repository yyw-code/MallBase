# ThinkPHP 规则：正式字段优先走模型访问器/修改器

## 适用范围

- `backend/app/*/model/**`
- `backend/app/*/service/**`
- 重点字段：JSON 字段、上传路径字段、需要补充 `*_full_url` 的正式模型字段

## 强制规则

1. 正式模型字段的读时展示增强，优先放在 Model 获取器中处理。
2. 正式模型字段的写入，优先依赖 Model 类型转换、修改器和 `$model->save()`。
3. Service 允许做保存前业务归一化，例如字段裁剪、结构标准化、业务校验。
4. Service 不允许做字段级 `hydrate*` / `persist*` 包装来替代模型职责。
5. JSON 字段如果定义了获取器，必须兼容 `think\model\type\Json` 对象输入，不能假设传入值一定是数组。
6. 模型访问器只能补充展示字段，例如 `pic_full_url`，不得覆盖原始存储字段。
7. 禁止在 Controller / Service / Model 中使用 `var_dump`、`dump`、`echo`、`die` 这类会污染接口响应的调试输出。

## 推荐做法

1. 正式字段先在 Model 中声明 `$json`、`$jsonAssoc`、`$append`。
2. 读取字段时通过 `get*Attr()` 统一补充展示字段。
3. 保存时让 Service 只做结构归一化，再交给 Model 持久化。
4. 若 Swoole 常驻进程未刷新字段定义，优先重启服务，不在业务代码里加绕行逻辑。

## 反例

- `GoodsService::hydrateSpecMeta()`
- `GoodsService::persistSpecMeta()`
- 在 `getSpecMetaAttr()` 里直接 `var_dump($value)`
- 在 Service 里把正式字段重新拼装成“伪模型输出”

## 自检清单

- [ ] 正式字段的读时增强是否已放到 Model 获取器。
- [ ] Service 中是否还存在 `hydrate*` / `persist*` 这类字段级包装方法。
- [ ] JSON 字段获取器是否兼容 `think\model\type\Json`。
- [ ] 展示增强是否只追加字段，不覆盖原始存储值。
- [ ] 接口链路中是否已清除调试输出。
