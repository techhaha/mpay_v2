# 接口总说明

接口按消费方和协议分组，真实路由以 `mpay/app/route/*.php` 为准。

## 当前路由面

| 路由 | 用途 | 文档 |
| --- | --- | --- |
| `/adminapi` | 管理后台接口 | [admin.md](./admin.md) |
| `/merapi` | 商户后台接口 | [mer.md](./mer.md) |
| `/api/cashier` | 收银台前端 JSON 接口 | [cashier.md](./cashier.md) |
| `/api/pay`、`/api/merchant`、`/api/transfer` | ePay V2 / 开放 API | [cashier.md](./cashier.md)、[legacy/epay.md](./legacy/epay.md) |
| `/submit.php`、`/mapi.php`、`/api.php` | ePay V1 兼容入口 | [legacy/epay.md](./legacy/epay.md) |

## 通用约束

- HTTP 成功态使用 `200`。
- 业务成功由响应体 `code=200` 表示。
- 后台类接口通过登录 token 鉴权。
- 开放支付接口通过商户 API 凭证和 ePay 签名规则鉴权。
- 接口字段不要在总文档重复铺开，按具体协议文档或控制器/校验器查看。
