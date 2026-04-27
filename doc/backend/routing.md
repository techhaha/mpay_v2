# 后端路由

后端只使用显式路由。`config/route.php` 加载三份路由文件后关闭默认路由。

## 路由文件

| 文件 | 覆盖范围 |
| --- | --- |
| `app/route/admin.php` | `/admin` 页面入口、`/adminapi` 管理后台 API |
| `app/route/mer.php` | `/mer` 页面入口、`/merapi` 商户后台 API |
| `app/route/api.php` | 收银台页面、收银台 API、ePay V1/V2 与开放 API |

## 当前入口

| 前缀 | 说明 | 中间件 |
| --- | --- | --- |
| `/admin` | 管理后台静态页面入口 | 无业务鉴权 |
| `/adminapi` | 管理后台接口 | `Cors`，保护接口再走 `AdminAuthMiddleware` |
| `/mer` | 商户后台静态页面入口 | 无业务鉴权 |
| `/merapi` | 商户后台接口 | `Cors`，保护接口再走 `MerchantAuthMiddleware` |
| `/cashier` | 收银台入口页 | 无业务鉴权 |
| `/payment` | 支付页、中转页、结果页 | 无业务鉴权 |
| `/api/cashier` | 收银台上下文、确认支付、支付单详情 | `Cors` |
| `/api/pay` | ePay V2 支付、查询、退款、关闭、通道回调 | `Cors` |
| `/api/merchant` | ePay V2 商户信息与订单查询 | `Cors` |
| `/api/transfer` | ePay V2 转账提交、查询、余额 | `Cors` |
| `/submit.php`、`/mapi.php`、`/api.php` | ePay V1 兼容入口 | `Cors` |

## 流转

```mermaid
flowchart LR
  Req[HTTP 请求] --> Route[显式路由]
  Route --> Middleware[中间件]
  Middleware --> Controller[控制器]
  Controller --> Validator[参数校验]
  Validator --> Service[服务层]
  Service --> Repository[仓库层]
  Repository --> Model[模型层]
  Model --> DB[(MySQL)]
```

## 维护要求

- 新增接口先改 `app/route/*`，再补对应 `docs/api/*`。
- 页面兜底路由只返回前端入口，不承载业务逻辑。
- 业务规则放服务层；路由文件只做 URL 到控制器方法的绑定。
