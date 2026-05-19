<p align="center">
  <img src="public/assets/brand/mpay-logo.svg" alt="MPAY V2 Webman" width="360">
</p>

<h1 align="center">MPAY V2</h1>

<p align="center">
  <a href="https://gitee.com/technical-laohu/mpay_v2_webman">项目主页</a> |
  <a href="https://gitee.com/technical-laohu/mpay_v2_webman/releases">发行版</a> |
  <a href="https://gitee.com/technical-laohu/mpay_v2_webman/issues">问题反馈</a>
</p>

MPAY V2 Webman 是一套基于 Webman 的支付中台后端服务，面向多商户、多通道、多支付方式的统一收款、支付路由、订单管理、退款、转账、清算和资金流水场景。

程序通过发行版安装包部署，内置安装向导会完成环境检测、数据库初始化、系统配置写入和管理员账号创建。

## ✨ 项目介绍

MPAY V2 的核心目标是把支付系统里容易分散的能力统一起来：商户、支付方式、支付插件、插件配置、支付通道、轮询组、路由策略、业务单、支付单、回调通知、退款、清算和资金账户都由后端统一建模和管理。

它不是简单的支付接口转发程序，而是一套可二次开发的支付中台底座：

- 对平台方：提供管理后台 API，用于维护商户、通道、插件、路由、订单、退款、清算和资金。
- 对商户方：提供商户后台 API，用于查看订单、退款、清算、余额、流水和 API 凭证。
- 对接入方：兼容 ePay V1/V2 协议，便于已有商城、发卡、资源站、业务系统快速接入。
- 对开发者：提供统一支付插件契约，便于扩展官方 API 支付、个人收款监听、网页流水监听等通道。

## ⚙️ 功能特性

| 模块 | 能力 |
| --- | --- |
| 管理后台 API | 管理员登录、商户、商户分组、商户策略、支付方式、支付插件、插件配置、支付通道、轮询组、路由预览、订单、退款、清算、资金、文件、系统配置 |
| 商户后台 API | 商户登录、商户资料、API 凭证、我的通道、自助插件配置、商户路由、路由预览、支付订单、退款订单、清算记录、可提现余额、资金流水 |
| 收银台 API | 收银台上下文、确认支付、支付单详情、支付状态、身份授权上下文、微信网页授权回调、身份回填继续支付 |
| 开放支付协议 | ePay V1、ePay V2、页面跳转支付、API 创建订单、订单查询、退款、退款查询、关闭订单、商户信息、商户订单、转账、转账查询、转账余额 |
| 支付核心 | 业务单、支付单、支付路由、插件运行时、支付状态生命周期、回调幂等、主动查单、商户通知任务、交易追踪 |
| 插件体系 | `pay()`、`query()`、`notify()`、`refund()`、`refundQuery()`、`close()`、`transfer()`、`transferQuery()`、`channelNotify()` |
| 资金清算 | 平台代收、商户自收、服务费、冻结、释放、清算单、清算明细、资金流水、退款净额重算 |
| 异步任务 | Webman 自定义进程、Redis Queue、通知重试、退款派发、转账派发、转账延迟查单、清算自动入账、网页流水通知 |
| 文件资产 | 本地存储、远程 URL 导入、阿里云 OSS、腾讯云 COS、预览、下载 |
| 运维能力 | 安装向导、环境检测、系统配置同步、运行状态、日志记录、命令行测试、反向代理部署 |

## 🖼️ 效果截图

以下截图用于展示 MPAY V2 的完整业务效果。

