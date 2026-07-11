# Swoole 并发配置建议

## 1. 适用范围

本文用于 MallBase 在 Swoole HTTP 模式下配置并发容量，重点覆盖：

- Swoole worker 数
- Swoole 最大连接数与监听队列
- 数据库、Redis、缓存连接池
- 订单创建等高并发写入接口的容量评估

本文不是固定生产参数。实际生产配置必须结合 CPU、内存、MySQL `max_connections`、Redis、Nginx、业务接口耗时和压测结果调整。

## 2. 核心原则

### 2.1 并发连接数不等于业务吞吐

`SWOOLE_MAX_CONN` 和 `SWOOLE_BACKLOG` 解决的是“能不能接住连接”和“连接能不能排队”的问题，不代表业务一定处理得快。

订单创建这类接口最终会受这些因素限制：

- PHP worker 数
- DB 连接池容量
- MySQL 行锁等待
- SKU 库存扣减 SQL
- Redis 幂等键读写
- 事务内写入表数量
- 商品、会员、积分、分销等扩展逻辑

### 2.2 worker 不要盲目开太多

worker 数过少会导致请求排队严重；worker 数过多会放大数据库连接数和内存占用。

建议从 CPU 核数附近开始：

```text
SWOOLE_WORKER_NUM = CPU 核数 或 CPU 核数的 1 到 2 倍
```

订单创建、支付回调这类 DB 写入多的接口，不建议只靠增加 worker 提升容量。worker 增加后，如果 DB 承受不了，会把瓶颈转移到 MySQL。

### 2.3 连接池必须受 MySQL 上限约束

如果每个 worker 都可能持有独立连接池，理论最大 DB 连接占用接近：

```text
最大 DB 连接需求 = Swoole 实例数 * worker_num * SWOOLE_DB_POOL_MAX_ACTIVE
```

实际部署前必须确认 MySQL：

```sql
SHOW VARIABLES LIKE 'max_connections';
SHOW STATUS LIKE 'Threads_connected';
SHOW STATUS LIKE 'Max_used_connections';
```

建议预留至少 20% 到 30% 连接给后台管理、定时任务、迁移脚本、临时排查和其它服务。

## 3. 关键配置说明

| 配置 | 作用 | 建议 |
|------|------|------|
| `SWOOLE_WORKER_NUM` | HTTP worker 数 | 从 CPU 核数开始，订单写入场景不要盲目超过 CPU 2 倍 |
| `SWOOLE_MAX_CONN` | Swoole 最大连接数 | 必须大于预期瞬时连接数 |
| `SWOOLE_BACKLOG` | TCP listen 队列 | 瞬时并发高时建议不小于目标并发的 1 到 2 倍 |
| `SWOOLE_MAX_REQUEST` | worker 处理多少请求后重启 | 保持默认 2000 可作为开发和小生产起点 |
| `SWOOLE_MAX_WAIT_TIME` | reload / stop 等待时间 | 高并发写入建议 60 秒以上 |
| `SWOOLE_POOL_MAX_WAIT_TIME` | 连接池等待时间 | 高峰期建议 5 到 10 秒，过大只会放大排队 |
| `SWOOLE_DB_POOL_MAX_ACTIVE` | DB 连接池最大活跃数 | 结合 MySQL 上限和 worker 数计算 |
| `SWOOLE_CACHE_POOL_MAX_ACTIVE` | Cache 连接池最大活跃数 | Redis 压力通常低于 MySQL，可略高于 DB |
| `SWOOLE_REDIS_POOL_MAX_ACTIVE` | Redis 连接池最大活跃数 | 幂等、缓存、队列共用时需要单独压测 |

## 4. 本地 1000 并发测试参考配置

本地订单创建 1000 并发测试通过时使用：

```dotenv
SWOOLE_WORKER_NUM=4
SWOOLE_MAX_CONN=4096
SWOOLE_BACKLOG=2048
SWOOLE_POOL_MAX_WAIT_TIME=10
SWOOLE_DB_POOL_MAX_ACTIVE=32
SWOOLE_CACHE_POOL_MAX_ACTIVE=32
SWOOLE_REDIS_POOL_MAX_ACTIVE=32
```

测试结果：

| 指标 | 值 |
|------|----|
| 并发请求 | 1000 |
| 成功 | 1000 |
| 失败 | 0 |
| 总耗时 | 7156.55 ms |
| 吞吐 | 139.73 RPS |
| p50 | 4236.71 ms |
| p95 | 6948.92 ms |
| p99 | 7100.34 ms |

这个配置能接住本地 1000 个瞬时下单请求，但 p95 接近 7 秒，说明请求主要是在排队处理。它适合作为开发压测参考，不应直接作为生产最终参数。

## 5. 并发目标配置建议

