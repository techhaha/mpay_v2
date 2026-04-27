# 收银台与开放接口

本文覆盖 `cashier` 前端使用的收银台接口，以及后端在 `/api` 下暴露的 ePay V2 / 开放接口。

## 页面入口

| 页面前缀 | 说明 |
| --- | --- |
| `/cashier` | 收银台首页和业务单入口 |
| `/payment` | 支付页、中转页、结果页 |

后端在 `mpay/app/route/api.php` 中读取 `public/cashier/index.html` 返回前端入口。

## 收银台 JSON API

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| `GET` | `/api/cashier/context` | 根据 `biz_no` 获取收银台上下文 |
| `POST` | `/api/cashier/confirm` | 确认支付，参数包含 `biz_no` 和支付类型 |
| `GET` | `/api/cashier/pay-order` | 根据 `pay_no` 获取支付单详情 |

对应前端封装：`cashier/src/api/cashier.ts`。对应后端控制器：`CashierController`。

## ePay V2 / 开放 API

| 分组 | 方法与路径 |
| --- | --- |
| 支付 | `ANY /api/pay/submit`、`POST /api/pay/create`、`POST /api/pay/query`、`POST /api/pay/refund`、`POST /api/pay/refundquery`、`POST /api/pay/close`、`ANY /api/pay/{payNo}/callback` |
| 商户 | `POST /api/merchant/info`、`POST /api/merchant/orders` |
| 转账 | `POST /api/transfer/submit`、`POST /api/transfer/query`、`POST /api/transfer/balance` |

对应控制器：`EpayV2Controller`。签名与参数以 [ePay 兼容协议](./legacy/epay.md) 和校验器为准。

## ePay V1 兼容入口

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| `ANY` | `/submit.php` | 页面跳转支付 |
| `POST` | `/mapi.php` | 接口支付 |
| `ANY` | `/api.php` | 标准 API |

对应控制器：`EpayV1Controller`。
