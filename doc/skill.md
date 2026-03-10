# MPAY V2 项目技术栈与结构文档

## 1. 项目概述

MPAY V2 是一个基于 Webman 后端框架和 Vue 3 前端框架的支付管理系统，核心聚焦支付业务：商户管理、通道配置、统一支付、易支付兼容、商户通知等。管理后台提供管理员认证、菜单、系统配置、通道与插件管理；对外提供 OpenAPI 与易支付标准接口。

## 2. 技术架构

### 2.1 后端技术栈

| 类别 | 技术/框架 | 版本 | 用途 | 来源 |
|------|-----------|------|------|------|
| 基础框架 | Webman | ^2.1 | 高性能HTTP服务框架 | composer.json |
| PHP版本 | PHP | >=8.1 | 开发语言 | composer.json |
| 数据库 | webman/database | ^2.1 | 数据库操作 | composer.json |
| 缓存 | Redis | ^2.1 | 缓存存储 | composer.json |
| 缓存 | webman/cache | ^2.1 | 缓存管理 | composer.json |
| 认证 | JWT | ^7.0 | 管理员认证 | composer.json |
| 验证码 | webman/captcha | ^1.0 | 登录验证码 | composer.json |
| 事件系统 | webman/event | ^1.0 | 事件管理 | composer.json |
| 配置管理 | vlucas/phpdotenv | ^5.6 | 环境变量 | composer.json |
| 定时任务 | workerman/crontab | ^1.0 | 定时任务 | composer.json |
| 队列 | webman/redis-queue | ^2.1 | 消息队列 | composer.json |
| 验证 | topthink/think-validate | ^3.0 | 数据验证 | composer.json |
| 容器 | php-di/php-di | 7.0 | 依赖注入 | composer.json |
| 日志 | monolog/monolog | ^2.0 | 日志管理 | composer.json |
| 控制台 | webman/console | ^2.1 | 命令行工具 | composer.json |

### 2.2 前端技术栈

| 类别 | 技术/框架 | 版本 | 用途 | 来源 |
|------|-----------|------|------|------|
| 基础框架 | Vue | ^3.5.15 | 前端框架 | package.json |
| 语言 | TypeScript | ^5.2.2 | 开发语言 | package.json |
| 构建工具 | Vite | ^6.3.5 | 构建工具 | package.json |
| UI框架 | Arco Design | ^2.57.0 | 界面组件库 | package.json |
| 状态管理 | Pinia | ^2.3.0 | 状态管理 | package.json |
| 路由 | Vue Router | ^4.3.0 | 前端路由 | package.json |
| HTTP客户端 | Axios | ^1.6.8 | API调用 | package.json |
| 表单生成 | @form-create/arco-design | ^3.2.37 | 动态表单 | package.json |
| 图表 | @visactor/vchart | ^1.11.0 | 数据可视化 | package.json |
| 国际化 | vue-i18n | 10.0.0-alpha.3 | 多语言支持 | package.json |
| 工具库 | @vueuse/core | ^12.4.0 | 实用工具 | package.json |
| 二维码 | qrcode | ^1.5.4 | 二维码生成 | package.json |

## 3. 项目结构

### 3.1 后端目录结构

