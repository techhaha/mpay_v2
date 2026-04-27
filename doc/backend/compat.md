# ePay 兼容层

后端同时保留 ePay V1 旧入口和 ePay V2 新接口。兼容层只负责协议适配，不作为后台管理或商户后台的新能力入口。

## 当前实现

| 协议 | 控制器 | 服务 |
| --- | --- | --- |
| ePay V1 | `app/http/api/controller/epay/EpayV1Controller.php` | `app/service/payment/epay/EpayV1ProtocolService.php` |
| ePay V2 | `app/http/api/controller/epay/EpayV2Controller.php` | `app/service/payment/epay/EpayV2ProtocolService.php` |

签名实现：

- `Md5Signer`
- `RsaSigner`
- `EpaySignerManager`

## V1 路由

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| `ANY` | `/submit.php` | 页面跳转支付 |
| `POST` | `/mapi.php` | 接口支付 |
| `ANY` | `/api.php` | 旧版标准 API |

## V2 路由

| 分组 | 路径 |
| --- | --- |
| 支付 | `/api/pay/submit`、`/api/pay/create`、`/api/pay/query`、`/api/pay/refund`、`/api/pay/refundquery`、`/api/pay/close`、`/api/pay/{payNo}/callback` |
| 商户 | `/api/merchant/info`、`/api/merchant/orders` |
| 转账 | `/api/transfer/submit`、`/api/transfer/query`、`/api/transfer/balance` |

## 关联文档

- [接口总说明](../api/README.md)
- [收银台与开放接口](../api/cashier.md)
- [ePay 兼容协议](../api/legacy/epay.md)
- [后端路由](./routing.md)
