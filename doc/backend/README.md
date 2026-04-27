# 后端总说明

`mpay` 是支付中台后端服务，基于 Webman。命令默认在 `mpay/` 目录执行。

## 技术栈

- PHP `>=8.1`
- `workerman/webman-framework ^2.1`
- MySQL、Redis
- JWT、Webman validation/cache/event/redis/database
- OSS/COS SDK 用于对象存储

## 快速启动

```bash
composer install
Copy-Item .env.example .env
php webman start
```

Windows 开发环境如需启动自定义进程，可使用：

```bash
php windows.php
```

## 主要目录

```text
app/
  command/      命令与烟雾测试
  common/       基类、常量、工具、中间件、支付插件
  http/         admin、mer、api 三类 HTTP 入口
  model/        数据模型
  repository/   数据访问
  route/        显式路由
  service/      业务服务
config/         Webman 与业务配置
public/         静态资源与前端构建产物
support/        Webman 支撑代码
```

## 关键入口

- 路由：`config/route.php`、`app/route/admin.php`、`app/route/mer.php`、`app/route/api.php`
- 支付：`app/service/payment/order/PayOrderService.php`
- 退款：`app/service/payment/order/RefundService.php`
- 清算：`app/service/payment/settlement/SettlementService.php`
- 路由：`app/service/payment/runtime/PaymentRouteService.php`
- 插件：`app/service/payment/runtime/PaymentPluginManager.php`
- 商户：`app/service/merchant/MerchantService.php`
- 商户后台：`app/service/merchant/portal/MerchantPortalService.php`
- 文件：`app/service/file/FileRecordService.php`

## 常用命令

```bash
php webman start
php webman restart
php webman mpay:test --all
php webman epay:mapi
php webman system:config-sync
```

## 关联文档

- [后端路由](./routing.md)
- [后端服务层](./services.md)
- [后端命令](./commands.md)
- [文件资产](./files.md)
- [接口总说明](../api/README.md)
- [部署说明](../deployment/backend.md)