下面是单实例 Swoole 的起步建议。上线前必须重新压测。

### 5.1 本地开发与功能联调

适用场景：

- 单人开发
- 功能联调
- 小规模接口验证

```dotenv
SWOOLE_WORKER_NUM=1
SWOOLE_MAX_CONN=1024
SWOOLE_BACKLOG=512
SWOOLE_DB_POOL_MAX_ACTIVE=3
SWOOLE_CACHE_POOL_MAX_ACTIVE=3
SWOOLE_REDIS_POOL_MAX_ACTIVE=3
SWOOLE_POOL_MAX_WAIT_TIME=5
```

说明：

- 资源占用低。
- 不适合 500 到 1000 瞬时下单压测。
- 如果要做本地并发测试，再临时提高 worker 和连接池。

### 5.2 本地压测 1000 瞬时请求

适用场景：

- 验证订单创建、库存扣减、幂等、积分账户初始化等并发正确性。

```dotenv
SWOOLE_WORKER_NUM=4
SWOOLE_MAX_CONN=4096
SWOOLE_BACKLOG=2048
SWOOLE_DB_POOL_MAX_ACTIVE=32
SWOOLE_CACHE_POOL_MAX_ACTIVE=32
SWOOLE_REDIS_POOL_MAX_ACTIVE=32
SWOOLE_POOL_MAX_WAIT_TIME=10
```

说明：

- 本地实测订单创建 1000 并发通过。
- MySQL CPU 会成为主要瓶颈之一。
- 不建议长期作为普通开发默认配置，避免占用过高。

### 5.3 小型生产或演示站

适用场景：

- 2 到 4 核机器
- 中低流量商城
- 管理端与 C 端共用一个后端实例

建议起点：

```dotenv
SWOOLE_WORKER_NUM=2
SWOOLE_MAX_CONN=2048
SWOOLE_BACKLOG=1024
SWOOLE_DB_POOL_MAX_ACTIVE=8
SWOOLE_CACHE_POOL_MAX_ACTIVE=16
SWOOLE_REDIS_POOL_MAX_ACTIVE=16
SWOOLE_POOL_MAX_WAIT_TIME=5
```

如果压测显示请求排队明显、CPU 仍有余量、MySQL 连接与慢查询正常，可以调整为：

```dotenv
SWOOLE_WORKER_NUM=4
SWOOLE_MAX_CONN=4096
SWOOLE_BACKLOG=2048
SWOOLE_DB_POOL_MAX_ACTIVE=12
SWOOLE_CACHE_POOL_MAX_ACTIVE=24
SWOOLE_REDIS_POOL_MAX_ACTIVE=24
SWOOLE_POOL_MAX_WAIT_TIME=5
```

### 5.4 中型生产

适用场景：

- 4 到 8 核机器
- 有一定活动流量
- MySQL 和 Redis 独立部署

建议起点：

```dotenv
SWOOLE_WORKER_NUM=4
SWOOLE_MAX_CONN=4096
SWOOLE_BACKLOG=2048
SWOOLE_DB_POOL_MAX_ACTIVE=16
SWOOLE_CACHE_POOL_MAX_ACTIVE=32
SWOOLE_REDIS_POOL_MAX_ACTIVE=32
SWOOLE_POOL_MAX_WAIT_TIME=5
```

若接口耗时稳定、MySQL 连接数充足、CPU 仍有余量，可逐步提高：

```dotenv
SWOOLE_WORKER_NUM=8
SWOOLE_MAX_CONN=8192
SWOOLE_BACKLOG=4096
SWOOLE_DB_POOL_MAX_ACTIVE=16
SWOOLE_CACHE_POOL_MAX_ACTIVE=32
SWOOLE_REDIS_POOL_MAX_ACTIVE=32
SWOOLE_POOL_MAX_WAIT_TIME=5
```

注意：

- 不建议一开始把 DB pool 拉到 64 或更高。
- MySQL `Max_used_connections` 如果接近上限，应先优化 SQL、索引、事务长度或扩容数据库。

### 5.5 2000 以上瞬时下单

不建议只靠单实例提高参数解决。

建议路线：

1. Nginx 负载均衡多个 Swoole 实例。
2. MySQL 独立部署并调高连接上限。
3. Redis 独立部署，确认网络延迟与连接池容量。
4. 热点商品活动引入更严格的库存保护策略。
5. 将非必要写入移出主事务或异步化。
6. 压测支付、分销、积分、退款完整链路，而不是只压订单创建。

## 6. 订单接口专项建议

### 6.1 客户端必须传 `idempotency_key`

订单创建接口应由客户端传入稳定的 `idempotency_key`。同一用户同一次提交必须复用同一个 key，页面重试或网络重发不应生成新 key。

否则可能出现：

