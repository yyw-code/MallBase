# AGENTS.md — MallBase 项目执行入口

本文件保留为项目级入口规范，避免规则分散。  
详细规则不在本文件重复定义，统一下沉到项目本地 skills。

## 1. 规则入口

当前项目统一以 `.codex/skills/**/SKILL.md` 为准。  
不再维护多来源优先级，避免规则冲突与重复维护。

## 2. 当前项目私有 skills（主入口）

`/Users/gosowong/code/OpenSource/mall-base/.codex/skills`

### 2.1 ThinkPHP

- `thinkPHP/architecture-layering`
- `thinkPHP/service-stateless-swoole`
- `thinkPHP/validate-then-transact`
- `thinkPHP/list-query-sync`
- `thinkPHP/list-return-compact`
- `thinkPHP/route-permission-system`
- `thinkPHP/ide-generic-annotation`
- `thinkPHP/goods-image-main-sync`
- `thinkPHP/goods-media-contract`
- `thinkPHP/backend-test-baseline`
- `thinkPHP/mall-base-boundary`
- `thinkPHP/dev-no-upgrade-sql`

### 2.2 Vben Admin

- `vbenAdmin/backend-driven-routing`
- `vbenAdmin/upload-component-first`
- `vbenAdmin/iconpicker-standard`
- `vbenAdmin/modal-form-layout`
- `vbenAdmin/admin-theme-consistency`
- `vbenAdmin/api-path-param`
- `vbenAdmin/upload-field-normalize`
- `vbenAdmin/e2e-webantd-realapi`
- `vbenAdmin/goods-spec-toggle-state`

### 2.3 通用

- `docs-linking`
- `open-source-wording`

### 2.4 UniApp（预留）

- `uniapp/api-contract`
- `uniapp/auth-token-flow`
- `uniapp/module-structure`

## 3. 执行约束（硬规则）

1. 后端严格三层：`Controller -> Service -> Model`
2. Swoole 下 Service 必须无状态
3. 事务遵循“先校验再事务”
4. 分页查询 `list/total` 条件同源，返回 `compact('total', 'list')`
5. 后台路由遵循 `System*` 命名、`/:id` 路径参数与权限字段规范
6. 前端优先复用 Upload/IconPicker，路由后端驱动，API 参数与后端一致
7. 开源仓库中的提交、文档、注释、备注、提示文案禁止使用带标签化、轻视性、冒犯性或不适合公开传播的词汇

## 4. 维护策略

- 新增规则：在 `.codex/skills/<stack>/<rule-name>/SKILL.md` 增加
- 修改规则：优先修改 skill，不直接扩写本文件
- 本文件作用：索引、执行入口、硬约束摘要

## 5. Git 提交策略（默认行为）

- 默认不自动执行任何 Git 变更动作。
- 所有 Git 变更动作都必须在计划模式下执行，包括但不限于 `git add`、`git commit`、`git push`、`git checkout -b`、`git switch -c` 以及其他会改变 Git 状态的操作。
- 执行任何 Git 变更动作前，必须先在对话中向用户展示可见的 Git 计划，不能只做内部计划。
- Git 计划至少包含：操作范围、涉及文件、是否排除无关改动、预备执行命令、预备提交信息或分支 / 推送目标。
- 用户未明确确认前，禁止执行任何 Git 变更动作。
- 不允许先做 Git 操作再补充说明，也不允许仅因用户说了“提交代码”就跳过展示计划与确认步骤。
- 只有用户明确要求“提交 / commit / push / 发版 / 提交到远端”时，才执行对应 Git 操作。
- 日常修复 bug、临时排查、文档调整、实验性修改，默认只保留工作区改动，不自动提交。
- `git commit` 提交信息必须使用中文（包含 `type(scope):` 前缀时，冒号后说明也必须中文）。
- `git commit`、文档标题、脚本输出、变更备注等公开文本，必须遵循 `open-source-wording` 规范，避免出现如“`小白`”“`傻瓜式`”“`无脑`”等标签化或不专业词汇。
- 禁止把多个无关功能点混在同一个提交里。
- 若用户明确要求“批量执行/一次性处理”，按用户要求合并为一个提交或按指定粒度拆分提交。
- 若用户只要求提交、但未指定粒度，默认按当前已完成且相互相关的改动整理提交，不自动把无关改动一并提交。

## 6. 智能团队指令（团队模式的唯一执行入口）

