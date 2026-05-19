# mpay 后端

`mpay` 是支付中台后端服务，基于 Webman，负责后台 API、商户 API、收银台、ePay 兼容协议、支付插件、路由、回调、退款、清算、资金、文件和系统配置。

## 快速开始

```bash
composer install
Copy-Item .env.example .env
php webman start
```

Windows 开发环境如需启动自定义进程：

```bash
php windows.php
```

## 常用命令

```bash
php webman start
php webman restart
php webman mpay:test --all
php webman epay:mapi
php webman system:config-sync
```

## 目录概览

```text
app/command/      命令和烟雾测试
app/common/       基类、常量、工具、中间件、支付插件
app/http/         admin、mer、api 控制器与校验
app/model/        模型
app/repository/   仓库
app/route/        显式路由
app/service/      业务服务
config/           Webman 与业务配置
public/           静态资源和前端构建产物
support/          Webman 支撑代码
```

## 入口

- 管理后台：`/admin`、`/adminapi`
- 商户后台：`/mer`、`/merapi`
- 收银台：`/cashier`、`/payment`、`/api/cashier`
- ePay V1：`/submit.php`、`/mapi.php`、`/api.php`
- ePay V2：`/api/pay`、`/api/merchant`、`/api/transfer`
- 通道级通知：`/api/pay/{chanId}/notify`，用于个人收款监听类插件先定位平台支付单，再进入标准支付回调流程。

## 支付插件

支付插件位于 `app/common/payment`。当前主要插件：

- `EpayV1Payment`：ePay V1 协议对接。
- `EpayV2Payment`：ePay V2 协议对接。
- `AlipayReceiptPayment`：支付宝个人收款监听，不对接官方 API。
- `WechatReceiptPayment`：微信个人收款监听，不对接官方 API。

个人收款监听插件通过配置表单声明订单匹配模式：

- 金额变动：下单时在支付单金额上做最小分级偏移，只用于通知定位订单；确认收款前会恢复原始订单金额，避免影响业务统计。
- 付款备注：下单时生成 4 位备注码并写入缓存，通知时按备注码定位订单。

监听工具当前按 SmsForwarder 签名规则校验 `timestamp`、`sign` 和通知内容。插件 `channelNotify()` 只返回 `pay_no`，后续验签、状态归一、回调日志和订单推进仍走标准 `notify()` 链路。

更多说明见 `../docs/backend/README.md`。