- 用户快速重复点击生成多单。
- 网络超时后前端重试生成新单。
- 压测数据误判为服务重复下单。

### 6.2 库存扣减必须保持原子性

库存扣减应保持条件更新或行锁保护，不能先查库存再无条件扣减。

推荐检查点：

- `stock >= quantity` 条件是否在写入 SQL 中体现。
- 订单创建失败时是否回滚库存相关写入。
- 同一 SKU 高并发下是否出现库存负数。

### 6.3 事务里只保留必要写入

订单创建事务应尽量短：

- 不在事务内请求远程服务。
- 不在事务内做大范围查询。
- 不在事务内生成复杂报表。
- 非关键日志、通知、统计尽量异步。

### 6.4 支付与订单创建分开压测

订单创建通过不代表支付链路通过。支付链路至少需要单独验证：

- 同一订单重复支付回调。
- 支付成功和订单关闭竞争。
- 支付成功触发积分、会员、分销扩展事件。
- 回调失败后的重试幂等。

## 7. 监控指标

压测和生产运行时至少关注：

### 7.1 Swoole

- worker 数是否符合预期。
- 是否有 worker abnormal exit。
- 是否出现连接重置。
- 请求 p95 / p99 是否持续升高。
- reload 是否能平滑完成。

### 7.2 MySQL

```sql
SHOW PROCESSLIST;
SHOW VARIABLES LIKE 'max_connections';
SHOW STATUS LIKE 'Threads_connected';
SHOW STATUS LIKE 'Max_used_connections';
SHOW ENGINE INNODB STATUS;
```

重点看：

- 活跃连接数
- 锁等待
- 死锁
- 慢查询
- CPU 与磁盘 IO

### 7.3 Redis

```bash
redis-cli INFO clients
redis-cli INFO stats
redis-cli INFO commandstats
```

重点看：

- connected_clients
- rejected_connections
- total_error_replies
- keyspace_hits / keyspace_misses
- SETNX / GET / DEL 延迟

## 8. 推荐压测矩阵

| 场景 | 并发 | 目标 |
|------|------|------|
| 普通订单创建 | 100 / 300 / 500 / 1000 | 验证库存、订单、幂等 |
| 同一用户重复提交 | 50 / 100 | 验证 `idempotency_key` |
| 同一订单支付回调 | 100 / 500 | 验证支付幂等 |
| 订单创建 + 支付回调 | 100 / 300 / 500 | 验证交易闭环 |
| 分销订单支付 | 100 / 300 / 500 | 验证佣金冻结与结算 |
| 退款并发 | 50 / 100 / 300 | 验证库存回补和资金回退 |

每次压测后至少校验：

- 订单数
- 订单项数量
- SKU 库存
- 重复订单号
- 同用户重复订单
- 支付日志唯一性
- 积分账户唯一性
- 分销佣金记录唯一性
- Redis 幂等键清理策略

## 9. 调参顺序

建议按这个顺序调参：

1. 先确认业务正确性：库存、订单、幂等、事务回滚。
2. 再提高 `SWOOLE_MAX_CONN` 和 `SWOOLE_BACKLOG`，确保连接能接住。
3. 调整 `SWOOLE_WORKER_NUM`，观察 CPU 和 p95 / p99。
4. 调整 DB / Redis 连接池，观察 MySQL 连接数和锁等待。
5. 优化 SQL 和事务长度。
6. 单实例到达瓶颈后，再考虑多实例和负载均衡。

不要一开始就把所有参数拉满。那样可能只是把压力从 Swoole 转移到 MySQL，并让故障更难定位。

## 10. 当前建议

对 MallBase 当前订单创建链路，建议保留两套配置思路：

| 环境 | 建议 |
|------|------|
| 普通开发 | 保持低 worker、小连接池，节省资源 |
| 本地压测 | 使用 4 worker、4096 连接、2048 backlog、32 连接池 |
| 小生产 | 从 2 到 4 worker、8 到 16 DB pool 起步 |
| 中生产 | 从 4 到 8 worker、16 DB pool 起步 |
| 大活动 | 多实例 + 独立 MySQL / Redis + 完整链路压测 |

如果目标是稳定承受 1000 个用户瞬时下单，单实例至少需要：

```dotenv
SWOOLE_WORKER_NUM=4
SWOOLE_MAX_CONN=4096
SWOOLE_BACKLOG=2048
SWOOLE_DB_POOL_MAX_ACTIVE=16
SWOOLE_CACHE_POOL_MAX_ACTIVE=32
SWOOLE_REDIS_POOL_MAX_ACTIVE=32
SWOOLE_POOL_MAX_WAIT_TIME=5
```

如果压测中出现连接池等待超时，再逐步把 DB pool 提到 24 或 32。不要在没有 MySQL 连接数和慢查询证据时直接提高到 64。
