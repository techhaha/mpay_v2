# MPay V2 Project Overview

更新日期：2026-03-13

## 1. 项目定位

这是一个基于 Webman 的多商户支付中台项目，当前主要目标是：

- 提供后台管理能力，维护商户、应用、支付方式、支付插件、支付通道、订单与系统配置
- 为商户应用提供统一支付能力
- 当前已优先兼容 `epay` 协议，后续可继续扩展更多外部支付协议
- 通过“支付插件 + 通道配置”的方式对接第三方渠道

结合当前代码与数据库，项目已经具备“多商户 -> 多应用 -> 多通道 -> 多插件”的基础骨架。

## 2. 技术栈与运行环境

### 后端技术栈

- PHP `>= 8.1`
- Webman `^2.1`
- webman/database
- webman/redis
- webman/cache
- webman/console
- webman/captcha
- webman/event
- webman/redis-queue
- firebase/php-jwt
- yansongda/pay `~3.7.0`

### 当前环境配置要点

- HTTP 服务监听：`0.0.0.0:8787`
- 数据库：MySQL
- 缓存与队列：Redis
- 管理后台认证：JWT
- 当前 `.env` 已配置远程 MySQL / Redis 地址，开发前需要确认本机网络可达

## 3. 当前环境可调用的 MCP 能力

本次会话中，已确认可以直接用于本项目的 MCP / 环境能力如下：

### MySQL MCP

- 可直接执行 SQL
- 可读取当前开发库表结构与数据
- 已确认能访问 `mpay_admin` 相关表，例如：
  - `ma_merchant`
  - `ma_merchant_app`
  - `ma_pay_channel`
  - `ma_pay_order`
  - `ma_notify_task`
  - `ma_callback_inbox`
  - `ma_pay_callback_log`

适合后续继续做：

- 表结构核对
- 初始化数据检查
- 回调与订单状态排查
- 开发联调时快速确认通道配置

### Playwright MCP

- 可进行浏览器打开、点击、表单填写、快照、截图、网络请求分析
- 适合后续验证：
  - 管理后台登录流程
  - 通道配置页面交互
  - 提交支付后的跳转页/表单页
  - 回调相关前端可视流程

### MCP 资源浏览

- 可列出 MCP 资源
- 可读取资源内容
- 当前未返回资源模板

### 非 MCP 但对开发有用的本地能力

- Shell 命令执行
- 工作区文件读写
- 代码补丁编辑

## 4. 业务模型总览

### 4.1 商户模型

- 表：`ma_merchant`
- 作用：定义商户主体
- 关键字段：
  - `merchant_no`
  - `merchant_name`
  - `funds_mode`
  - `status`

### 4.2 商户应用模型

- 表：`ma_merchant_app`
- 作用：商户可创建多个应用，每个应用具备独立 `app_id` / `app_secret`
- 关键字段：
  - `merchant_id`
  - `api_type`
  - `app_id`
  - `app_secret`
  - `app_name`
  - `status`

当前代码中，`app_id` 既是应用标识，也是外部协议鉴权入口；`epay` 兼容链路直接用它作为 `pid`。

### 4.3 支付方式模型

- 表：`ma_pay_method`
- 作用：维护支付方式字典
- 当前库内数据：
  - `alipay`
  - `wechat`
  - `unionpay`

### 4.4 支付插件模型

- 表：`ma_pay_plugin`
- 作用：把“支付通道配置”与“PHP 插件实现类”解耦
- 插件需要同时实现：
  - `PaymentInterface`
  - `PayPluginInterface`

当前代码里已有两个插件类：

- `app/common/payment/LakalaPayment.php`
- `app/common/payment/AlipayPayment.php`

但当前数据库只注册了 `lakala`，还没有把 `alipay` 作为活动插件注册进现网开发库。

### 4.5 支付通道模型

- 表：`ma_pay_channel`
- 作用：把“商户应用 + 支付方式 + 插件 + 参数配置”绑定起来
- 关键字段：
  - `merchant_id`
  - `merchant_app_id`
  - `plugin_code`
  - `method_id`
  - `config_json`
  - `split_ratio`
  - `chan_cost`
  - `chan_mode`
  - `daily_limit`
  - `daily_cnt`
  - `min_amount`
  - `max_amount`
  - `status`
  - `sort`

