# 升级 Agent

升级 Agent 不是常驻服务。它只在管理员创建升级或回滚任务后启动一次，执行完并短暂展示只读状态页后退出。

## 实际流程

1. MallBase 后台选择目标版本并创建任务。
2. PHP 写入 `upgrade/run/requests/<job-id>.json`；文件中没有 Platform token。
3. `systemd.path` 发现请求，启动一次 `mallbase-agent run-job`。
4. Agent 从 `upgrade/config/instance.json` 读取实例 ID、token 和激活状态，校验升级包并执行升级或回滚；该文件不保存 Platform 地址。
5. Agent 把结果写入 `upgrade/jobs/<job-id>/record.json`；MallBase 后台负责长期展示记录。

`/upgrade/` 只代理当前任务的临时状态页。没有任务时端口 `18081` 不监听，这是正常状态。

## 代码和部署文件

```text
mall-base/
├── backend/
│   ├── app/controller/admin/upgrade/UpgradeController.php  # 后台升级接口
│   ├── app/service/admin/upgrade/UpgradeAdminService.php   # 创建任务和读取记录
│   ├── app/command/UpgradeRuntimeCommand.php                # 固定 PHP CLI 入口
│   ├── app/model/upgrade/UpgradeRecord.php                 # record.json 文件模型
│   └── route/api/admin/upgrade.php                         # 权限和路由
├── frontend/admin/apps/web-antd/
│   ├── src/api/system/upgrade.ts                           # PHP 升级 API 客户端
│   ├── src/views/system/upgrade/index.vue                  # 版本、操作和历史页面
│   └── src/views/_core/maintenance/index.vue               # 跳转当前临时状态页
├── deploy/
│   ├── docker/host-preflight.sh                            # 创建目录并校验权限/二进制
│   ├── systemd/mallbase-agent@.path                        # 监听一次性请求
│   ├── systemd/mallbase-agent@.service                     # 执行一次 run-job
│   └── nginx/mallbase.conf                                 # 代理 /upgrade/
└── upgrade/bin/
    ├── mallbase-agent-linux-amd64                           # 发布候选，只读
    ├── mallbase-agent-linux-arm64                           # 发布候选，只读
    ├── checksums.sha256                                     # 发布候选校验
    └── active/mallbase-agent                                # 运行时入口，不进入发布包
```

职责边界：

- 后台 PHP 是权限、目标版本、任务创建和长期历史的事实来源。
- Agent 只处理宿主机文件、发布包验签、备份和最近备份回滚。
- 实例 token 只保存在 `upgrade/config/instance.json`，请求文件只保存临时页面票据的 SHA-256；PHP 不保存或向 Agent 传递 Platform 地址。
- `/upgrade/` 页面只能查看一个正在执行或刚完成的任务，不能选择版本或再次发起操作。

## PHP 加载版本目录

PHP 不配置、接收、持久化或拼接 Platform 域名，也不提供可覆盖该域名的环境变量。后台加载可用版本时只以 argv 数组执行已校验的固定活动入口，不经过 shell：

```text
<MallBase>/upgrade/bin/active/mallbase-agent catalog
```

该命令无额外参数且 stdin 为空。Agent 使用编译时固定的 Platform 地址请求 MallBase 公开版本目录；命令不读取或输出 instance token。成功时退出码为 `0`，stderr 为空，stdout 必须是一个紧凑 JSON 对象加一个终止换行，含换行总长不超过 `262144` 字节。失败时退出码为 `1`、stdout 为空，stderr 仅为 `catalog failed: CATALOG_FAILED` 加换行；PHP 对进程、输出或目录结构异常统一返回 `UPGRADE_CATALOG_UNAVAILABLE`，不回显 stderr 或远端正文。

PHP 继续严格校验 `data/releases/packages`。新后台目录只显示存在精确 `package_kind=full` 包的 stable 版本：`from_version` 等于当前版本、`from_storage_layout_version=1`、`to_storage_layout_version=1`，且 `required_bootstrap_version` 缺省或为 `null`。legacy patch 即使与 full 同时存在也会被忽略；只有 patch 的 release 不显示，返回候选的 `package_kind` 固定为 `full`。

catalog 只用于无凭据的后台版本发现，catalog 不代替 resolve。PHP 只把管理员选中的 `target_version` 写入任务；执行 `run-job` 时，Agent 仍从 `instance.json` 读取 instance token，独立完成认证 resolve、签名清单和发布包校验。catalog 结果不构成下载授权，也不作为 resolve 缓存。

