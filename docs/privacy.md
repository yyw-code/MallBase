# 隐私与平台实例统计说明

MallBase 可接入平台实例统计，用于了解开源版本的安装、活跃、版本分布和运行环境概况。

## 统计范围

允许上报的数据仅限于：

- MallBase 版本
- PHP 版本
- 数据库类型和版本
- 操作系统类型和架构
- 时区
- 安装来源
- 端类型和端版本
- 平台分配的安装实例标识
- 低频心跳时间

不允许上报的数据包括：

- 订单 ID、订单金额、支付信息
- 商品 ID、商品内容
- 会员 ID、手机号、邮箱
- 收货人、收货地址
- 后台登录人员信息
- 商家业务操作明细
- 导出记录或导出内容

## 本地状态

平台实例状态保存在运行时安装锁文件中：

```text
backend/runtime/install/install.lock
```

安装锁文件不进入版本库。平台相关状态位于 JSON 的 `platform` 节点，例如：

```json
{
  "installed_at": "2026-06-19 12:00:00",
  "platform": {
    "instance_id": "d3ec761b-c5d1-4663-8c76-7d2d351efad5",
    "token": "mbt_xxx",
    "last_report_at": 0,
    "next_report_after": 0,
    "components": {
      "admin_web": 1781856000,
      "uniapp": 1781856000
    },
    "disabled": false
  }
}
```

字段说明：

| 字段 | 说明 |
|------|------|
| `instance_id` | 平台分配的安装实例标识 |
| `token` | 平台实例心跳凭证，仅用于平台心跳鉴权 |
| `last_report_at` | 最近一次心跳上报时间戳 |
| `next_report_after` | 下次允许心跳上报的最早时间戳 |
| `components` | 最近活跃端类型及本地记录时间戳 |
| `disabled` | 是否关闭平台实例统计 |

## 关闭方式

将 `backend/runtime/install/install.lock` 中的 `platform.disabled` 设置为 `true` 后，后端应停止平台实例统计上报。

```json
{
  "installed_at": "2026-06-19 12:00:00",
  "platform": {
    "disabled": true
  }
}
```

如果删除整个 `platform` 节点，后续版本可能会在满足上报条件时重新激活实例。需要关闭时应保留 `platform.disabled = true`。
