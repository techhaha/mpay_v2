# 数据表目录

这份目录页只负责“表去哪看、表大概干什么”，详细结构仍以 [`payment-middle-ddl.sql`](./payment-middle-ddl.sql) 为准。

## 基础字典

| 表名 | 用途 | 说明 |
| --- | --- | --- |
| `ma_payment_type` | 支付方式字典 | 维护支付方式编码、名称、图标和启用状态 |
| `ma_system_config` | 系统配置表 | 维护全局配置键值 |

## 商户主体

| 表名 | 用途 | 说明 |
| --- | --- | --- |
| `ma_merchant_group` | 商户分组表 | 作为路由绑定和商户归类的输入条件 |
| `ma_merchant` | 商户表 | 商户主体资料，也是后台登录主体 |
| `ma_merchant_api_credential` | 商户 API 凭证表 | 开放接口签名凭证，V1 使用 MD5 key，V2 保存 RSA 公钥，与后台登录分离 |
| `ma_merchant_policy` | 商户策略预留表 | 预留的商户策略结构 |

## 支付编排

| 表名 | 用途 | 说明 |
| --- | --- | --- |
| `ma_payment_plugin` | 支付插件注册表 | 扫描和注册支付插件定义 |
| `ma_payment_plugin_conf` | 支付插件 API 配置表 | 插件初始化配置和结算周期配置 |
| `ma_payment_channel` | 支付通道表 | 维护平台通道和商户自有通道 |
| `ma_payment_poll_group` | 支付轮询组表 | 承载轮询策略和候选通道编排 |
| `ma_payment_poll_group_channel` | 支付轮询组-通道编排表 | 轮询组内的通道顺序和权重配置 |
| `ma_payment_poll_group_bind` | 商户分组-轮询组绑定表 | 商户分组与轮询组的映射关系 |

## 交易订单

| 表名 | 用途 | 说明 |
| --- | --- | --- |
| `ma_biz_order` | 业务订单表 | 统一业务订单入口，只承载业务事实、收银台恢复所需信息（subject/body/notify_url/return_url/client_ip/device）和业务扩展参数 |
| `ma_pay_order` | 支付单表 | 记录支付发起、状态推进和回调信息，扩展字段只留商户附加参数 |
| `ma_refund_order` | 退款单表 | 记录退款发起、状态推进和结果，扩展字段只留商户附加参数 |
| `ma_transfer_order` | 转账单表 | 记录转账发起、状态推进和渠道结果，扩展字段只留商户附加参数 |
| `ma_settlement_order` | 清算单表 | 记录清算批次和清算状态，扩展字段只留清算附加信息 |
| `ma_settlement_item` | 清算明细表 | 记录清算单内的明细拆分 |

## 资金账户

| 表名 | 用途 | 说明 |
| --- | --- | --- |
| `ma_merchant_account` | 商户余额账户表 | 记录商户余额、冻结、可用等账户信息 |
| `ma_merchant_account_ledger` | 商户余额流水表 | 记录账户变更流水和账务明细 |

## 运维日志

| 表名 | 用途 | 说明 |
| --- | --- | --- |
| `ma_channel_notify_log` | 通道通知日志表 | 记录通道侧通知、重试和失败原因 |
| `ma_pay_callback_log` | 支付回调日志表 | 记录支付回调处理和幂等结果 |
| `ma_channel_daily_stat` | 通道日统计表 | 记录通道成功率、耗时和健康度数据 |
| `ma_notify_task` | 商户通知任务表 | 记录商户异步通知任务和重试情况 |

## 文件与后台

| 表名 | 用途 | 说明 |
| --- | --- | --- |
| `ma_file_asset` | 文件表 | 记录上传文件、预览、下载和存储位置 |
| `ma_admin_user` | 管理员用户表 | 记录后台管理员账号信息 |

## 使用建议

- 先看 `tables.md`，再看 DDL
- 如果字段定义变了，以 DDL 为准
- 如果表用途变了，先补 DDL 注释，再补这里的目录说明
- `ext_json` 使用分区结构保存轻量运行上下文：顶层可放 `_protocol_version`，商户透传放 `merchant`，支付载体放 `payment`，收银台承接放 `presentation`
- 回调、通知、重试和原始报文使用专门日志/任务表，不进入订单扩展字段
- 表级说明后面可以按业务域继续拆成独立文档
