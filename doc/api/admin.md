# 管理后台接口

`admin` 前端调用 `/adminapi`，接口定义在 `mpay/app/route/admin.php`。

## 基本信息

- 页面入口：`/admin`
- API 前缀：`/adminapi`
- 登录接口：`POST /login`
- 保护接口：`AdminAuthMiddleware`
- 前端封装：`admin/src/api/modules/*`

## 模块速览

| 模块 | 主要路径 |
| --- | --- |
| 认证 | `/login`、`/logout`、`/user/profile` |
| 管理员 | `/admin-users` |
| 商户 | `/merchants`、`/merchants/{id}/overview`、`/merchants/{id}/reset-password`、`/merchants/{id}/issue-credential` |
| 商户 API 凭证 | `/merchant-api-credentials` |
| 商户分组与策略 | `/merchant-groups`、`/merchant-policies` |
| 支付方式 | `/payment-types` |
| 支付插件 | `/payment-plugins`、`/payment-plugins/refresh`、`/payment-plugins/{code}/schema` |
| 插件配置 | `/payment-plugin-confs` |
| 支付通道 | `/payment-channels`、`/payment-channels/route-options` |
| 轮询组 | `/payment-poll-groups`、`/payment-poll-group-channels`、`/payment-poll-group-binds` |
| 路由预览 | `/routes/resolve` |
| 文件资产 | `/file-asset`、`/file-asset/upload`、`/file-asset/import-remote`、`/file-asset/{id}/preview`、`/file-asset/{id}/download` |
| 交易 | `/pay-orders`、`/refund-orders`、`/refund-orders/{refundNo}/retry`、`/settlement-orders` |
| 资金 | `/merchant-accounts`、`/merchant-accounts/summary`、`/account-ledgers` |
| 运维 | `/channel-daily-stats`、`/channel-notify-logs`、`/pay-callback-logs`、`/merchant-notify-tasks`、`/merchant-notify-tasks/{notifyNo}/retry` |
| 系统 | `/system/menu-tree`、`/system/dict-items`、`/system-config-pages` |

## 关联代码

- 控制器：`mpay/app/http/admin/controller`
- 校验器：`mpay/app/http/admin/validation`
- 前端接口：`admin/src/api/modules`
