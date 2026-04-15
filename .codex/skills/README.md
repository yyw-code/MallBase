# MallBase 项目本地 Skills 说明

本目录是 **项目私有技能库**，仅服务当前仓库，不依赖全局环境。

## 目录约定

- 一级按技术栈分组：`thinkPHP` / `vbenAdmin` / `uniapp`
- 二级按规则命名：`<rule-name>/SKILL.md`
- 每个 `SKILL.md` 只描述一条核心规则，避免耦合

## 触发建议

- 修改后端分层、事务、路由：优先看 `thinkPHP/*`
- 修改后台前端页面与 API：优先看 `vbenAdmin/*`
- 修改移动端（后续接入）：优先看 `uniapp/*`
- 执行后端回归：优先看 `thinkPHP/backend-test-baseline`
- 执行后台真实链路 E2E：优先看 `vbenAdmin/e2e-webantd-realapi`

## 设计原则

1. 一条规则一个 skill，方便按需启用
2. 规则必须可落地并有自检清单
3. 与 `CLAUDE.md`、`.claude/skills/learned` 保持一致