| 管理后台运营首页 | 商户后台首页 |
| --- | --- |
| ![管理后台运营首页](https://foruda.gitee.com/images/1779179393093638388/998e77f2_12697045.jpeg) | ![商户后台首页](https://foruda.gitee.com/images/1779179487907421618/f9bddaf1_12697045.jpeg) |

| 管理后台支付订单 | 商户后台我的通道 |
| --- | --- |
| ![管理后台支付订单](https://foruda.gitee.com/images/1779179409315423492/b5509e0e_12697045.jpeg) | ![商户后台我的通道](https://foruda.gitee.com/images/1779179503548189014/90dea02c_12697045.jpeg) |

| 管理后台插件配置 | 收银台入口 |
| --- | --- |
| ![管理后台插件配置](https://foruda.gitee.com/images/1779179332423808751/785548c4_12697045.jpeg) | ![收银台入口](https://foruda.gitee.com/images/1779179535649728680/c0bd4284_12697045.jpeg) |

## 🧱 技术栈

| 类型 | 技术 |
| --- | --- |
| 运行环境 | PHP 8.1+ |
| 后端框架 | Webman 2.x、Workerman |
| 数据库 | MySQL |
| 缓存与队列 | Redis、webman/redis-queue |
| 命令行 | webman/console |
| 鉴权 | JWT，管理后台、商户后台和开放 API 独立鉴权 |
| 存储 | 本地存储、阿里云 OSS、腾讯云 COS |
| 日志 | Monolog、Webman runtime logs |
| 依赖管理 | Composer |
| 静态资源 | `public` 目录承载首页、安装页、文档页或自行构建的前端产物 |

## 🧭 系统架构

```text
外部业务系统 / 商城 / 发卡系统
        |
        | ePay V1/V2 / Open API
        v
MPAY Webman 后端
        |
        |-- 管理后台 API：商户、插件、通道、路由、订单、资金、系统配置
        |-- 商户后台 API：商户资料、API 凭证、订单、退款、清算、流水
        |-- 收银台 API：支付上下文、确认支付、身份授权、支付状态
        |-- 支付运行时：业务单、支付单、路由、插件调用、查单、回调、通知
        |-- Redis Queue：通知、退款、转账、清算、网页流水监听
        |
        v
第三方支付 / 官方 API / 个人收款监听 / 网页流水监听
```

核心支付链路：

```text
商户系统发起支付
  -> 校验商户状态和签名
  -> 创建业务单 ma_biz_order
  -> 创建支付单 ma_pay_order
  -> 按商户分组和支付方式解析路由
  -> 轮询组选择支付通道
  -> 加载支付插件和插件配置
  -> 插件返回二维码、跳转链接、HTML、JSAPI 参数或身份授权地址
  -> 上游回调或主动查单推进支付状态
  -> 创建商户通知任务
  -> 平台代收场景生成清算单并入账
```

## 📁 目录结构

```text
app/command/       命令行任务、迁移、测试和联调命令
app/common/        基类、常量、工具、中间件、支付插件、SDK
app/http/          admin、mer、api 控制器、中间件和参数校验
app/model/         数据模型
app/process/       Webman 自定义进程
app/queue/         Redis Queue 消费者
app/repository/    仓库层
app/route/         显式路由
app/service/       支付、商户、资金、文件、安装、系统配置等服务
config/            Webman 配置、业务配置、进程配置、队列配置
database/          迁移、种子和完整 DDL
public/            静态资源目录
runtime/           运行日志、缓存、PID、上传文件等运行时目录
support/           Webman 支撑代码
tools/             辅助工具，例如 receipt_watcher
```

## 🧰 运行环境

最低要求：

| 依赖 | 要求 |
| --- | --- |
| PHP | 8.1 或更高 |
| MySQL | 5.7+ / 8.x |
| Redis | 5.x+ |
| PHP 扩展 | `pdo_mysql`、`redis`、`pcntl` |

开发环境可以直接使用 PHP CLI 启动；生产环境建议使用 Nginx 或 Apache 反向代理到 Webman HTTP 服务。

## 📝 安装说明

MPAY V2 面向实际部署提供发行版安装包。正式安装建议从 Gitee 发行版下载，不建议直接用源码仓库作为生产安装包。

发行版安装包结构与项目 `dist` 目录一致，通常包含：

```text
app/              后端业务源码
config/           配置文件
database/         数据库迁移、种子和完整 DDL
public/           首页、安装页、接口文档页和静态资源
runtime/          运行时目录
support/          Webman 支撑代码
vendor/           Composer 依赖
.env.example      环境变量示例
composer.json     Composer 配置
composer.lock     Composer 锁定文件
webman            Webman 命令入口
windows.php       Windows 开发启动入口
start.php         Webman 启动入口
```

### 1. 下载发行版

进入项目发行版页面下载最新安装包：

```text
https://gitee.com/technical-laohu/mpay_v2_webman/releases
```

下载后解压到站点目录，例如：

```text
/www/wwwroot/pay.example.com
```

### 2. 准备运行环境

服务器需要准备：

| 依赖 | 说明 |
| --- | --- |
| PHP | 8.1 或更高版本 |
| MySQL | 建议 5.7+ / 8.x |
| Redis | 用于缓存、登录态、队列和运行时任务 |
| 程序依赖 | 发行版已包含 `vendor`，通常不需要在服务器单独安装依赖 |
| PHP 扩展 | `pdo_mysql`、`redis`、`pcntl` |

Webman 是常驻内存服务，不依赖 PHP-FPM 处理请求。

### 3. 设置目录权限

确保运行用户可以写入以下目录：

```text
runtime/
public/storage/
```

安装程序会写入 `.env`、安装锁、日志、缓存和上传目录。若服务器权限较严格，请先确认站点目录对 Webman 运行用户可写。

### 4. 启动服务

Linux / macOS：

```bash
php webman start
```

Windows 开发环境：

```bash
php windows.php
```

默认监听地址：

```text
http://127.0.0.1:8787
```

生产环境建议使用 Supervisor、systemd、宝塔守护进程等方式守护 Webman 进程，再通过 Nginx 或 Apache 反向代理访问。

### 5. 打开安装程序

浏览器访问：

```text
http://你的域名/install
```

或本地访问：

```text
http://127.0.0.1:8787/install
```

安装程序会引导填写：

- 站点名称和站点 URL。
- MySQL 地址、端口、数据库名、账号和密码。
- Redis 地址、端口、密码、缓存库和队列库。
- 管理员账号、管理员密码和 JWT 密钥。

安装程序会自动完成：

- 检测 PHP、目录权限、数据库和 Redis。
- 创建数据库，前提是数据库账号具备创建权限。
- 写入 `.env` 配置文件。
- 执行数据库迁移。
- 写入支付方式、系统配置、支付插件和管理员账号。
- 生成平台 ePay RSA 密钥。
- 写入安装锁。

安装完成后重启 Webman：

```bash
php webman restart
```

### 6. 安装后检查

安装完成后建议检查：

| 地址 | 说明 |
| --- | --- |
| `/` 或 `/home` | 项目首页 |
| `/install` | 已安装后应提示安装状态 |
| `/docs` | 内置接口文档页 |
| `/adminapi/system/public-config` | 管理后台公开配置接口 |
| `/api/cashier/config` | 收银台公开配置接口 |

页面入口 `/admin`、`/mer`、`/cashier` 依赖对应构建产物是否存在；后端接口不受影响。
## 🚪 访问入口

| 场景 | 页面入口 | API 入口 |
| --- | --- | --- |
| 首页 | `/`、`/home` | 无 |
| 安装向导 | `/install` | `/adminapi/install/*` |
| 内置接口文档页 | `/docs` | 无 |
| 管理后台 | `/admin`，需存在 `public/admin/index.html` | `/adminapi` |
| 商户后台 | `/mer`，需存在 `public/mer/index.html` | `/merapi` |
| 收银台 | `/cashier`、`/payment`，需存在 `public/cashier/index.html` | `/api/cashier`、`/api/pay` |
| ePay V1 | `/submit.php`、`/mapi.php`、`/api.php` | 同左 |
| ePay V2 | 无固定页面 | `/api/pay`、`/api/merchant`、`/api/transfer` |
| 通道级通知 | 无页面 | `/api/pay/{chanId}/notify` |

如果对应前端构建产物不存在，页面入口会返回 `Admin page not found`、`Merchant page not found` 或 `Cashier page not found`。这不影响后端 API 使用。

## ⌨️ 常用命令

```bash
# 启动、重启、停止
php webman start
php webman restart
php webman stop

# Windows 开发环境启动
php windows.php

# 数据库迁移
php webman migrate
php webman migrate:status

# 系统配置默认值同步
php webman system:config-sync

# 商户通知重试
php webman payment:notify-retry

# 支付链路烟雾测试
php webman mpay:test --all

# P0 核心检查
php webman mpay:p0-check

# ePay V1 兼容接口测试
php webman epay:mapi

# ePay V2 初始化联调密钥
php webman epay:v2-bootstrap

# ePay V2 API 烟雾测试
php webman epay:v2-api

# ePay V1/V2 mock 全链路测试
php webman epay:mock-chain
```

## 🔌 开放接口速览

### 🔹 ePay V1

| 入口 | 方法 | 说明 |
| --- | --- | --- |
| `/submit.php` | GET / POST | 页面跳转支付 |
| `/mapi.php` | POST | 接口支付 |
| `/api.php` | GET / POST | 商户信息、订单查询、退款等兼容 API |

V1 使用 MD5 签名，面向已有易支付生态的接入方。

### 🔸 ePay V2

| 入口 | 方法 | 说明 |
| --- | --- | --- |
| `/api/pay/submit` | GET / POST | 页面跳转支付 |
| `/api/pay/create` | POST | API 创建支付订单 |
| `/api/pay/query` | POST | 查询支付订单 |
| `/api/pay/refund` | POST | 创建退款 |
| `/api/pay/refundquery` | POST | 查询退款 |
| `/api/pay/close` | POST | 关闭订单 |
| `/api/merchant/info` | POST | 查询商户信息 |
| `/api/merchant/orders` | POST | 查询商户订单 |
| `/api/transfer/submit` | POST | 提交转账 |
| `/api/transfer/query` | POST | 查询转账 |
| `/api/transfer/balance` | POST | 查询转账余额 |

V2 使用 RSA 签名，适合新接入系统。

创建支付订单示例：

```bash
curl -X POST "http://127.0.0.1:8787/api/pay/create" \
  -H "Content-Type: application/json" \
  -d '{
    "pid": 1001,
    "type": "alipay",
    "out_trade_no": "T202605190001",
    "notify_url": "https://merchant.example.com/pay/notify",
    "return_url": "https://merchant.example.com/pay/return",
    "name": "测试订单",
    "money": "9.90",
    "clientip": "127.0.0.1",
    "timestamp": "1779179000",
    "sign_type": "RSA",
    "sign": "..."
  }'
```

返回结构会根据插件能力不同返回二维码内容、跳转链接、HTML、URL Scheme、JSAPI 参数或身份授权地址。

## 🧩 支付插件

支付插件位于：

```text
app/common/payment
```

当前内置插件：

| 插件类 | 插件编码 | 说明 |
| --- | --- | --- |
| `AlipayApiPayment` | `alipay_api` | 支付宝官方 API 支付 |
| `WechatApiPayment` | `wechat_api` | 微信官方 API 支付 |
| `EpayV1Payment` | `epay_v1` | 彩虹易支付 V1 协议适配 |
| `EpayV2Payment` | `epay_v2` | 彩虹易支付 V2 协议适配 |
| `AlipayReceiptPayment` | `alipay_receipt` | 支付宝个人收款监听 |
| `WechatReceiptPayment` | `wxpay_receipt` | 微信个人收款监听 |
| `ShouQianBaReceiptPayment` | `shouqianba_receipt` | 收钱吧二维码牌网页流水监听 |
| `PostarReceiptPayment` | `postar_receipt` | 星驿付收款单网页流水监听 |

插件常用方法：

| 方法 | 职责 |
| --- | --- |
| `pay()` | 创建支付承接信息 |
| `query()` | 主动查询支付状态 |
| `notify()` | 处理上游支付回调 |
| `refund()` | 发起退款 |
| `refundQuery()` | 查询退款状态 |
| `close()` | 关闭订单 |
| `transfer()` | 发起转账 |
| `transferQuery()` | 查询转账状态 |
| `channelNotify()` / `channelNotifyPayload()` | 通道级通知先定位平台支付单 |

`pay()` 返回值会由支付运行时转换为收银台和开放 API 可识别的支付呈现结构，常见类型包括：

| 类型 | 说明 |
| --- | --- |
| `qrcode` / `qr_code` | 二维码内容，适合扫码支付 |
| `url` / `redirect` | 跳转链接 |
| `html` | 上游返回的 HTML 表单或页面片段 |
| `scheme` | App URL Scheme |
| `jsapi` | 公众号、小程序或 JSAPI 支付参数 |
| `identity` | 缺少 OpenID、buyer_id 等用户身份时返回授权承接地址 |

## 🛣️ 支付路由模型

支付选路固定围绕下面的数据关系展开：

```text
商户
  -> 商户分组
  -> 路由绑定
  -> 轮询组
  -> 轮询组通道编排
  -> 支付通道
  -> 插件配置
  -> 支付插件
```

路由模式：

| 模式 | 说明 |
| --- | --- |
| 顺序轮询 | 按通道排序和轮询状态选择 |
| 权重随机 | 按权重随机选择可用通道 |
| 默认通道 | 优先使用标记为默认的通道 |

如果支付创建失败并提示“路由无命中”，通常需要检查商户状态、商户分组、路由绑定、轮询组、通道状态、插件状态和支付方式是否一致。

## 💰 资金与清算口径

金额在系统内部统一使用“分”作为计算和存储单位。ePay 协议面向商户时使用元字符串，例如 `"9.90"`。

平台代收：

```text
支付成功
  -> 创建商户通知任务
  -> 生成待清算单
  -> 清算入账后增加商户可提现余额
  -> 写入资金流水
```

商户自收：

```text
支付资金直接进入商户自己的上游账户
  -> 平台只处理服务费冻结、扣除或释放
  -> 不生成平台代收清算入账
```

退款创建时必须锁定原支付单，并把 `CREATED`、`PROCESSING`、`SUCCESS` 的退款单计入占用金额，避免并发超退。清算入账前会按支付单和已成功退款重新核算净额。

## 🔁 异步任务与进程

| 进程 / 队列 | 说明 |
| --- | --- |
| `payment-runtime` | 商户通知重试、支付超时扫描、支付中订单主动查单 |
| `receipt-watcher-sync` | 将需要查询流水的账号和订单同步到 Redis |
| `merchant_notify` | 商户通知投递 |
| `refund_dispatch` | 退款上游派发 |
| `transfer_dispatch` | 转账上游派发 |
| `transfer_query` | 转账延迟查单 |
| `settlement_complete` | 清算自动入账 |
| `receipt_flow_notify` | 网页流水监听通知处理 |

Linux 生产环境使用 `php webman start` 会按 Webman 配置启动相关进程。Windows 开发环境请使用 `php windows.php`。

## 👀 网页流水监听

网页流水监听适用于第三方平台没有标准回调，但可以登录网页后台查询收款流水的场景。

职责边界：

- Webman 后端负责维护账号、订单任务、插件配置和订单匹配。
- Python `receipt_watcher` 负责登录第三方网页后台并抓取流水。
- 流水归一后投递 Redis 队列，再由 Webman 调用插件完成订单定位和支付确认。

当前内置适配方向：

- 收钱吧二维码牌收款。
- 星驿付收款单收款。

## 🚀 部署建议

### Nginx 反向代理示例

```nginx
server {
    listen 80;
    server_name pay.example.com;

    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

注意不要用通用规则拦截 `/submit.php`、`/mapi.php`、`/api.php`，这些是 ePay V1 兼容入口，不是传统 PHP-FPM 文件执行入口。

### Apache 反向代理示例

```apache
<VirtualHost *:80>
    ServerName pay.example.com

    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:8787/
    ProxyPassReverse / http://127.0.0.1:8787/

    RequestHeader set X-Forwarded-Proto "http"
    RequestHeader set X-Forwarded-Port "80"
</VirtualHost>
```

生产环境建议：

- 使用 HTTPS。
- 使用 Supervisor、systemd、宝塔守护进程或容器编排保持 Webman 常驻。
- 定期备份 MySQL 和重要配置。
- `runtime/`、`public/storage/` 需要可写。
- 生产环境关闭调试输出，检查日志权限。
- 支付回调地址、商户通知地址、站点 URL 必须使用公网可访问域名。

## 🔐 安全注意事项

- 安装完成后立即修改默认管理员账号和密码。
- 上线前替换所有 JWT 密钥、平台密钥、商户密钥、数据库密码和 Redis 密码。
- 不要把 `.env`、证书、私钥、上游支付密钥提交到公开仓库。
- 商户开放 API 凭证只用于接口签名，不等同于商户后台登录密码。
- 管理后台、商户后台和开放 API 是三套独立鉴权体系。
- 个人收款监听、网页流水监听、代收和清算业务需要自行确认合规边界。

## 📄 许可证

仓库内包含 `LICENSE` 文件。使用、二次开发、商用发布和插件分发前，请确认当前仓库许可证、业务代码授权边界和第三方 SDK 的授权要求。
