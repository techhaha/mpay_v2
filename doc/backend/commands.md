# 后端命令

命令默认在 `mpay/` 目录执行。

## Webman 启动

| 命令 | 作用 |
| --- | --- |
| `php webman start` | 启动后端 HTTP 服务 |
| `php webman restart` | 重启后端服务 |
| `php windows.php` | Windows 开发环境启动 Webman 与自定义进程 |
| `php start.php start` | 生产/Linux 常见启动入口 |

`payment-runtime` 是自定义进程，不是 Console 命令；它随 Webman 进程启动，负责通知重试、支付超时扫描和支付中订单主动查单。

## 业务命令

| 命令 | 作用 |
| --- | --- |
| `php webman mpay:test --all` | 支付、退款、清结算、余额、追踪链路烟雾测试 |
| `php webman epay:mapi` | ePay V1 `mapi.php` 兼容接口烟雾测试 |
| `php webman epay:v2-api` | ePay V2 创建、查询、关闭、退款、商户信息等核心 API 烟雾测试 |
| `php webman epay:mock-chain` | 自动写入 mock 配置并跑 ePay V1/V2 全链路 |
| `php webman epay:v2-bootstrap` | 生成开发联调用平台和商户 RSA 密钥 |
| `php webman payment:notify-retry` | 手动重试到期商户通知任务 |
| `php webman system:config-sync` | 将 `config/system_config.php` 默认配置同步到数据库 |
| `php webman test` | 基础命令注册检查 |

## 常用参数

- `mpay:test`：`--payment`、`--refund`、`--settlement`、`--balance`、`--trace`、`--all`、`--live`
- `epay:mapi`：`--live`、`--merchant-id`、`--merchant-no`、`--type`、`--money`、`--refund-trade-no`
- `epay:v2-api`：`--live`、`--merchant-id`、`--merchant-no`、`--merchant-private-key-file`、`--type`、`--method`、`--money`
- `epay:mock-chain`：`--only=v1|v2|all`
- `payment:notify-retry`：`--limit`

## 关联文档

- [后端总说明](./README.md)
- [支付运行时数据契约](./payment-runtime-contract.md)
- [ePay 兼容协议](../api/legacy/epay.md)