这正对应你描述的核心业务特点：一个应用下可配置多个支付通道，每个通道可挂接不同插件与参数。

### 4.6 支付订单模型

- 表：`ma_pay_order`
- 作用：统一存放系统支付订单
- 关键特性：
  - 系统订单号：`order_id`
  - 商户订单号：`mch_order_no`
  - 幂等唯一键：`(merchant_id, merchant_app_id, mch_order_no)`
  - `extra` JSON 用于存放 `notify_url`、`return_url`、`pay_params`、退款信息等

### 4.7 回调与通知模型

- `ma_callback_inbox`：回调幂等收件箱
- `ma_pay_callback_log`：回调日志
- `ma_notify_task`：商户异步通知任务

这三张表说明项目已经为“渠道回调幂等 + 日志留痕 + 商户通知补偿”预留了比较完整的基础设施。

## 5. 代码分层与关键入口

### 外部接口入口

- `app/http/api/controller/EpayController.php`
- `app/http/api/controller/PayController.php`

### 支付主流程服务

- `app/services/api/EpayProtocolService.php`
- `app/services/api/EpayService.php`
- `app/services/PayService.php`
- `app/services/PayOrderService.php`
- `app/services/ChannelRouterService.php`
- `app/services/PluginService.php`
- `app/services/PayNotifyService.php`
- `app/services/NotifyService.php`
- `app/services/PaymentStateService.php`

### 支付插件契约

- `app/common/contracts/PaymentInterface.php`
- `app/common/contracts/PayPluginInterface.php`
- `app/common/base/BasePayment.php`

### 管理后台接口

- 商户：`MerchantController`
- 商户应用：`MerchantAppController`
- 支付方式：`PayMethodController`
- 插件注册：`PayPluginController`
- 通道：`ChannelController`
- 订单：`OrderController`
- 系统配置：`SystemController`
- 登录认证：`AuthController`

## 6. 当前已落地的对外接口

### 路由现状

当前 `app/routes/api.php` 实际挂载的对外接口为：

- `GET|POST /submit.php`
- `POST /mapi.php`
- `GET /api.php`
- `ANY /notify/{pluginCode}`

### 兼容协议现状

当前真正已打通的是 `epay` 风格接口：

- `submit.php`：页面跳转支付
- `mapi.php`：API 下单
- `api.php?act=order`：查单
- `api.php?act=refund`：退款

### OpenAPI 现状

`PayController` 中存在以下方法：

- `create`
- `query`
- `close`
- `refund`

但当前都还是 `501 not implemented`，并且对应路由尚未挂载，因此“通用 OpenAPI”目前仍是预留骨架，不是已上线能力。

## 7. 核心支付链路

### 7.1 Epay 下单链路

1. 商户调用 `submit.php` 或 `mapi.php`
2. `EpayProtocolService` 负责参数提取与校验
3. `EpayService` 使用 `app_secret` 做 MD5 验签
4. 构造统一内部订单数据
5. `PayOrderService` 创建订单，并通过联合唯一键保证幂等
6. `ChannelRouterService` 根据 `merchant_id + merchant_app_id + method_id` 选取通道
7. `PluginService` 从注册表解析插件类并实例化
8. 插件执行 `pay()`
9. `PayService` 回写：
   - `channel_id`
   - `chan_order_no`
   - `chan_trade_no`
   - `fee`
   - `real_amount`
   - `extra.pay_params`
10. 转换成 `epay` 所需返回结构给调用方

### 7.2 回调处理链路

1. 第三方渠道回调 `/notify/{pluginCode}`
2. `PayNotifyService` 调插件 `notify()` 验签与解析
3. 通过 `ma_callback_inbox` 做幂等去重
4. 状态机更新订单状态
5. 写入回调日志
6. 创建商户通知任务

### 7.3 商户通知链路

1. `NotifyService` 根据订单 `extra.notify_url` 创建通知任务
2. 通知内容写入 `ma_notify_task`
3. `sendNotify()` 使用 HTTP POST JSON 回调商户
4. 若商户返回 HTTP 200 且 body 为 `success`，视为通知成功

