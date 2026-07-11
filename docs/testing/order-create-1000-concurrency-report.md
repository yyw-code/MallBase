# 订单创建 1000 并发压测报告

## 1. 结论

本次压测覆盖的是 C 端订单创建接口：

- 接口：`POST /client/api/order/create`
- 场景：1000 个不同用户同时下单，每人 1 单，每单 1 件同一 SKU
- 库存：压测前 SKU 库存 1200
- 结果：1000 个请求全部下单成功，失败 0
- 数据一致性：订单数、订单项数量、库存扣减、订单号唯一性、用户订单唯一性均符合预期

本次验证说明：在本地 Docker 开发环境中，调整 Swoole worker、连接数、backlog 和连接池后，订单创建链路可以承受 1000 个不同用户同时提交下单请求，并保持库存与订单数据一致。

需要注意：这不是生产容量承诺。本次测试环境是本地 Docker，且只覆盖订单创建，不覆盖支付回调、分销结算、售后退款、物流发货等后续链路。

## 2. 测试环境

| 项目 | 值 |
|------|----|
| 测试日期 | 2026-07-08 |
| 后端运行方式 | Docker 开发全套 |
| 后端容器 | `mallbase-dev` |
| HTTP 地址 | `http://127.0.0.1:8080` |
| PHP | 8.2.31（容器运行时） |
| Swoole | 6.2.1 |
| ThinkPHP | 8.1.4 |
| MySQL | 8.0 |
| Redis | 7-alpine |

压测前后均使用真实 HTTP 请求进入 Swoole 服务，不直接调用 Service 或数据库。

## 3. 测试目标

本次压测主要确认三类问题：

1. 1000 个不同用户同时下单时，HTTP 服务是否能保持可用。
2. 同一 SKU 被高并发扣减库存时，是否出现超卖、少扣、重复订单号等数据问题。
3. 订单创建链路中的积分账户初始化、幂等键、订单写入是否存在并发错误。

## 4. 压测数据准备

| 数据 | 数量 |
|------|------|
| 测试用户 | 1000 |
| 用户地址 | 1000 |
| JWT access token | 1000 |
| 商品 SPU | 1 |
| 商品 SKU | 1 |
| SKU 初始库存 | 1200 |
| 每个请求下单数量 | 1 |

每个用户使用独立手机号、独立地址、独立 JWT、独立 `idempotency_key`。

## 5. 第一次压测结果

第一次压测使用原本开发环境配置：

| 配置项 | 当时值 |
|--------|--------|
| Swoole worker | 1 |
| DB pool `max_active` | 3 |
| Cache pool `max_active` | 3 |

结果：

| 指标 | 值 |
|------|----|
| 请求数 | 1000 |
| 成功数 | 183 |
| 失败数 | 817 |
| 主要失败 | `ECONNRESET` / `socket hang up` |

数据库一致性：

| 检查项 | 结果 |
|--------|------|
| 成功订单数 | 183 |
| 订单项数量合计 | 183 |
| 库存剩余 | 1017 |
| 重复订单号 | 0 |
| 同用户重复订单 | 0 |

服务日志出现 Swoole worker 异常退出：

```text
Channel::~Channel() (ERRNO 10003): channel is destroyed, 456 consumers will be discarded
worker(pid=1104, id=0) abnormal exit, status=0, signal=11
```

判断：

- 业务数据一致性没有问题，成功的 183 单都扣减正确。
- HTTP 服务容量不足，单 worker + 小连接池无法承受 1000 个瞬时请求。
- worker 异常退出后，大量连接被重置。

## 6. 调整项

本次调整了本地开发环境的 Swoole 与连接池参数：

| 配置项 | 调整后 |
|--------|--------|
| `SWOOLE_WORKER_NUM` | 4 |
| `SWOOLE_MAX_CONN` | 4096 |
| `SWOOLE_BACKLOG` | 2048 |
| `SWOOLE_DB_POOL_MAX_ACTIVE` | 32 |
| `SWOOLE_CACHE_POOL_MAX_ACTIVE` | 32 |
| `SWOOLE_REDIS_POOL_MAX_ACTIVE` | 32 |
| `SWOOLE_POOL_MAX_WAIT_TIME` | 10 |

同时把 `SWOOLE_MAX_CONN`、`SWOOLE_BACKLOG` 接入后端 Swoole 配置，并补齐 Docker env 同步脚本，避免根 `.env` 与 `backend/.env` 不一致。

服务重启后日志确认：

```text
Worker 数量: 4
连接池: DB (max_active=32), Cache (max_active=32)
```

进程确认：

```text
swoole: http server #0 process for ThinkPHP
swoole: http server #1 process for ThinkPHP
swoole: http server #2 process for ThinkPHP
swoole: http server #3 process for ThinkPHP
```