本节仅约束“团队模式”的入口。当前项目团队模式只有一套可执行入口：`t1-t6`。  
普通问答、文档说明、轻量排查、单文件查看等非团队任务不受本节约束，按 6.4 场景矩阵处理即可。  
`code-reviewer`、`tdd-guide`、`security-review`、`build-fix`、`e2e-check` 仅作为团队内部职责阶段，不是新的独立命令入口。

### 6.1 团队短命令（固定）

1. `t1`：程序员 + 测试
2. `t2`：程序员 + 测试 + 运维
3. `t3`：UI设计师 + 程序员 + 测试
4. `t4`：架构师 + 程序员 + 测试
5. `t5`：架构师 + UI设计师 + 程序员 + 测试
6. `t6`：架构师 + UI设计师 + 程序员 + 测试 + 运维（最强全链路团队）

### 6.2 触发格式（固定）

- `t1: <需求>`
- `t2: <需求>`
- `t3: <需求>`
- `t4: <需求>`
- `t5: <需求>`
- `t6: <需求>`

示例：
- `t1: 修复商品列表分页和导出问题`
- `t2: 修复线上接口超时并补充回滚预案`
- `t3: 优化商品列表和表单视觉`
- `t4: 重构权限模块并保证兼容`
- `t5: 重构后台页面信息架构并完成交互统一`
- `t6: 完成发布前全链路改造`

### 6.3 角色职责边界

- 架构师：识别架构风险、制定最小可落地方案、沉淀防错规则到 skills
- UI设计师：统一交互与视觉规范，输出可实现方案
- 程序员：实现功能/修复问题，保证代码与规范一致
- 测试：执行回归清单，输出风险与边界场景
- 运维：处理环境、部署、配置、回滚与监控相关事项

### 6.4 适用场景矩阵（按影响范围和风险等级）

- 普通问答、文档说明、轻量排查：可不启用团队模式，按普通任务处理
- 单模块小修复、单点 bug、局部接口修正：默认 `t1`
- 涉及环境、部署、配置、回滚、监控：默认 `t2`
- 涉及后台页面视觉、表单交互、组件体验：默认 `t3`
- 涉及跨层设计、模型结构、接口契约、领域规则：默认 `t4`
- 同时涉及架构和 UI/交互：默认 `t5`
- 涉及发布前审计、全链路改造、跨前后端与环境协同：默认 `t6`

高风险场景不得降级为普通处理：
- 数据库结构修改
- 批量数据修复
- 鉴权、支付、订单主链路改动
- 网关、部署、环境配置变更

### 6.5 默认执行规则

- 未指定命令时，按 6.4 场景矩阵判断：
  - 普通问答、文档说明、轻量排查、单文件查看、配置解释：按普通任务处理，不启用团队模式。
  - 命中 6.4 矩阵中任一开发/修复/改造场景：按对应 `t*` 团队执行；未显式指定且场景模糊时，默认按 `t1` 执行。
  - 命中 9.2 高风险操作：强制按对应 `t*` 团队执行，不得降级为普通任务。
- 所有 `t` 开头命令（`t1/t2/t3/t4/t5/t6` 及后续新增 `t*`）均为**强制多 agent 模式**，禁止单 agent 直接完成。
- `t1` 最少并行 2 个 agent（程序员 + 测试）。
- `t2` 最少并行 3 个 agent（程序员 + 测试 + 运维）。
- `t3` 最少并行 3 个 agent（UI设计师 + 程序员 + 测试）。
- `t4` 最少并行 3 个 agent（架构师 + 程序员 + 测试）。
- `t5` 最少并行 4 个 agent（架构师 + UI设计师 + 程序员 + 测试）。
- `t6` 最少并行 5 个 agent（架构师 + UI设计师 + 程序员 + 测试 + 运维）。
- 所有 `t*` 命令必须输出“分角色结论摘要”。
- 若某角色当次无改动，也必须在摘要中明确标注“已检查，无需改动”。
- 涉及代码改动遵循第 5 节提交策略（默认不自动执行 Git 变更动作；如需执行，必须先展示计划并得到用户确认）。

## 7. 团队内部职责阶段（不是独立命令）

团队执行时，默认按以下 5 类职责覆盖，不把它们误写成新的 agent 命令：

1. 方案规划：明确目标、边界、改动路径、回滚点
2. 实现开发：完成代码、配置、脚本或页面改动
3. 代码审查：检查正确性、边界条件、兼容性、可维护性
4. 安全/边界检查：关注输入校验、权限、事务、数据一致性、环境风险
5. 测试验证：执行自动化测试、手工验证或抽样验证

### 7.1 不同团队的默认顺序