```
d:\phpstudy_pro\WWW\mpay\mpay_v2_webman\
├── app/                        # 应用代码
│   ├── common/                 # 通用代码
│   │   ├── base/               # 基础类
│   │   │   ├── BaseController.php
│   │   │   ├── BaseModel.php
│   │   │   ├── BaseRepository.php
│   │   │   └── BaseService.php
│   │   ├── contracts/          # 契约/接口
│   │   │   ├── PayPluginInterface.php
│   │   │   └── AbstractPayPlugin.php
│   │   ├── constants/          # 常量
│   │   ├── enums/               # 枚举
│   │   ├── middleware/          # 中间件（Cors, StaticFile）
│   │   ├── payment/             # 支付插件实现
│   │   │   └── LakalaPayment.php
│   │   └── utils/               # 工具类（JwtUtil 等）
│   ├── events/                 # 事件
│   ├── exceptions/             # 异常（BadRequest, NotFound, Validation 等）
│   ├── http/
│   │   ├── admin/              # 管理后台
│   │   │   ├── controller/
│   │   │   │   ├── AuthController.php
│   │   │   │   ├── AdminController.php
│   │   │   │   ├── MenuController.php
│   │   │   │   ├── SystemController.php
│   │   │   │   ├── ChannelController.php
│   │   │   │   └── PluginController.php
│   │   │   └── middleware/
│   │   │       └── AuthMiddleware.php
│   │   └── api/                 # 对外 API
│   │       ├── controller/
│   │       │   ├── PayController.php     # OpenAPI 支付接口（骨架）
│   │       │   └── EpayController.php    # 易支付接口（submit.php/mapi.php/api.php）
│   │       └── middleware/
│   │           ├── EpayAuthMiddleware.php
│   │           └── OpenApiAuthMiddleware.php
│   ├── jobs/                   # 异步任务
│   │   └── NotifyMerchantJob.php
│   ├── models/                 # 数据模型
│   │   ├── Admin.php
│   │   ├── Merchant.php
│   │   ├── MerchantApp.php
│   │   ├── PaymentMethod.php
│   │   ├── PaymentPlugin.php
│   │   ├── PaymentChannel.php
│   │   ├── PaymentOrder.php
│   │   ├── PaymentCallbackLog.php
│   │   ├── PaymentNotifyTask.php
│   │   └── SystemConfig.php
│   ├── repositories/           # 数据仓储
│   │   ├── AdminRepository.php
│   │   ├── MerchantRepository.php
│   │   ├── MerchantAppRepository.php
│   │   ├── PaymentMethodRepository.php
│   │   ├── PaymentPluginRepository.php
│   │   ├── PaymentChannelRepository.php
│   │   ├── PaymentOrderRepository.php
│   │   ├── PaymentNotifyTaskRepository.php
│   │   ├── PaymentCallbackLogRepository.php
│   │   └── SystemConfigRepository.php
│   ├── routes/                 # 路由
│   │   ├── admin.php
│   │   ├── api.php
│   │   └── mer.php
│   ├── services/               # 业务逻辑
│   │   ├── AuthService.php
│   │   ├── AdminService.php
│   │   ├── CaptchaService.php
│   │   ├── MenuService.php
│   │   ├── SystemConfigService.php
│   │   ├── SystemSettingService.php
│   │   ├── PluginService.php        # 插件注册与实例化
│   │   ├── ChannelRouterService.php  # 通道路由（按商户+应用+支付方式选通道）
│   │   ├── PayOrderService.php      # 订单创建、幂等、退款
│   │   ├── PayService.php            # 统一下单、调用插件
│   │   ├── NotifyService.php        # 商户通知、重试
│   │   └── api/
│   │       └── EpayService.php       # 易支付业务封装
│   ├── validation/             # 验证器
│   │   ├── EpayValidator.php
│   │   └── SystemConfigValidator.php
│   └── process/               # 进程（Http, Monitor）
├── config/                    # 配置文件
├── database/                  # 数据库脚本
│   └── mvp_payment_tables.sql # 支付系统核心表（ma_*）
├── doc/                       # 文档
│   ├── skill.md
│   ├── epay.md
│   ├── payment_flow.md
│   ├── validation.md
│   └── payment_system_implementation.md
├── public/
├── resource/
│   └── mpay_v2_admin/        # 前端项目
├── .env
└── composer.json
```

### 3.2 数据库表结构（`database/mvp_payment_tables.sql`）

| 表名 | 说明 |
|------|------|
| ma_merchant | 商户表 |
| ma_merchant_app | 商户应用表（api_type 区分 openapi/epay/custom） |
| ma_pay_method | 支付方式字典（alipay/wechat/unionpay） |
| ma_pay_plugin | 支付插件注册表（plugin_code 为主键） |
| ma_pay_channel | 支付通道表（merchant_id, merchant_app_id, method_id 关联） |
| ma_pay_order | 支付订单表（status: 0-PENDING, 1-SUCCESS, 2-FAIL, 3-CLOSED） |
| ma_pay_callback_log | 支付回调日志表 |
| ma_notify_task | 商户通知任务表（order_id, retry_cnt, next_retry_at） |
| ma_system_config | 系统配置表 |
| ma_admin | 管理员表 |

### 3.3 前端目录结构

```
resource/mpay_v2_admin/
├── src/
│   ├── api/
│   ├── components/
│   ├── layout/
│   ├── router/
│   ├── store/
│   ├── views/
│   │   ├── login/
│   │   ├── home/
│   │   ├── finance/
│   │   ├── channel/
│   │   ├── analysis/
│   │   └── system/
│   ├── App.vue
│   └── main.ts
├── package.json
└── vite.config.ts
```

## 4. 核心功能模块

### 4.1 支付业务流程约定

1. **订单创建**：`PayOrderService::createOrder`，支持幂等（merchant_id + merchant_app_id + mch_order_no 唯一）
2. **通道路由**：`ChannelRouterService::chooseChannel(merchantId, merchantAppId, methodId)` 按第一个可用通道
3. **统一下单**：`PayService::unifiedPay` → 创建订单 → 选通道 → 实例化插件 → 调用 `unifiedOrder`
4. **商户通知**：`NotifyService::createNotifyTask`，`notify_url` 从订单 `extra['notify_url']` 获取
5. **通知重试**：`NotifyMerchantJob` 定时拉取待重试任务，指数退避

### 4.2 支付插件接口

- `app/common/contracts/PayPluginInterface.php`
- `app/common/contracts/AbstractPayPlugin.php`
- 示例实现：`app/common/payment/LakalaPayment.php`

插件需实现：`getName`、`getSupportedMethods`、`getConfigSchema`、`getSupportedProducts`、`init`、`unifiedOrder`、`refund`、`verifyNotify` 等。

### 4.3 后端核心模块

