# ThinkPHP Skills 索引（MallBase）

后端规则按子目录拆分，请按场景选用：

1. `architecture-layering`：三层架构与调用边界
2. `service-stateless-swoole`：Swoole 协程安全与 Service 无状态
3. `validate-then-transact`：先校验再事务
4. `list-query-sync`：分页 list/total 条件同源
5. `list-return-compact`：分页返回 compact 规范
6. `route-permission-system`：后台路由、命名和权限字段规范
7. `ide-generic-annotation`：Controller/Service 泛型注释与 IDE 跳转规范
8. `goods-image-main-sync`：商品主图与轮播图一致性规则
9. `backend-test-baseline`：后端测试基线与执行约束
10. `schema-first-no-transition-compat`：正式字段优先，禁止长期过渡兼容
11. `model-field-accessor-first`：正式字段优先走模型访问器/修改器
12. `goods-sku-unified-sales-unit`：商品统一售卖单元必须落 SKU
13. `build-list-query-only`：列表查询统一使用 Service::buildListQuery
14. `region-snapshot-invalid-on-change`：地区业务双存快照，更新后标记失效不自动迁移
