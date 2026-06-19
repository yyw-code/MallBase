---
name: model-field-accessor-first
description: MallBase ThinkPHP 模型字段访问器与素材回显规则；在 Model 字段读写、访问器、修改器、with 关联、素材 ID 回显或 Service 字段组装优化时使用。
---

# ThinkPHP 规则：模型语义优先，素材回显批量处理

## 适用范围

- `backend/app/*/model/**`
- `backend/app/*/service/**`
- 重点字段：JSON 字段、路径/URL 字段、素材 ID 字段、模型关联、需要补充 `*_full_url` 的返回字段

## 强制规则

1. 正式模型字段的结构语义优先放在 Model 中处理，例如关联、枚举文案、JSON 归一化、修改器。
2. 正式模型字段的写入，优先依赖 Model 类型转换、修改器和 `$model->save()`。
3. Service 允许做保存前业务归一化，例如字段裁剪、结构标准化、业务校验。
4. 简单展示型关联优先使用模型关联 + `with()`，避免重复手写 `ids -> map -> foreach`。
5. JSON 字段如果定义了获取器，必须兼容 `think\model\type\Json` 对象输入，不能假设传入值一定是数组。
6. 路径/URL 字段可以在模型访问器中补充 `*_full_url`。
7. 素材 ID 字段禁止在模型访问器中查素材表或调用只能处理路径的 `buildUploadUrl()` 伪装成 URL 回显。
8. 素材 ID 的 `*_full_url` 回显必须在 Service 层或专用回显服务中批量解析，避免模型访问器隐式 N+1。
9. 关联字段参与筛选、排序、聚合时，使用 `join`、`exists` 或明确的关联 ID 查询，不依赖 `with()`。
10. 禁止在 Controller / Service / Model 中使用 `var_dump`、`dump`、`echo`、`die` 这类会污染接口响应的调试输出。

## 推荐做法

1. 正式字段先在 Model 中声明 `$json`、`$jsonAssoc`、`$append`。
2. 一对一、一对多等简单展示关系先定义模型关联，读取时优先 `with()`。
3. 读取路径/URL 字段时，可以通过 `get*Attr()` 补充展示字段。
4. 读取素材 ID 字段时，用 `AssetHydrator` 或等价批量解析工具补充 `*_full_url`。
5. 保存时让 Service 只做结构归一化，再交给 Model 持久化。
6. 若 Swoole 常驻进程未刷新字段定义，优先重启服务，不在业务代码里加绕行逻辑。

## 素材字段判定

- 字段类型或注释是素材 ID，例如 `bigint unsigned COMMENT '头像素材ID'`：不要在模型访问器里拼 URL。
- 字段类型是路径字符串，例如 `varchar(255) COMMENT '头像'` 且存 `/static/...`、`/uploads/...`、`https://...`：可以使用 `buildUploadUrl()`。
- 同一个接口返回多条记录时，素材 URL 必须批量解析，不允许每条记录独立查素材。

## 反例

- 在 `User::getAvatarFullUrlAttr()` 里把素材 ID `1` 传给 `buildUploadUrl('1')`。
- 在模型访问器里通过素材 ID 查询 `upload_asset` 表。
- Service 中为简单关联展示手写多层 `array_column -> whereIn -> map -> foreach`，而模型已有清晰关系可用。
- 只包一层 `app()->make()` 且没有测试替身价值的私有方法。
- 在 `getSpecMetaAttr()` 里直接 `var_dump($value)`

## 自检清单

- [ ] 简单展示型关联是否已优先使用 `with()`。
- [ ] 关联字段参与查询条件时，是否在查询构建方法中处理。
- [ ] 素材 ID 字段是否避免了模型访问器隐式查询。
- [ ] 素材 URL 是否通过批量回显补充。
- [ ] 路径/URL 字段的访问器是否与 schema 匹配。
- [ ] JSON 字段获取器是否兼容 `think\model\type\Json`。
- [ ] 展示增强是否只追加字段，不覆盖原始存储值。
- [ ] Service 中是否还存在无收益薄封装。
- [ ] 接口链路中是否已清除调试输出。