| 模块 | 主要功能 | 文件位置 |
|------|----------|----------|
| 认证 | 管理员登录、验证码 | AuthController, AuthService |
| 管理员 | 获取管理员信息 | AdminController, AdminService, Admin 模型 |
| 菜单 | 获取路由菜单 | MenuController, MenuService |
| 系统 | 字典、配置管理 | SystemController, SystemConfigService |
| 通道管理 | 通道列表、详情、保存 | ChannelController, PaymentChannelRepository |
| 插件管理 | 插件列表、配置 Schema、产品列表 | PluginController, PluginService |
| 易支付 | submit.php/mapi.php/api.php | EpayController, EpayService |

### 4.4 前端核心模块

| 模块 | 主要功能 | 位置 |
|------|----------|------|
| 布局 | 系统整体布局 | src/layout/ |
| 认证 | 登录、权限控制 | src/views/login/ |
| 首页 | 数据概览 | src/views/home/ |
| 财务管理 | 结算、对账、发票 | src/views/finance/ |
| 渠道管理 | 通道配置、支付方式 | src/views/channel/ |
| 数据分析 | 交易分析、商户分析 | src/views/analysis/ |
| 系统设置 | 系统配置、字典管理 | src/views/system/ |

## 5. API 接口设计

### 5.1 管理后台（/adminapi）

| 路径 | 方法 | 控制器 | 功能 | 权限 |
|------|------|--------|------|------|
| /adminapi/captcha | GET | AuthController | 获取验证码 | 无 |
| /adminapi/login | POST | AuthController | 管理员登录 | 无 |
| /adminapi/user/getUserInfo | GET | AdminController | 获取管理员信息 | JWT |
| /adminapi/menu/getRouters | GET | MenuController | 获取路由菜单 | JWT |
| /adminapi/system/getDict[/{code}] | GET | SystemController | 获取字典 | JWT |
| /adminapi/system/base-config/tabs | GET | SystemController | 获取配置标签 | JWT |
| /adminapi/system/base-config/form/{tabKey} | GET | SystemController | 获取表单配置 | JWT |
| /adminapi/system/base-config/submit/{tabKey} | POST | SystemController | 提交配置 | JWT |
| /adminapi/channel/list | GET | ChannelController | 通道列表 | JWT |
| /adminapi/channel/detail | GET | ChannelController | 通道详情 | JWT |
| /adminapi/channel/save | POST | ChannelController | 保存通道 | JWT |
| /adminapi/channel/plugins | GET | PluginController | 插件列表 | JWT |
| /adminapi/channel/plugin/config-schema | GET | PluginController | 插件配置 Schema | JWT |
| /adminapi/channel/plugin/products | GET | PluginController | 插件产品列表 | JWT |

### 5.2 易支付接口（对外 API）

| 路径 | 方法 | 控制器 | 功能 | 说明 |
|------|------|--------|------|------|
| /submit.php | ANY | EpayController | 页面跳转支付 | 参数：pid, key, out_trade_no, money, name, type, notify_url 等 |
| /mapi.php | POST | EpayController | API 接口支付 | 返回 trade_no、payurl/qrcode/urlscheme |
| /api.php | GET | EpayController | 订单查询/退款 | act=order 查询，act=refund 退款 |

易支付约定：`pid` 映射为 `app_id`（商户应用标识），`key` 为 `app_secret`。

## 6. 命名与约定

### 6.1 模型与仓储命名

- 业务语义命名：`PaymentMethod`、`PaymentOrder`、`PaymentChannel` 等，不使用 `ma` 前缀
- 表名仍为 `ma_*`，通过模型 `$table` 映射

### 6.2 订单相关字段

- 系统订单号：`order_id`
- 商户订单号：`mch_order_no`
- 商户ID：`merchant_id`
- 商户应用ID：`merchant_app_id`
- 通道ID：`channel_id`
- 支付方式ID：`method_id`（关联 ma_pay_method.id）

### 6.3 商户应用 api_type

用于区分不同 API 的验签与通知方式：`openapi`、`epay`、`custom` 等。

## 7. 开发流程

### 7.1 后端开发

1. **环境**：PHP 8.1+，Composer，MySQL，Redis
2. **依赖**：`composer install`
3. **数据库**：执行 `database/mvp_payment_tables.sql`
4. **配置**：复制 `.env.example` 为 `.env`
5. **启动**：
   - Linux：`php start.php start`
   - Windows：`php windows.php start`

### 7.2 前端开发

1. **环境**：Node.js 18.12+，PNPM 8.7+
2. **依赖**：`pnpm install`
3. **开发**：`pnpm dev`
4. **构建**：`pnpm build:prod`

## 8. 相关文档

| 文件 | 说明 |
|------|------|
| doc/epay.md | 易支付接口说明 |
| doc/payment_flow.md | 支付流程说明 |
| doc/payment_system_implementation.md | 支付系统实现说明 |
| doc/validation.md | 验证规则说明 |
| database/mvp_payment_tables.sql | 支付系统表结构 |

## 9. 总结

MPAY V2 以支付业务为核心，采用 Webman + Vue 3 技术栈，后端分层清晰（Controller → Service → Repository → Model），支持支付插件扩展与易支付兼容。管理后台基于 JWT 认证，提供通道、插件、系统配置等管理能力；对外提供易支付标准接口（submit/mapi/api），便于第三方商户接入。