- `t1`：实现/修复 → 代码审查 → 回归测试
- `t2`：实现/修复 → 代码审查 → 回归测试 → 运维检查
- `t3`：交互方案 → 实现 → 代码审查 → 回归测试
- `t4`：架构检查 → 实现 → 代码审查 → 回归测试
- `t5`：架构检查 + 交互方案并行 → 实现 → 代码审查 → 回归测试
- `t6`：架构检查 + 交互方案 + 实现探索并行 → 代码审查 → 测试收口 → 运维检查

### 7.2 默认关注优先级

1. 正确性
2. 回归风险
3. 安全性
4. UI 一致性
5. 性能与运维风险

## 8. 并行执行最佳实践

### 8.1 正确并行

场景：新功能开发（跨前后端）
- 架构师：先确认接口契约、数据流和边界
- UI设计师：并行整理交互和视觉约束
- 程序员：在契约稳定后实现
- 测试：并行准备验证清单，待实现完成后执行

场景：Bug 修复
- 程序员：定位与修复根因
- 测试：并行准备复现步骤和回归清单
- 架构师：若涉及跨层问题，可并行判断是否需要结构调整

场景：后台页面优化
- UI设计师：先出交互与样式方向
- 程序员：按已确定方案实现
- 测试：验证关键流程与主题兼容性

场景：发布前审计
- 架构师：检查系统边界、配置和技术债
- 测试：执行回归清单
- 运维：检查部署、监控、回滚预案

### 8.2 反模式（假并行）

- 方案尚未稳定时，程序员和测试同时实现/验证同一条未定方案
- 代码尚未完成时，代码审查直接给出实现级结论
- 需要串行确认的数据结构、接口契约，却被拆成多个角色各自猜测

## 9. 风险控制与禁用项

### 9.1 禁止行为

- 禁止虚构项目中不存在的独立 agent 命令
- 禁止把“自动触发某代理”写成系统保证
- 禁止未经说明就跳过测试、代码审查或回滚评估
- 禁止在高风险改动中省略架构检查或运维检查

### 9.2 高风险操作默认团队

- 数据库结构修改：默认 `t4` 及以上
- 批量数据修复：默认 `t4` 及以上
- 鉴权/支付/订单主链路：默认 `t4` 或 `t6`
- 部署配置、网关、环境联动：默认 `t2` 或 `t6`

## 10. 团队职责到 skills 的映射

- 架构检查：
  - `thinkPHP/architecture-layering`
  - `thinkPHP/service-stateless-swoole`
  - `thinkPHP/mall-base-boundary`
- 事务与数据一致性：
  - `thinkPHP/validate-then-transact`
- 列表与分页规范：
  - `thinkPHP/list-query-sync`
  - `thinkPHP/list-return-compact`
- 后台路由与权限：
  - `thinkPHP/route-permission-system`
- 控制器/服务 IDE 规范：
  - `thinkPHP/ide-generic-annotation`
- 商品图片与媒体规则：
  - `thinkPHP/goods-image-main-sync`
  - `thinkPHP/goods-media-contract`
- 后端测试基线：
  - `thinkPHP/backend-test-baseline`
- 数据库 seed 与升级策略：
  - `thinkPHP/dev-no-upgrade-sql`
- 后台 UI 与表单规范：
  - `vbenAdmin/modal-form-layout`
  - `vbenAdmin/admin-theme-consistency`
  - `vbenAdmin/upload-component-first`
  - `vbenAdmin/iconpicker-standard`
- 后端驱动路由与 API 约束：
  - `vbenAdmin/backend-driven-routing`
  - `vbenAdmin/api-path-param`
  - `vbenAdmin/upload-field-normalize`
- 前端 E2E：
  - `vbenAdmin/e2e-webantd-realapi`

## 11. 团队默认测试流程（开发测试一体化）

所有 `t*` 团队任务，除非用户明确跳过测试，否则默认按以下流程执行并在结论中汇报：

1. 后端改动：
  - 先确认依赖：`composer install --working-dir backend`（仅首次或依赖变更时）
  - 执行：`composer --working-dir backend test`
2. 前端 `web-antd` 改动：
  - 先确认浏览器：`pnpm --dir frontend/admin run test:e2e:install`（首次）
  - 执行：`pnpm --dir frontend/admin run test:e2e`
3. 纯文档/纯配置且无行为变化：
  - 可降级为抽样测试，但必须在汇报中写清“未全量执行”的理由。
4. 测试角色输出要求：
  - 必须给出“已执行命令 + 结果 + 失败定位（如有）”。
  - 不允许只写“已测试通过”。
