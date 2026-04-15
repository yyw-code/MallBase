# ThinkPHP 规则：商品主图与轮播图一致性

## 适用范围

`backend/app/admin/service/goods/GoodsService.php` 及商品查询/保存链路。

## 强制规则

1. `main_image` 为空时，保存前必须兜底使用 `images[0].url`。
2. 列表返回时若 `main_image` 为空，必须补首图兜底，避免列表主图空白。
3. 详情返回时若 `main_image` 为空，必须补首图兜底。
4. 图片字段返回应包含可直接展示的完整地址字段（如 `main_image_full_url`、`full_url`）。

## 自检清单

- [ ] 新增商品仅传 `images` 也能在列表看到主图。
- [ ] 编辑商品主图为空但轮播图有值时，能正确回显主图。
- [ ] 前端不再依赖手动拼接域名。
