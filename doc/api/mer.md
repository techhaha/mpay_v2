# 商户后台接口

`mer` 前端调用 `/merapi`，接口定义在 `mpay/app/route/mer.php`。

## 基本信息

- 页面入口：`/mer`
- API 前缀：`/merapi`
- 登录接口：`POST /login`
- 登录主体：`ma_merchant`
- 保护接口：`MerchantAuthMiddleware`
- 前端封装：`mer/src/api/modules/*`

## 模块速览

| 模块 | 主要路径 |
| --- | --- |
| 认证 | `/login`、`/logout`、`/user/profile` |
| 商户资料 | `/merchant/profile`、`/merchant/change-password` |
| 通道与路由 | `/my-channels`、`/my-channels/create-meta`、`/plugin-configs`、`/plugin-configs/options`、`/payment-plugins/{code}/schema`、`/route-preview` |
| API 凭证 | `/api-credential`、`/api-credential/issue-credential` |
| 订单 | `/pay-orders`、`/refund-orders`、`/refund-orders/{refundNo}`、`/refund-orders/{refundNo}/retry` |
| 清算 | `/settlement-records`、`/settlement-records/{settleNo}` |
| 资金 | `/withdrawable-balance`、`/balance-flows` |
| 系统 | `/system/menu-tree`、`/system/dict-items` |

## 关联代码

- 控制器：`mpay/app/http/mer/controller`
- 校验器：`mpay/app/http/mer/validation`
- 前端接口：`mer/src/api/modules`

## 商户自助通道

- 商户可新增、修改、删除 `merchant_id=当前商户` 的自有通道。
- 商户新增插件配置时只能选择管理后台标记为“允许商户端自助使用”的启用插件。
- 商户通道绑定的 `api_config_id` 必须属于当前商户，不能引用平台配置或其它商户配置。