## 8. 插件与通道现状

### `LakalaPayment`

状态：示例插件 / mock 插件

现状：

- `pay()` 已实现，但只是返回模拟二维码字符串
- `query()` 未实现
- `close()` 未实现
- `refund()` 未实现
- `notify()` 未实现

这意味着当前库里虽然已经能“创建订单并拿到拉起参数”，但还不能完成真实的拉卡拉闭环。

### `AlipayPayment`

状态：代码层面相对完整

已实现：

- `pay()`
- `query()`
- `close()`
- `refund()`
- `notify()`

特点：

- 基于 `yansongda/pay`
- 支持产品类型：
  - `alipay_web`
  - `alipay_h5`
  - `alipay_scan`
  - `alipay_app`
- 可根据环境自动选产品

注意：

- 当前开发库没有注册 `alipay` 插件记录
- 当前通道也没有指向 `AlipayPayment`

所以它虽然写在代码里，但当前数据库并没有真正启用它。

## 9. 管理后台现状

后台已经覆盖以下核心维护能力：

- 验证码登录 + JWT 鉴权
- 商户管理
- 商户应用管理
- 支付方式管理
- 支付插件注册管理
- 支付通道管理
- 订单列表 / 详情 / 退款
- 系统基础配置管理

这部分说明“支付中心后台”已经不是空架子，而是可以承接后续运营配置的。

## 10. 当前开发库快照（基于 2026-03-13 实际查询）

### 数据量

- `ma_admin`: 1
- `ma_merchant`: 1
- `ma_merchant_app`: 1
- `ma_pay_method`: 3
- `ma_pay_plugin`: 1
- `ma_pay_channel`: 2
- `ma_pay_order`: 1
- `ma_notify_task`: 0
- `ma_callback_inbox`: 0
- `ma_pay_callback_log`: 0

### 当前商户与应用

- 商户：`M001 / 测试商户`
- 应用：`1001 / 测试应用-易支付`
- 应用类型：`epay`

### 当前活动插件

- `lakala -> app\\common\\payment\\LakalaPayment`

### 当前通道

- `lakala_alipay`
- `lakala_wechat`

### 当前示例订单

- 订单号：`P20260312160833644578`
- 商户单号：`TEST123`
- 状态：`PENDING`
- 通道：`channel_id = 1`
- `extra.pay_params` 为 mock 二维码

## 11. 当前代码与需求的对应关系

你给出的项目特点，与当前实现的对应情况如下：

### 已匹配的部分

- 多商户：已支持
- 一个商户多个应用：已支持
- 一个应用多个支付通道：已支持
- 通道可绑定支付方式：已支持
- 通道可绑定支付插件：已支持
- 通道可存储插件参数：已支持
- 通道可配置手续费：已支持，当前会参与 `fee` / `real_amount` 计算
- 商户通过 `APPID` 发起支付：已支持，当前主要在 `epay` 兼容链路中落地
- 创建订单并调用第三方插件：已支持

### 仅完成“数据建模”，尚未完全落地执行的部分

- 每日限额：字段已存在，但当前下单/路由流程未校验
- 每日笔数限制：字段已存在，但当前未校验
- 最小/最大金额限制：字段已存在，但当前未校验
- 更复杂的路由策略：当前仅按 `sort` 取第一条可用通道
- 多协议统一 OpenAPI：控制器骨架存在，但未真正接入

## 12. 后续阅读建议

如果下一次继续开发，建议优先从以下文件继续进入：

- 支付入口：`app/http/api/controller/EpayController.php`
- 协议适配：`app/services/api/EpayProtocolService.php`
- 业务主流程：`app/services/PayService.php`
- 订单创建：`app/services/PayOrderService.php`
- 回调处理：`app/services/PayNotifyService.php`
- 插件管理：`app/services/PluginService.php`
- 拉卡拉插件：`app/common/payment/LakalaPayment.php`
- 支付宝插件：`app/common/payment/AlipayPayment.php`
- 通道配置：`app/http/admin/controller/ChannelController.php`

