# 协作接手指南

这份文档给新协作者快速接手使用。稳定事实先看 [稳定口径](./standards.md)。

## 先看什么

1. [工作区 README](../README.md)
2. [项目总览](./overview.md)
3. [架构与请求流](./architecture.md)
4. [后端总说明](./backend/README.md)
5. [前端总说明](./frontend/README.md)
6. [接口总说明](./api/README.md)
7. [数据库总说明](./db/README.md)

## 常用命令

后端：

```bash
cd mpay
composer install
php webman start
php webman mpay:test --all
php webman system:config-sync
```

前端：

```bash
cd admin
pnpm dev
pnpm build:prod

cd ../mer
pnpm dev
pnpm build:prod

cd ../cashier
pnpm dev
pnpm build
```

## 不要搞混的边界

- `admin`：页面 `/admin`，接口 `/adminapi`。
- `mer`：页面 `/mer`，接口 `/merapi`。
- `cashier`：页面 `/cashier`、`/payment`，接口 `/api/cashier`。
- ePay V1：`/submit.php`、`/mapi.php`、`/api.php`。
- ePay V2：`/api/pay`、`/api/merchant`、`/api/transfer`。
- `mpay/doc/` 是旧资料归档，最新文档在 `docs/`。

## 优先查看的代码

- `mpay/app/route/admin.php`
- `mpay/app/route/mer.php`
- `mpay/app/route/api.php`
- `mpay/app/service/payment/order/PayOrderService.php`
- `mpay/app/service/payment/order/RefundService.php`
- `mpay/app/service/payment/runtime/PaymentRouteService.php`
- `mpay/app/service/payment/runtime/PaymentPluginManager.php`
- `mpay/app/service/payment/cashier/CashierService.php`
- `mpay/app/service/merchant/portal/MerchantPortalService.php`
- `admin/src/api/modules`
- `mer/src/api/modules`
- `cashier/src/api/cashier.ts`

## 协作原则

- 文档和代码冲突时，先以代码为准，再修正文档。
- 接口入口以 `mpay/app/route` 为准。
- 前端 API 前缀以各项目 `src/api/index.ts` 为准。
- 不在总文档重复接口字段，字段细节看控制器、校验器、协议文档和 DDL。
