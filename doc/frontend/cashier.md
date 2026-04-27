# 收银台前端

`cashier` 是用户侧支付前端，负责收银台上下文展示、确认支付、跳转和支付结果页。

## 基本信息

- 目录：`cashier/`
- 技术栈：Vue 3、Vite、TypeScript、Tailwind CSS、axios、qrcode
- 页面入口：`/cashier`、`/payment`
- JSON API：默认 `/api/cashier/*`
- 开放支付 API：后端同时提供 `/api/pay/*`
- 本地代理：`/api` 默认代理到 `http://127.0.0.1:8787`

## 页面路由

| 路由 | 页面 |
| --- | --- |
| `/cashier` | 首页 |
| `/cashier/:bizNo` | 收银台入口页 |
| `/payment/:payNo` | 支付页 |
| `/payment/:payNo/redirect` | 支付中转页 |
| `/payment/:payNo/result` | 结果页 |
| `/payment/:payNo/success` | 成功结果页 |
| `/payment/:payNo/return` | 支付返回页 |
| `/payment/:payNo/error` | 支付错误页 |
| `/payment/:payNo/back` | 返回中转页 |

页面路由前缀在 `src/config/index.ts` 中固定为 `CASHIER_PATH_PREFIX=/cashier`、`PAYMENT_PATH_PREFIX=/payment`。当前 `.env` 中的 `VITE_ROUTE_PREFIX` 只是遗留示例变量，代码没有读取它。

## 接口调用

`src/api/cashier.ts` 当前封装三类接口：

- `GET /api/cashier/context?biz_no=...`
- `POST /api/cashier/confirm`
- `GET /api/cashier/pay-order?pay_no=...`

`VITE_API_PREFIX` 会影响请求路径前缀，默认是 `api`；`VITE_API_BASE_URL` 用于直连后端，不填时本地通过 Vite 代理访问 `/api`。

## 常用命令

```bash
pnpm install
pnpm dev
pnpm build
pnpm preview
```