## 7. 第二次压测结果

第二次压测使用调整后的配置。

| 指标 | 值 |
|------|----|
| 请求数 | 1000 |
| 成功数 | 1000 |
| 失败数 | 0 |
| HTTP 状态 | 1000 个 `200` |
| 业务状态 | 1000 个 `下单成功` |
| 唯一订单 ID | 1000 |
| 唯一订单号 | 1000 |
| 总耗时 | 7156.55 ms |
| 吞吐 | 139.73 RPS |

延迟分布：

| 指标 | 值 |
|------|----|
| min | 947.27 ms |
| p50 | 4236.71 ms |
| p90 | 6765.87 ms |
| p95 | 6948.92 ms |
| p99 | 7100.34 ms |
| max | 7132.57 ms |

数据库一致性：

| 检查项 | 结果 |
|--------|------|
| 订单数 | 1000 |
| 订单项数量合计 | 1000 |
| 订单日志数 | 1000 |
| SKU 库存剩余 | 200 |
| 按成功数推导库存 | 200 |
| 重复订单号 | 0 |
| 同用户重复订单 | 0 |

压测期间容器资源采样：

| 时间点 | 后端 CPU | 后端内存 | MySQL CPU | MySQL 内存 | Redis CPU |
|--------|----------|----------|-----------|------------|-----------|
| 04:47:06 | 104.85% | 242.9 MiB | 109.75% | 625.8 MiB | 12.19% |
| 04:47:10 | 114.59% | 212.2 MiB | 117.61% | 未记录 | 未记录 |
| 04:47:13 | 76.12% | 183.8 MiB | 2.43% | 未记录 | 未记录 |

压测后日志没有新的 worker 异常退出。

## 8. 数据清理

压测完成后已清理本地临时数据：

- 订单、订单项、订单日志
- 订单会员折扣、积分抵扣、积分赠送记录
- 用户、用户地址
- 用户积分账户、积分流水
- 用户钱包、钱包流水
- 测试商品、SKU、商品详情
- 测试地区数据
- Redis `idem:order:create:*` 幂等键

清理后复查结果均为 0。

## 9. 发现的问题与已处理项

### 9.1 积分账户并发初始化

现象：

- 订单确认页试算时曾出现 `mb_user_points.uk_user_id` 重复键错误。

原因：

- 同一个用户首次访问积分账户时，如果多个请求同时进入，可能同时判断账户不存在并尝试插入。

处理：

- `ensurePoints()` 和锁定账户读取逻辑在遇到唯一键冲突时回读已有账户。
- 保留 `uk_user_id` 唯一索引作为最终一致性保护。

### 9.2 订单状态机旧对象覆盖风险

风险：

- 支付回调、自动任务、用户确认收货等路径可能持有旧订单对象。
- 如果状态已经被其它事务更新，旧对象继续流转可能覆盖最新状态。

处理：

- 状态流转时按订单 ID 在事务内重新 `lock(true)` 读取最新订单。
- 若最新状态已达到目标状态，按幂等处理，不重复写日志。
- 若最新状态不允许流转，抛出业务异常。

### 9.3 Swoole 开发环境容量不足

现象：

- 1 worker + 小连接池下 1000 瞬时请求导致连接重置和 worker crash。

处理：

- 本地开发环境调整 worker、连接数、backlog、连接池。
- 后端配置补充 `SWOOLE_MAX_CONN`、`SWOOLE_BACKLOG`。
- Docker env 同步脚本补齐相关 Swoole 参数。

## 10. 边界与风险

本次压测不能证明以下场景已经满足生产要求：

1. 多实例部署下的全局库存竞争。
2. 支付回调、分销佣金、积分释放、退款回滚等完整订单生命周期。
3. 秒杀类极端热点商品场景。
4. 真实公网链路、Nginx、CDN、移动端弱网重试。
5. MySQL 慢查询、磁盘 IO、连接数上限在生产环境中的长期表现。

如果要验证完整交易闭环，需要增加：

- 创建订单并支付成功的并发压测。
- 同一订单重复支付回调幂等压测。
- 分销佣金冻结、结算、撤销并发压测。
- 退款和库存回补并发压测。
- 多实例 Swoole + 同一 MySQL / Redis 的压测。

## 11. 回归验证

本次改动后已执行：

```bash
php -l backend/config/swoole.php
git diff --check
composer --working-dir backend test
```

结果：

```text
No syntax errors detected in backend/config/swoole.php
Tests: 523, Assertions: 2039, Skipped: 69, Incomplete: 1.
```

结论：

- 后端测试通过。
- Swoole 配置语法通过。
- 相关文件 diff 检查通过。
