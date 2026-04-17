# ThinkPHP 规则：正式字段优先，禁止长期过渡兼容

## 适用范围

- 所有后端业务模块的字段新增、字段改名、接口结构升级
- 重点检查：`app/admin/service/**`、`app/admin/controller/**`、`app/admin/model/**`

## 强制规则

1. 数据库字段、接口字段一旦确定为正式结构，必须同步完成：
   - 数据库迁移 / SQL
   - 安装 SQL
   - Controller 参数白名单
   - Validate 场景
   - Model 输出字段
   - 前端类型与页面
2. 不允许为了绕过未执行迁移而加入长期过渡逻辑，例如：
   - `SHOW COLUMNS`
   - `DESCRIBE`
   - `information_schema` 探测字段
   - `has*Column()` / `filter*Data()` 之类运行时结构判断
   - “有新字段就传，没有就静默忽略”的业务降级
3. 允许保留的兼容仅限协议归一化，且必须满足：
   - 有明确淘汰目标
   - 只做输入/输出标准化
   - 不涉及运行时数据库结构探测
4. 若发现真实数据库未执行迁移，优先补 SQL，不要在业务代码里做临时兼容。

## 推荐做法

1. 先补正式 SQL
2. 再删临时兼容代码
3. 最后补测试，验证正式结构可用

## 反例

- `hasMainVideoColumn()`
- `filterMainVideoData()`
- `Db::query(\"SHOW COLUMNS ...\")`

## 自检清单

- [ ] 新增字段是否已同步到真实数据库与 `backend/app/install/data/schema`。
- [ ] Service/Controller/Validate/Model/前端类型是否已全部接通。
- [ ] 代码中是否残留运行时字段探测或静默忽略字段逻辑。
