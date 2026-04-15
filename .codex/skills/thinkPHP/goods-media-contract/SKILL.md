# ThinkPHP 规则：商品媒体字段契约（主图/主视频）

## 适用范围

- `backend/app/admin/controller/goods/GoodsController.php`
- `backend/app/admin/service/goods/GoodsService.php`
- `backend/app/admin/model/goods/Goods.php`
- `backend/app/admin/validate/goods/GoodsValidate.php`

## 强制规则

1. 商品媒体字段统一包含：`main_image`、`main_video`。
2. Controller 的 `create/update` 参数白名单必须与验证器场景保持一致，避免字段漏传。
3. `Goods` 模型必须追加 `main_image_full_url`、`main_video_full_url`，前端禁止自行拼接域名。
4. 数据库结构一旦确定，代码必须直接依赖正式字段，不允许在 Service 层增加“字段存在性探测 / 自动降级忽略字段”的过渡逻辑。
5. 列表/详情在做主图兜底时，要同步回填 `main_image_full_url`，避免“有图路径但无完整地址”。

## 自检清单

- [ ] 创建/编辑商品可正常提交 `main_video`。
- [ ] 数据库迁移执行后，代码中不存在 `SHOW COLUMNS`、`has*Column()`、`filter*Data()` 这类临时兼容逻辑。
- [ ] 列表和详情中的 `main_image_full_url`、`main_video_full_url` 可直接展示。
