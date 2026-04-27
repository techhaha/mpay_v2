# 数据库总说明

这里放数据库相关的稳定说明和 DDL 入口。

## 当前 DDL

当前数据库事实源已经迁移到 [`payment-middle-ddl.sql`](./payment-middle-ddl.sql)。

它覆盖的核心表分组包括：

- 商户与登录主体
- 支付方式、支付插件、支付通道
- 轮询组、轮询组通道、轮询组绑定
- 商户策略与商户 API 凭证
- 支付单、退款单、转账单、清算单
- 商户资金账户与流水
- 通知日志、回调日志、日统计
- 文件资产、系统配置、后台用户

## 关键表分组

| 分组 | 代表表 |
| --- | --- |
| 基础字典 | `ma_payment_type`、`ma_system_config` |
| 商户主体 | `ma_merchant`、`ma_merchant_group`、`ma_merchant_api_credential`、`ma_merchant_policy` |
| 支付编排 | `ma_payment_plugin`、`ma_payment_plugin_conf`、`ma_payment_channel`、`ma_payment_poll_group`、`ma_payment_poll_group_channel`、`ma_payment_poll_group_bind` |
| 交易订单 | `ma_biz_order`、`ma_pay_order`、`ma_refund_order`、`ma_transfer_order`、`ma_settlement_order`、`ma_settlement_item` |
| 资金账户 | `ma_merchant_account`、`ma_merchant_account_ledger` |
| 运维日志 | `ma_channel_notify_log`、`ma_pay_callback_log`、`ma_channel_daily_stat`、`ma_notify_task` |
| 文件与后台 | `ma_file_asset`、`ma_admin_user` |

## 说明原则

- DDL 是事实源
- 表结构变化要先更新 DDL，再补说明
- 文档里不要重复贴大段 SQL，尽量只解释结构和用途
- `ma_merchant` 是商户主体，也是后台登录主体
- `ma_merchant_api_credential` 只用于开放接口签名和兼容层，不参与后台登录
- `ma_merchant_api_credential` 同时承载 V1 的 MD5 凭证值和 V2 的 RSA 公钥
- `ma_transfer_order` 负责承接 V2 转账单据
- 路由链路优先遵循“商户分组 -> 轮询组 -> 支付通道”
- `ext_json` 使用分区结构保存轻量运行上下文；`_protocol_version` 这类强语义字段可放顶层，商户透传放 `merchant`，支付载体放 `payment`，收银台承接放 `presentation`
- 通知、回调、重试、原始报文进入 `ma_pay_callback_log`、`ma_notify_task`、`ma_channel_notify_log`，不要塞进订单扩展槽
- `ma_pay_order` 不再保留 `request_method` 这类 HTTP 快照字段

## 建议写法

以后每个表说明都按下面的结构写：

1. 表用途
2. 主键与索引
3. 核心字段
4. 状态值含义
5. 与哪些业务链路相关

## 继续阅读

- [数据表目录](./tables.md)
- [项目稳定口径](../standards.md)
- [项目总览](../overview.md)
- [架构与请求流](../architecture.md)
