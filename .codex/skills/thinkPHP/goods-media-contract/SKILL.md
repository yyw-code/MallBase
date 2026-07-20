---
name: goods-media-contract
description: MallBase ThinkPHP 商品媒体与主图同步契约；处理 main_image、main_video、images、SKU/规格图片的素材 ID 保存、首图兜底、AssetIdNormalizer、AssetHydrator、full_url 回显或商品媒体字段变更时使用。
---

# 商品媒体与主图契约

## 存储语义

当前 schema 中：

- `mb_goods.main_image`、`main_video` 存素材 ID。
- `mb_goods.images` 存轮播图素材 ID 数组 JSON。
- SKU 图片和 `spec_meta.values.pic` 同样按素材 ID 处理。

不要把完整 URL 写回这些正式字段。兼容旧输入或混合结构时，通过 `AssetIdNormalizer` 归一化，新增代码以素材 ID 为正式协议。

## 保存链路

1. 保持 `GoodsController` 的参数白名单、`GoodsValidate` 场景、`GoodsService` 保存数据和 schema 同步。
2. 在事务前归一化 `images`、`main_image`、`main_video`、规格图片和 SKU 图片。
3. `main_image` 为空且轮播图有值时，用首个有效素材 ID 兜底；不要读取 `images[0].full_url` 作为存储值。
4. 保存前通过 `AssetService::assertUsableAssets()` 或现有等价入口校验引用。
5. 保存后沿用 `syncUsage()` 维护商品字段与素材引用关系。

## 回显链路

1. 列表和详情先保留原始素材 ID，再通过 `AssetHydrator` 批量追加展示地址。
2. 列表使用 `hydrateGoodsList()`，详情使用 `hydrateGoodsDetail()`；不要逐行查询素材表。
3. 对外展示字段使用 `main_image_full_url`、`main_video_full_url`、图片项 `full_url`、SKU `image_full_url` 等已有契约。
4. 主图兜底后同步生成对应 `main_image_full_url`，避免 ID 已补但展示地址仍为空。

## 正式字段

商品媒体字段定型后，直接依赖 schema 和迁移结果。不要在业务 Service 中加入 `SHOW COLUMNS`、`has*Column()` 或“字段不存在就静默丢弃”的运行时结构兼容；数据库升级边界遵循 `dev-schema-upgrade-sql`。

## 自检

- [ ] 数据库存的是素材 ID，不是 `full_url`。
- [ ] 只传轮播图时，主图能以首个有效素材 ID 兜底。
- [ ] 列表和详情通过批量 Hydrator 回显。
- [ ] 参数白名单、验证器、Service、Model/schema 契约一致。
