

# mpay 后端

`mpay` 是基于 Webman 的支付中台后端服务，负责后台 API、商户 API、收银台、ePay 兼容协议、支付插件、路由、回调、退款、清算、资金、文件和系统配置。

## 项目简介

mpay 是一个完整的支付系统解决方案，提供以下核心功能：

- **多端接入**：支持管理后台、商户后台、收银台等多端 API
- **支付协议**：兼容 ePay V1 和 ePay V2 协议
- **渠道管理**：支持多种支付渠道配置和路由策略
- **资金管理**：商户账户、冻结资金、账本流水
- **清算结算**：自动化的结算流程
- **异步任务**：基于 Redis 的队列消费机制
- **个人收款监听**：支持支付宝、微信个人收款监听

## 快速开始

```bash
# 安装依赖
composer install

# 复制环境配置
cp .env.example .env

# 启动服务
php webman start
```

Windows 开发环境如需启动自定义进程：

```bash
php windows.php
```

## 常用命令

```bash
# 启动与重启
php webman start
php webman restart

# 运行测试
php webman mpay:test --all

# ePay 接口
php webman epay:mapi

# 系统配置同步
php webman system:config-sync
```

## 目录结构

```
app/command/      命令行和烟雾测试
app/common/      基类、常量、工具、中间件、支付插件
app/http/        admin、mer、api 控制器与校验
app/model/       模型
app/repository/ 仓库
app/route/      显式路由
app/service/    业务服务
config/         Webman 与业务配置
public/         静态资源和前端构建产物
support/        Webman 支撑代码
```

## 入口

| 模块 | 路径 |
|------|------|
| 管理后台 | `/admin`、`/adminapi` |
| 商户后台 | `/mer`、`/merapi` |
| 收银台 | `/cashier`、`/payment`、`/api/cashier` |
| ePay V1 | `/submit.php`、`/mapi.php`、`/api.php` |
| ePay V2 | `/api/pay`、`/api/merchant`、`/api/transfer` |
| 渠道通知 | `/api/pay/{chanId}/notify` |

## 支付插件

当前主要支付插件位于 `app/common/payment`：

- **EpayV1Payment**：ePay V1 协议对接
- **EpayV2Payment**：ePay V2 协议对接（含转账功能）
- **AlipayReceiptPayment**：支付宝个人收款监听
- **WechatReceiptPayment**：微信个人收款监听

### 个人收款监听

个人收款监听插件通过配置表单声明订单匹配模式：

- **金额变动**：下单时在支付单金额上做最小分级偏移，仅用于通知定位订单；确认收款前会恢复原始订单金额
- **付款备注**：下单时生成 4 位备注码并写入缓存，通知时按备注码定位订单

监听工具按 SmsForwarder 签名规则校验 `timestamp`、`sign` 和通知内容。插件 `channelNotify()` 只返回 `pay_no`，后续验签、状态归一、回调日志和订单推进仍走标准 `notify()` 链路。

## 配置说明

### 环境变量

复制 `.env.example` 为 `.env` 并配置以下主要参数：

- 数据库连接
- Redis 连接
- 应用基础配置
- 支付渠道配置

### 系统配置

系统配置通过 `config/` 目录和数据库进行管理，可使用命令行同步：

```bash
php webman system:config-sync
```

## 技术栈

- **框架**：Webman
- **数据库**：MySQL
- **缓存**：Redis
- **SDK**：支付宝、微信支付官方 SDK

## 许可证

请查看项目根目录的 LICENSE 文件。