## Agent 调用 PHP 的固定 CLI

Agent 不调用本机 HTTP/HMAC 升级路由。唯一入口是：

```bash
cd /srv/mallbase/backend
php think upgrade:runtime
```

命令只从 stdin 读取一个 UTF-8 严格 JSON 对象，最大 `8192` 字节；不接受重复键、尾随 JSON、数组根或未知字段。顶层字段固定为：

```json
{"schema_version":1,"operation":"pause","job_id":"11111111-1111-4111-8111-111111111111","payload":{"action":"upgrade","source_version":"1.2.0","target_version":"1.3.0"}}
```

`job_id` 必须是 UUID。各 operation 的 `payload` 精确字段如下：

| operation | payload |
| --- | --- |
| `pause` | `action`（`upgrade` 或 `rollback`）、`source_version`、`target_version` |
| `backup_database` | `{}` |
| `migrate` | `migration_id`、`version`、`path`、`sha256`；`path` 只能是当前任务 staging 下的 `migrations/<id>.sql` |
| `restore_database` | `source_job_id`、`database_path`、`database_sha256` |
| `awaiting_restart` | `action`、`target_version` |
| `resume` | `{}` |

stdout 始终只有一行稳定 JSON，键顺序固定；stderr 为空：

```json
{"schema_version":1,"ok":true,"operation":"pause","job_id":"11111111-1111-4111-8111-111111111111","data":{"state":"paused","action":"upgrade","source_version":"1.2.0","target_version":"1.3.0"},"error":null}
```

失败时 `data` 为 `null`，`error` 只有稳定错误码；顶层尚未解析成功时 `operation` 或 `job_id` 可为 `null`：

```json
{"schema_version":1,"ok":false,"operation":"pause","job_id":"11111111-1111-4111-8111-111111111111","data":null,"error":{"code":"SIMPLE_UPGRADE_GATE_NOT_PAUSED"}}
```

退出码：成功 `0`，输入/JSON 错误 `2`，状态冲突 `3`，其他运行错误 `1`。SQL 文件由 PHP 校验路径与 SHA-256 后执行，PHP 将断点写入 `upgrade/run/simple-migrations.json`；Agent 不读取、拆分或执行 SQL。

## 运行目录

宿主机预检脚本会创建下面的目录；源码仓库不需要保留这些空目录：

| 路径 | 写入方 | 用途 |
| --- | --- | --- |
| `upgrade/config/instance.json` | PHP | 实例 ID、token 和激活状态（无 Platform 地址） |
| `upgrade/run/requests/<job-id>.json` | PHP | 等待 Agent 原子消费的一次性请求 |
| `upgrade/run/simple-migrations.json` | PHP | SQL 迁移状态和失败断点 |
| `upgrade/run/simple-upgrade.lock` | Agent | 防止两个升级进程并发执行 |
| `upgrade/jobs/<job-id>/record.json` | PHP 创建、Agent 更新 | 后台长期任务记录 |
| `upgrade/backups/` | Agent | 按任务保存的代码备份 |
| `upgrade/packages/` | Agent | 已下载的发布包 |
| `upgrade/staging/` | Agent | 验证后的独占解压目录 |
| `upgrade/agent-private/` | Agent | 不与 PHP 共享的恢复检查点 |

不要手工创建 `0777` 目录或修改任务 JSON。目录所有者、共享组和权限不符合约定时，Agent 会拒绝运行。

生产预检（包括 `--check`）只能对刚完整解压、尚未在线升级的完整发布包执行。必须先在包外完成统一签名或包 SHA-256 验证，再由预检校验包根 `release-files.sha256` 的格式、重复路径、每个文件的 SHA-256、普通文件形态及全部祖先目录，之后才允许调整所有权和权限。清单缺失、包含绝对路径、`.`/`..`、反斜杠、符号链接或运行数据路径时直接失败。源码 checkout 不生成该清单，因此不能作为专用 Agent 用户的生产预检输入。

