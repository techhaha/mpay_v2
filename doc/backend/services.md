# 后端服务层

服务层承载业务规则，控制器只负责入口、参数和响应包装。

## 目录职责

| 目录 | 主要职责 |
| --- | --- |
| `account/funds`、`account/ledger` | 商户账户、余额和资金流水 |
| `bootstrap` | 启动期初始化 |
| `file`、`file/storage` | 文件资产、上传、导入、预览、下载和存储驱动 |
| `merchant` | 商户主体、总览、分组、策略、API 凭证 |
| `merchant/auth` | 商户后台登录认证 |
| `merchant/portal` | 商户后台资料、通道、路由预览、订单资金查询 |
| `ops/log`、`ops/stat` | 通道通知、支付回调、商户通知任务和通道日统计 |
| `payment/cashier` | 收银台上下文、确认支付、支付单展示 |
| `payment/config` | 支付方式、插件、插件配置、通道、轮询组、绑定 |
| `payment/epay` | ePay V1/V2 协议、MD5/RSA 签名 |
| `payment/order` | 支付单、退款单、费用、派单、回调、生命周期 |
| `payment/runtime` | 路由解析、插件装配、商户通知、运行时维护 |
| `payment/settlement` | 清算单和清算生命周期 |
| `payment/trace` | 交易追踪与报表 |
| `payment/transfer` | 转账能力 |
| `system/access`、`system/config`、`system/user` | 管理员认证、系统配置、管理员用户 |

## 命名规则

- `*Service`：对外门面。
- `*QueryService`：查询与展示拼装。
- `*CommandService`：写入、修改、删除。
- `*LifecycleService`：状态流转。
- `*CallbackService`：第三方回调。
- `*SyncService`：同步、扫描、刷新。
- `*SupportService`：业务辅助能力。

## 关键服务

- 支付：`PayOrderService`、`PayOrderLifecycleService`、`PayOrderChannelDispatchService`
- 退款：`RefundService`、`RefundCreationService`、`RefundLifecycleService`
- 清算：`SettlementService`、`SettlementLifecycleService`
- 路由与插件：`PaymentRouteService`、`PaymentRouteResolverService`、`PaymentPluginManager`
- 收银台：`CashierService`
- 通知：`NotifyService`、`MerchantNotifyDispatcherService`
- 商户：`MerchantService`、`MerchantPortalService`、`MerchantApiCredentialService`
- 文件：`FileRecordService`、`StorageManager`

## 维护要求

- 查询和写入不要混在一个越来越胖的类里。
- 回调、路由解析、插件装配、通知重试要保持独立职责。
- `SupportService` 只放有业务含义的复用逻辑，不做基础工具的空转发。
