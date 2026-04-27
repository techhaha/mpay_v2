# 项目总览

`MPAY_V2` 是一个支付中台工作区，后端基于 Webman，前端拆成管理后台、商户后台和收银台三套独立应用。

## 工作区组成

| 目录 | 类型 | 职责 |
| --- | --- | --- |
| `mpay` | PHP / Webman 后端 | 支付、退款、路由、插件、回调、商户、资金、清算、文件与系统配置 |
| `admin` | Vue 3 管理后台 | 商户、通道、插件、轮询、订单、退款、清算、资金、日志、文件、系统配置 |
| `mer` | Vue 3 商户后台 | 商户资料、API 凭证、可用通道、路由预览、订单、退款、清算、余额和流水 |
| `cashier` | Vue 3 收银台 | 收银台上下文、确认支付、支付跳转、支付单状态和结果页 |
| `docs` | 文档中心 | 当前项目事实、接口、部署和协作说明 |

根目录只是工作区容器；`admin`、`mer`、`cashier`、`mpay` 各自保留独立 Git 仓库。

## 核心链路

```text
商户系统/ePay 请求
  -> 后端校验商户与签名
  -> 创建业务单/支付单
  -> 商户分组路由解析
  -> 轮询组选择支付通道
  -> 支付插件调用第三方
  -> 收银台展示或跳转
  -> 回调/查单推进状态
  -> 通知商户
  -> 清算后写入商户资金与流水
```

## 当前入口

| 场景 | 页面入口 | API 入口 | 后端路由文件 |
| --- | --- | --- | --- |
| 管理后台 | `/admin` | `/adminapi` | `mpay/app/route/admin.php` |
| 商户后台 | `/mer` | `/merapi` | `mpay/app/route/mer.php` |
| 收银台 | `/cashier`、`/payment` | `/api/cashier`、`/api/pay` | `mpay/app/route/api.php` |
| ePay V1 兼容 | `/submit.php`、`/mapi.php`、`/api.php` | 同左 | `mpay/app/route/api.php` |
| ePay V2 / 开放 API | 无固定页面 | `/api/pay`、`/api/merchant`、`/api/transfer` | `mpay/app/route/api.php` |

## 后端重点模块

- `app/http`：管理后台、商户后台、开放 API 的控制器、中间件和参数校验。
- `app/route`：显式路由；默认路由已关闭。
- `app/service/payment`：支付、退款、清算、路由、插件、通知、追踪和 ePay 协议。
- `app/service/merchant`：商户主体、登录、分组、策略、商户后台能力和 API 凭证。
- `app/service/account`：商户资金账户和流水。
- `app/service/file`：文件资产、上传、预览、下载和存储驱动。
- `app/common/payment`：支付插件实现，当前包含 Alipay、ePay V1、ePay V2 和模板插件。

## 数据范围

当前 DDL 包含支付配置、商户、订单、退款、转账、资金、清算、日志、通知、文件、系统配置和管理员用户表。完整表结构以 [当前 DDL](./db/payment-middle-ddl.sql) 为准。

## 推荐阅读

1. [架构与请求流](./architecture.md)
2. [后端总说明](./backend/README.md)
3. [前端总说明](./frontend/README.md)
4. [接口总说明](./api/README.md)
5. [部署总说明](./deployment/README.md)