`release-files.sha256` 和外层 `release-manifest.json` 都是完整 ZIP 的 bootstrap-only 控制文件。Platform 验证后会过滤它们，不把它们放入 Agent 运行时的签名 schema v2 manifest/tar。在线升级会改变 live tree 的普通代码，原始 `release-files.sha256` 随即自然过期，所以预检和 `--check` 都不能作为已运行实例的重复 health check，也不允许运行时刷新这张表或维护第二套 inventory。重新部署必须把对应 full ZIP 完整解压到空的新目录，不能覆盖任意旧源码树；该 ZIP 同时携带对应版本的 Agent 候选和 `checksums.sha256`，然后才能再次执行预检。`.env` 和运行数据继续按下述排除边界单独挂载或迁移。

项目根、`release-files.sha256`、清单列出的普通发布文件及其祖先目录统一由 `mallbase-agent:mallbase-upgrade` 持有。项目根和普通受管祖先目录精确为 `0755`；普通文件按是否包含执行位规范为精确 `0644` 或 `0755`，不保留额外的 group/world 写位，从而符合 Agent securefs 合同并允许 Agent 在同目录创建临时文件后原子替换。预检不使用递归 `chown`，并明确排除 `data/`、`.env`、backend runtime/vendor/uploads/storage/cert、upgrade 运行目录、依赖缓存和构建缓存；这些目录中的既有用户数据不会被递归改所有者。

正式发布不能仅凭 MallBase 仓库中已存在候选文件，就认定它们包含待发布的 Agent 改动。必须先提交 Agent 代码，再在 Agent 仓库使用真实 `VERSION`、`SOURCE_COMMIT` 和 release key 运行 `build-release.sh`；把生成的 `dist/mallbase-agent-linux-amd64`、`dist/mallbase-agent-linux-arm64` 与 `dist/checksums.sha256` 替换到 MallBase 的 `upgrade/bin/`，并由 CI 校验 `dist/agent-manifest.json` 与这些构建产物一致，之后才允许组装 MallBase full ZIP。`agent-manifest.json` 只作 Agent 构建和发布证据，不进入 Agent 运行时包。

完整 ZIP 归档态中，两个 Agent 候选固定为 `0755`，`checksums.sha256` 固定为 `0644`。这些 mode 由 MallBase 发布构建器写入 ZIP entry；Platform 只负责验签、生成或传递运行时签名清单与包，保持 schema v2 `manifest.files[]` 和 runtime tar 中候选的 `0755`，并过滤 bootstrap-only 的 `checksums.sha256`。Platform 不在 MallBase 宿主机执行 `chmod`。

host-preflight 在宿主机离线安装阶段将候选收紧为 `0555`，将 `checksums.sha256` 收紧为 `0444`。安装态的 `upgrade/` 和 `upgrade/bin/` 均由 Agent 用户持有并固定为 `0750`；`checksums.sha256` 只作为构建、发布和首次预检证据，Agent 运行时不更新它。`upgrade/bin/active/` 固定为 `0750`，活动二进制 `active/mallbase-agent` 固定为 `0755`，二者同样由 Agent 用户持有。运行时不依赖独立 `agent-manifest.json`。

完整发布包只携带 `upgrade/bin/mallbase-agent-linux-amd64` 和 `upgrade/bin/mallbase-agent-linux-arm64`，不包含 `upgrade/bin/active/`。Agent 在任务最后一步按 `runtime.GOARCH` 映射固定路径：`amd64` 对应 `upgrade/bin/mallbase-agent-linux-amd64`，`arm64` 对应 `upgrade/bin/mallbase-agent-linux-arm64`。Agent 从已验签的 Platform schema v2 `manifest.files[]` 中选择该固定路径的唯一条目，校验 `path`、`sha256`、`size`、`mode`，再从 staging 读取候选内容并写入 `active/` 内的同目录临时文件。临时文件设置为 `0755` 并同步到磁盘后，原子重命名为固定的 `active/mallbase-agent`，最后同步 `active/` 目录；不支持的架构或匹配项不唯一时必须失败。

外层完整发布组装元数据如果继续保留 `release-manifest.json` 的 `agent.artifacts[]`，它只描述 full ZIP 的组装产物，不是 Agent 运行时协议。Agent 运行时不读取 `agent.artifacts[]`，也不依赖独立 `agent-manifest.json`。

从旧 Agent 切换到本协议必须执行一次性离线引导。已发布旧 Agent 的 `manifest.NormalizePath` 会拒绝 `upgrade/**`，旧 Runner 也没有 Agent 自更新步骤，因此旧二进制不能通过普通在线任务替换自己。必须先停止 Agent 对应的 systemd `.path` 和 `.service`，把已验签的新 full ZIP 完整解压到空的新目录，再在尚未发生在线升级的目录运行 host-preflight；预检按宿主机架构选择候选，原子安装到 `upgrade/bin/active/mallbase-agent`，并校验活动二进制与该候选的 SHA-256 一致。

完成这次引导、确认运行的是新 Agent 后，未来在线升级才由新 Runner 在代码和 SQL 均成功后按 `runtime.GOARCH` 选择 schema v2 `manifest.files[]` 中的固定候选并原子更新 active。执行更新的当前进程继续完成任务并退出，下一次 systemd 任务才使用新二进制。旧 Platform legacy 路由只能维持旧下载协议，不能为旧二进制补上不存在的自更新能力，也不能作为 bridge 自动更新到新协议。

systemd 的路径权限只保留下面三层嵌套规则：

```ini
ProtectSystem=strict
ReadWritePaths=%f
ReadOnlyPaths=%f/upgrade/bin
ReadWritePaths=%f/upgrade/bin/active
```

`%f` 允许 Agent 原子替换普通发布目标并写任务工作区，`upgrade/bin` 随后重新收紧为只读，`upgrade/bin/active` 是 bin 子树唯一可写例外。候选文件虽由 Agent 用户持有，在该 mount namespace 中仍不可写。

完整发布包只携带预构建的 `backend/public/admin` 和 `backend/public/client`，不在线构建或发布小程序。

## 安装 systemd 单元

下面命令假设已验签的完整发布包解压到 `/srv/mallbase`，且根目录存在 `release-files.sha256`。Agent 用户和共享组只需创建一次。

host-preflight 是按路径先校验、再 `chown`/`chmod` 的简单 shell 边界，路径操作之间存在 TOCTOU 窗口。因此它必须在 Agent 和 systemd 的 `.path`、`.service` 均已停止、专用 UID 下没有残留 Agent 进程、可信本地文件系统上没有其他部署或宿主进程并发写项目树时离线独占运行。已验签完整包和无并发写者是当前实现保持简单且安全的必要前提；不要把预检当成在线权限修复工具。首次安装没有既有 unit 时可跳过停止命令；重新部署先停止既有 unit，再完整解压对应 full ZIP：

```bash
INSTANCE=$(systemd-escape --path /srv/mallbase)
sudo systemctl stop "mallbase-agent@${INSTANCE}.path"
sudo systemctl stop "mallbase-agent@${INSTANCE}.service"
```

确认没有并发写者后执行预检，并立即用 `--check` 校验同一离线目录：

```bash
sudo groupadd --system mallbase-upgrade
sudo useradd --system --gid mallbase-upgrade --home-dir /nonexistent --shell /usr/sbin/nologin mallbase-agent

cd /srv/mallbase
sudo MALLBASE_AGENT_USER=mallbase-agent sh deploy/docker/host-preflight.sh
sudo MALLBASE_AGENT_USER=mallbase-agent sh deploy/docker/host-preflight.sh --check
sudo install -m 0644 deploy/systemd/mallbase-agent@.service /etc/systemd/system/
sudo install -m 0644 deploy/systemd/mallbase-agent@.path /etc/systemd/system/

INSTANCE=$(systemd-escape --path /srv/mallbase)
sudo systemctl daemon-reload
sudo systemctl enable --now "mallbase-agent@${INSTANCE}.path"
```

如果用户或组已经存在，跳过对应的创建命令。Docker 后端通过根目录 `.env` 中的 `MALLBASE_UPGRADE_SHARED_GID` 与 Agent 共享任务目录。

## 验证

```bash
INSTANCE=$(systemd-escape --path /srv/mallbase)
sudo systemctl status "mallbase-agent@${INSTANCE}.path"
sudo journalctl -u "mallbase-agent@${INSTANCE}.service" -n 100 --no-pager
```

常见情况：

- 后台提示 Agent 未及时启动：先检查 `.path` 是否启用，再看 `.service` 日志。
- `HOST_PREFLIGHT_*`：目录所有者、共享组或权限不符合约定，重新执行预检，不要手工放宽到 `0777`。
- `/upgrade/` 返回 `502`：当前没有任务，或任务进程已结束；长期记录请在 MallBase 后台查看。
