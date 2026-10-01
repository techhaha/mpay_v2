<p align="center">
  <img src="https://foruda.gitee.com/images/1779352690595058298/81c08ce0_12697045.png" alt="MPAY V2 Webman" width="400">
</p>

<h1 align="center">码支付 V2</h1>

<p align="center">
  <a href="https://gitee.com/technical-laohu/mpay_v2_webman">Gitee 项目</a> |
  <a href="https://github.com/techhaha/mpay_v2">GitHub 项目</a> |
  <a href="https://gitee.com/technical-laohu/mpay_v2_webman/wikis/00-开始阅读">项目文档</a> |
  <a href="https://gitee.com/technical-laohu/mpay_v2_webman/releases">发行版下载</a> |
  <a href="https://gitee.com/technical-laohu/mpay_v2_webman/issues">问题反馈</a>
</p>

**MPAY V2** 是一套基于 Webman 的支付中台后端服务，面向**多商户、多通道、多支付方式**的统一收款、支付路由、订单管理、退款、转账、清算和资金流水场景。

程序通过发行版安装包部署，内置安装向导会完成环境检测、数据库初始化、系统配置写入和管理员账号创建。

### 发行版已经发布测试版，欢迎安装测试提BUG

## 🌐 开源地址

| 平台 | 项目地址 |
| --- | --- |
| Gitee | [technical-laohu/mpay_v2_webman](https://gitee.com/technical-laohu/mpay_v2_webman) |
| GitHub | [techhaha/mpay_v2](https://github.com/techhaha/mpay_v2) |

## 🔎 演示环境

| 项目 | 地址 / 账号 | 密码 |
| --- | --- | --- |
| 演示地址 | [https://test.qcjy.cc](https://test.qcjy.cc) | - |
| 管理后台 | `admin` | `Aa123456` |
| 商户后台 | `M20260521120046881802` | `Aa123456` |

演示账号只用于体验公开界面，不是所有新安装站点的默认账号。不要向共享演示环境上传真实密钥、上游凭证、证件或银行卡资料，也不要使用演示站进行真实资金操作。

## 📚 文档与教程

| 文档 | 说明 |
| --- | --- |
| 项目 Wiki：[Gitee](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/00-开始阅读) · [GitHub](https://github.com/techhaha/mpay_v2/wiki/00-开始阅读) | 按用户、接入开发者、二开和运维任务阅读，包含完整目录 |
| [宝塔发行包安装](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/03-宝塔发行包安装) | 使用 `mpay.zip`、宝塔、PHP CLI、MySQL、Redis 和反向代理部署 |
| [Docker 安装](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/04-Docker安装) | 使用 `mpay-docker.zip` 部署 MPAY、MySQL 和 Redis |
| [监听工具安装](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/05-监听工具安装) | 按需选择 Go 直连或 Chromium 浏览器版，核对 Redis、授权、账号与流水 |
| [完成第一笔收款](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/06-完成第一笔收款) | 串起商户、插件配置、通道、路由、付款确认和商户通知 |
| [收款插件配置总览](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/09-收款插件配置指南) | 先选收款方案，再进入对应的详细操作教程 |
| [码牌类插件配置使用教程](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/码牌类插件配置使用教程) | 从账号、配置、通道到系统监听和路由，按步骤完成设置 |
| [码牌字段映射与排查手册](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/码牌类插件字段映射与排查手册) | 逐平台说明商户号、门店 ID、终端号的取值来源与常见误填 |
| [支付宝微信个人收款监听配置](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/支付宝微信个人收款监听配置教程) | SmsForwarder Webhook、转发规则与 wxtool 公众号分组的详细设置 |
| [天阙 Pretran 动态码配置](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/天阙Pretran动态码插件配置教程) | 三种模式、mno/设备号、动态建码、详情备注及 UUID 区别 |
| [银行卡公众号收款配置](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/银行卡公众号收款配置教程) | 中行、建行、工行的收款资料、固定公众号及金额识别 |
| [账单与链上收款配置](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/账单与链上收款配置教程) | 支付宝账单凭证、USDT TRC20 地址池及识别金额 |
| [商户接入指南](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/11-商户接入指南) | ePay V1/V2 凭证、签名、下单、查询、退款和通知联调 |
| [支付插件开发](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/16-支付插件开发) | 最小插件、配置 schema、完整 pay() 返回及测试要求 |

## 🎥 视频教程

视频教程用于演示 MPAY V2 的实际安装流程，建议结合上方文字教程一起阅读。

| 教程 | 适用场景 | 视频地址 |
| --- | --- | --- |
| 📌 宝塔面板源码安装教程 | 使用 `mpay.zip` 发行版，在宝塔面板部署 MPAY V2 | [哔哩哔哩观看](https://www.bilibili.com/video/BV138bw6aEK5/?share_source=copy_web) |
| 📌 Docker 安装教程 | 使用 Docker 发行版一键部署 MPAY V2 码支付服务 | [哔哩哔哩观看](https://www.bilibili.com/video/BV1TXhe69ENi/?share_source=copy_web) |
| 📌 配套监听工具 Docker 安装教程 | 使用 Docker 部署 MPAY V2 码支付配套监听工具 | [哔哩哔哩观看](https://www.bilibili.com/video/BV11dhe6DEe4/?share_source=copy_web) |

## ✨ 项目介绍

**MPAY V2** 的核心目标是把支付系统里容易分散的能力统一起来：商户、支付方式、支付插件、插件配置、支付通道、轮询组、路由策略、业务单、支付单、回调通知、退款、清算和资金账户都由后端统一建模和管理。

它不是简单的支付接口转发程序，而是一套可二次开发的支付中台底座：

- **对平台方：** 提供管理后台 API，用于维护商户、通道、插件、路由、订单、退款、清算和资金。
- **对商户方：** 提供商户后台 API，用于查看订单、退款、清算、余额、流水和 API 凭证。
- **对接入方：** 兼容 ePay V1/V2 协议，便于已有商城、发卡、资源站、业务系统快速接入。
- **对开发者：** 提供统一支付插件契约，便于扩展官方 API 支付、个人收款监听、网页流水监听等通道。

## ⚙️ 功能特性

| 模块 | 能力 |
| --- | --- |
| 管理后台 API | 管理员登录、商户、商户分组、商户策略、支付方式、支付插件、插件配置、支付通道、轮询组、路由预览、订单、退款、清算、资金、文件、系统配置 |
| 商户后台 API | 商户登录、商户资料、API 凭证、收款通道、收款配置、通道选择、通道检测、支付订单、退款订单、结算记录、账户余额、资金流水 |
| 收银台 API | 收银台上下文、确认支付、支付单详情、支付状态、身份授权上下文、微信网页授权回调、身份回填继续支付 |
| 开放支付协议 | ePay V1、ePay V2、页面跳转支付、API 创建订单、订单查询、退款、退款查询、关闭订单、商户信息、商户订单、转账、转账查询、转账余额 |
| 支付核心 | 业务单、支付单、支付路由、插件运行时、支付状态生命周期、回调幂等、主动查单、商户通知任务、交易追踪 |
| 插件体系 | `pay()`、`query()`、`notify()`、`refund()`、`close()`，以及可选退款查询 `queryRefund()`、转账、进件和通道级通知能力 |
| 资金清算 | 平台代收、商户自收、服务费、冻结、释放、清算单、清算明细、资金流水、退款净额重算 |
| 异步任务 | Webman 自定义进程、Redis Queue、通知重试、退款派发、转账派发、转账延迟查单、清算自动入账、网页流水通知 |
| 文件资产 | 本地存储、远程 URL 导入、阿里云 OSS、腾讯云 COS、预览、下载 |
| 运维能力 | 安装向导、环境检测、系统配置同步、运行状态、日志记录、命令行测试、反向代理部署 |

## 🖼️ 效果截图

以下为已有版本的界面示意，便于了解功能布局。菜单、字段及操作能力以当前版本和 Wiki 为准，图中数据与告警不代表最新版本已完成全流程验收。

| 管理后台运营首页 | 商户后台首页 |
| --- | --- |
| ![管理后台运营首页](https://foruda.gitee.com/images/1779238498207284572/7215e8ce_12697045.png) | ![商户后台首页](https://foruda.gitee.com/images/1779238533395522489/5a21b96b_12697045.jpeg) |

| 插件中心 | 插件配置 |
| --- | --- |
| ![插件中心](https://foruda.gitee.com/images/1779238551992502057/341b04c1_12697045.png) | ![插件配置](https://foruda.gitee.com/images/1779238572250428440/4fe329d9_12697045.png) |

| 商户列表 | 商户资料 |
| --- | --- |
| ![商户列表](https://foruda.gitee.com/images/1779238590151676222/f514118c_12697045.png) | ![商户资料](https://foruda.gitee.com/images/1779238609164906930/dcf38240_12697045.png) |

| 通道轮询 | 系统配置 |
| --- | --- |
| ![通道轮询](https://foruda.gitee.com/images/1779238629717797023/3fd4032a_12697045.png) | ![系统配置](https://foruda.gitee.com/images/1779238653064286041/6453f45d_12697045.png) |

| 运行监控 | 账户资金 |
| --- | --- |
| ![运行监控](https://foruda.gitee.com/images/1779238672017076518/5b618172_12697045.png) | ![账户资金](https://foruda.gitee.com/images/1779238690648683948/72147d60_12697045.png) |

| 支付订单 | 支付通道 |
| --- | --- |
| ![支付订单](https://foruda.gitee.com/images/1779238708517050435/8baed165_12697045.png) | ![支付通道](https://foruda.gitee.com/images/1779238724562054604/37094c84_12697045.png) |

| 二维码收银台 | 文件管理 |
| --- | --- |
| ![二维码收银台](https://foruda.gitee.com/images/1779238745769201133/3eec33f7_12697045.png) | ![文件管理](https://foruda.gitee.com/images/1779238770787169407/70d580a0_12697045.png) |


## 🧱 技术栈

| 类型 | 技术 |
| --- | --- |
| 运行环境 | PHP 8.2+（当前已核验发行包依赖要求） |
| 后端框架 | Webman 2.x、Workerman |
| 数据库 | MySQL |
| 缓存与队列 | Redis、webman/redis-queue |
| 命令行 | webman/console |
| 鉴权 | JWT，管理后台、商户后台和开放 API 独立鉴权 |
| 存储 | 本地存储、阿里云 OSS、腾讯云 COS |
| 日志 | Monolog、Webman runtime logs |
| 依赖管理 | Composer |
| 静态资源 | 发行包的 `public` 已包含首页、安装页、文档页和管理/商户/收银台构建产物 |

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
  -> 按需完成身份授权，插件返回完整标准结果及支付呈现
  -> 可信上游回调、支持的查单或监听流水推进支付状态
  -> 创建商户通知任务
  -> 平台代收场景生成清算单并入账
```

## 📁 目录结构

```text
app/command/       生产命令、迁移和运行期任务命令
app/command/tests/  测试命令、测试套件和命令专用 mock（纯净安装包排除）
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

新部署建议按下列教程基线准备。教程使用版本与最低兼容范围是两件事，其他版本应以对应发行包的兼容说明为准：

| 依赖 | 要求 |
| --- | --- |
| PHP | 当前已核验发行包要求 PHP 8.2+ |
| MySQL | 安装教程采用 MySQL 8.0，使用独立数据库和数据库用户 |
| Redis | 安装教程采用 Redis 7；流水监听需支持配套 Stream 命令 |
| 安装检测相关扩展 | `pdo_mysql`、`openssl`、`json`、`mbstring`、`redis`，并确认 RSA 密钥生成功能可用 |
| Linux 进程能力 | `pcntl` 等 Webman PHP CLI 所需进程控制能力 |
| 常用功能扩展 | 按文件上传、验证码和支付插件需求准备 `curl`、`fileinfo`、`gd`、`bcmath`、`simplexml`；实际以所用功能要求为准 |

开发环境可以直接使用 PHP CLI 启动；生产环境建议使用 Nginx 或 Apache 反向代理到 Webman HTTP 服务。
具体要求可以看webman官方教程：**[宝塔安装](https://www.workerman.net/doc/webman/bt-install.html)**，**[nginx代理](https://www.workerman.net/doc/webman/others/nginx-proxy.html)**

## 📝 安装说明

**MPAY V2 面向实际部署提供发行版安装包。正式安装建议从 Gitee 发行版下载，不建议直接用源码仓库作为生产安装包。**

发行版安装包结构：

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
| PHP | 当前已核验发行包要求 PHP 8.2+ |
| MySQL | 教程基线 MySQL 8.0，提前准备数据库及用户 |
| Redis | 教程基线 Redis 7，用于缓存、登录态、队列和运行时任务 |
| 程序依赖 | 发行版已包含 `vendor`，通常不需要在服务器单独安装依赖 |
| PHP 扩展 | 按上方“运行环境”准备，以 PHP CLI 的实际扩展和所用插件要求为准 |

Webman 是常驻内存服务，不依赖 PHP-FPM 或 OPcache 处理请求。请使用 `php -v` 和 `php -m` 检查 PHP CLI 的实际版本和扩展。

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

# 调试方式运行（用于开发调试，打印数据会显示在终端，终端关闭后webman服务也随之关闭）
php webman start

# 守护进程方式运行（用于正式环境，打印数据不会显示在终端，终端关闭后webman服务会持续运行）
php webman start -d

```

Windows 开发环境：

```bash

# 双击 windows.bat 或者终端运行 php windows.php 启动
php windows.php

```

默认监听地址：

```text
http://127.0.0.1:8787
```

生产环境使用 Linux，建议由 Supervisor、systemd 或宝塔守护进程统一管理，再通过 Nginx 或 Apache 反向代理访问。交给守护工具时，配置前台命令 `php webman start`，不加 `-d`；不要同时保留另一套手动启动的服务。

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
- 管理员账号和管理员密码；JWT 密钥由安装流程生成，不需要照抄其他站点的密钥。

安装程序会自动完成：

- 检测 PHP、目录权限、数据库和 Redis。
- 创建数据库，前提是数据库账号具备创建权限。
- 写入 `.env` 配置文件。
- 执行数据库迁移。
- 写入支付方式、系统配置、支付插件和管理员账号。
- 生成平台 ePay RSA 密钥。
- 写入安装锁。

安装完成后，通过当前使用的守护工具重启 Webman。若使用手动守护方式，则执行：

```bash
php webman restart -d
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

发行包已包含 `/admin`、`/mer` 及收银台构建产物；页面缺失时先检查安装包和解压目录。收银台通过订单生成的 `/cashier/{biz_no}` 或 `/payment/{pay_no}` 进入，打开没有单号的路径不能证明支付配置正确。

## 🧭 宝塔面板图文安装教程

下面以宝塔 Linux 面板安装 MPAY V2 发行版为例，演示从运行环境、站点创建、安装包部署、Webman 启动到安装向导完成的完整流程。示例域名、数据库名和账号仅用于演示，正式部署时请替换为自己的域名和安全密码。

### 1. 安装基础运行环境

进入宝塔面板的「软件商店」，先确认服务器已安装并启动 `Nginx`、`MySQL`、`Redis`，并安装可用的 `PHP 8.2+` CLI。当前已核验发行包的依赖要求 PHP 8.2，即使安装器旧提示允许 8.1，也不能据此降低版本。MPAY V2 使用 Webman 常驻内存运行，动态请求最终会由 Nginx 反向代理到 Webman HTTP 服务。

![宝塔面板安装基础运行环境](https://foruda.gitee.com/images/1779353289805107112/e718dc7c_12697045.png)

### 2. 安装 PHP 扩展

在 PHP 设置中进入「安装扩展」，确认当前 PHP 版本已安装 `fileinfo` 和 `redis` 扩展。`redis` 扩展用于连接 Redis 服务，`fileinfo` 用于文件类型识别和上传相关处理。

![宝塔面板安装 PHP 扩展](https://foruda.gitee.com/images/1779353308475459260/dbd65d1f_12697045.png)

### 3. 创建站点和数据库

进入「网站」页面点击「添加站点」，选择「传统项目」。填写访问域名，站点根目录建议使用 `/www/wwwroot/你的域名`；同时创建 MySQL 数据库并记录数据库名、用户名和密码。因为 Webman 不依赖 PHP-FPM 处理动态请求，PHP 版本可以选择「纯静态」，后续通过伪静态规则反向代理到 Webman 服务。

数据库密码只保存在自己的部署资料中，不公开建站表单中的凭证截图。完整操作对照 [Wiki 宝塔安装](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/03-宝塔发行包安装)。

### 4. 上传并解压发行版安装包

进入刚创建的站点目录，上传发行版附件 `mpay.zip`，然后解压到站点当前目录。旧截图中的包名只作操作示意，以实际附件为准。解压后根目录应能看到 `app`、`config`、`database`、`public`、`runtime`、`support`、`vendor`、`webman`、`start.php` 等文件和目录。

![宝塔面板上传并解压安装包](https://foruda.gitee.com/images/1779353343893590519/b7e86feb_12697045.png)

### 5. 解除必要函数禁用

在站点当前目录打开宝塔终端，执行以下命令解除 Webman 运行所需的 PHP CLI 禁用函数：

```bash
cd /www/wwwroot/项目目录
php webman fix-disable-functions

# 如果解除不成功，可以使用下面脚本再试一遍
curl -Ss https://www.workerman.net/webman/fix-disable-functions | php
```

命令会检查当前 PHP CLI 配置，并尝试启用 `exec`、`shell_exec`、`proc_open`、`pcntl_alarm`、`pcntl_fork`、`pcntl_signal`、`pcntl_signal_dispatch` 等函数。执行完成后如有提示，请按宝塔或 PHP 配置要求重载对应服务。

![宝塔面板解除 PHP 禁用函数](https://foruda.gitee.com/images/1779353361914885224/fa7a7bf8_12697045.png)

### 6. 启动 Webman 服务

正式运行优先在宝塔守护工具中使用前台命令 `php webman start`，不加 `-d`。以下为未使用守护工具时的手动后台启动方式，二者选择一种：

```bash
php webman start -d
```

看到 `Start success` 且监听地址为 `http://0.0.0.0:8787` 时，说明 Webman 服务已经启动。后续访问域名时，Nginx 会把动态请求转发到该端口。

![宝塔面板启动 Webman 服务](https://foruda.gitee.com/images/1779353385726246291/2bc0d68a_12697045.png)

### 7. 设置网站运行目录

进入站点设置的「网站目录」，将「运行目录」设置为 `/public` 并保存。这样静态资源、首页、安装页和前端构建产物会从 `public` 目录对外提供。

![宝塔面板设置网站运行目录](https://foruda.gitee.com/images/1779353405855345417/42eacf7e_12697045.png)

### 8. 配置伪静态反向代理

进入站点设置的「伪静态」，写入下面与 Wiki 一致的规则。真实静态文件由 Nginx 返回，其他请求交给 Webman；三个 ePay V1 `.php` 路由单独保留原 URI，其他 `.php` 请求不作为站点文件执行。不要同时保留另一套反向代理或 PHP-FPM 配置。

```nginx
location / {
    try_files $uri @mpay_backend;
}

# MPAY ePay V1 protocol routes, keep the original URI.
location ~ ^/(submit|mapi|api)\.php$ {
    proxy_set_header Host $http_host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_pass http://127.0.0.1:8787;
}

location @mpay_backend {
    proxy_set_header Host $http_host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_pass http://127.0.0.1:8787;
}

location ~ \.php$ {
    return 404;
}

location ^~ /.well-known/ {
    try_files $uri =404;
}

location ~ /\. {
    return 404;
}
```

本例用于 Nginx 直接面对访问者的场景，网站根目录为 `public`。如果前面还有可信 CDN 或代理，应按实际链路配置客户端 IP，不能盲目照搬请求头设置。

配置以本节文本为准，不使用旧截图中的 URI 改写规则。保存后先检查 Nginx 配置，再重载站点；更多说明见 [Wiki 宝塔安装](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/03-宝塔发行包安装)。

### 9. 打开安装入口

浏览器访问站点域名，未安装时首页会显示系统状态为「未安装」。点击「开始安装」，或直接访问 `/install` 进入安装向导。

![MPAY V2 未安装首页](https://foruda.gitee.com/images/1779353444389677137/570d36ba_12697045.png)

### 10. 填写基础配置

安装向导会依次完成协议确认、环境检测和基础配置。基础配置中需要填写平台名称、站点 URL、MySQL 连接信息、Redis 连接信息和初始管理员信息。Redis 默认没有密码时可以留空；如果服务器设置了 Redis 密码，则按实际密码填写。

![MPAY V2 安装向导基础配置](https://foruda.gitee.com/images/1779353469746411588/b092ed1e_12697045.png)

### 11. 进入管理后台检查

安装完成后，通过实际使用的宝塔/Supervisor/systemd 守护工具重启服务。未使用守护工具、而是手动后台启动时，执行：

```bash
php webman restart -d
```

然后访问 `/admin` 进入管理后台。运营首页能正常打开，并显示运行健康、交易统计、运维告警和快捷入口，说明管理端页面和后台 API 已经连通。

![MPAY V2 管理后台首页](https://foruda.gitee.com/images/1779353490103183099/b6ec6f14_12697045.png)

### 12. 进入商户后台验证

访问 `/mer` 可进入商户后台。首次上线后建议在管理后台补齐商户资料、接口凭证、插件配置、支付通道和路由策略，再使用商户后台验证订单、退款、资金流水和通道配置是否符合预期。

![MPAY V2 商户后台首页](https://foruda.gitee.com/images/1779353507701324783/ac64a892_12697045.png)

## 🚪 访问入口

| 场景 | 页面入口 | API 入口 |
| --- | --- | --- |
| 首页 | `/`、`/home` | 无 |
| 安装向导 | `/install` | `/adminapi/install/*` |
| 内置接口文档页 | `/docs` | 无 |
| 管理后台 | `/admin`，需存在 `public/admin/index.html` | `/adminapi` |
| 商户后台 | `/mer`，需存在 `public/mer/index.html` | `/merapi` |
| 收银台 | `/cashier/{biz_no}`、`/payment/{pay_no}`，由订单生成，需存在 `public/cashier/index.html` | `/api/cashier`、`/api/pay` |
| ePay V1 | `/submit.php`、`/mapi.php`、`/api.php` | 同左 |
| ePay V2 | 无固定页面 | `/api/pay`、`/api/merchant`、`/api/transfer` |
| 通道级通知 | 无页面 | `/api/pay/{chanId}/notify` |

如果对应前端构建产物不存在，页面入口会返回 `Admin page not found`、`Merchant page not found` 或 `Cashier page not found`。这不影响后端 API 使用。

## ⌨️ 常用命令

下面是独立操作示例，不要整段复制执行。宝塔、Supervisor 或 systemd 托管的进程优先通过对应工具管理；Docker 服务通过安装目录的 Compose 管理，不在容器内再启动第二个守护进程。

### 日常启停与状态

```bash
# 查看进程状态
php webman status
# 持续查看状态详情
php webman status -d

# 独立前台运行；守护工具也应使用前台模式
php webman start
# 没有使用守护工具时，可选择手动后台运行
php webman start -d

# 平滑停止；直接停止命令为 php webman stop
php webman stop -g

# 仅适用于允许 reload 的进程，不等同完整升级重启
php webman reload -g

# 手动后台运行方式下，完整重启并保持后台模式
php webman restart -d

# Windows 开发环境启动
php windows.php
```

`payment-runtime`、`receipt-watcher` 等维护进程不能仅凭一次 `reload` 就认定已加载新代码。升级或修改环境配置时按 [升级备份与回滚](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/22-升级备份与回滚) 执行受控重启，不承诺所有更新都能无停机热加载。

### 升级维护与通知处理

| 命令 | 用途与注意事项 |
| --- | --- |
| `php webman migrate:status` | 查看迁移状态 |
| `php webman migrate` | 按版本执行迁移，会改动数据库；先备份并阅读升级说明 |
| `php webman system:config-sync` | 同步系统配置定义及缓存，不等于迁移数据库；以当前版本命令说明为准 |
| `php webman payment:notify-retry --limit=10` | 将到期商户通知重新入队，可能产生外部通知，不是只读诊断 |

### 开发环境专用

**以下命令只在独立开发环境使用，不作为普通安装步骤。** 纯净发行包可能不包含测试命令，先用 `php webman list` 与对应 `help` 确认；不带 `--live` 也不代表全部命令只读。

| 命令 | 用途与副作用 |
| --- | --- |
| `php webman mpay:test --all` | 服务/方法检查；参数可能改变执行范围，不等于真实支付闭环 |
| `php webman mpay:p0-check` | 可能写入商户、通道、订单及余额等测试数据，只用于专用库 |
| `php webman epay:mapi` / `php webman epay:v2-api` | 接口联调；先核对参数与是否会访问真实通道 |
| `php webman epay:v2-bootstrap` | 开发凭证初始化，可能写密钥和接口凭证；已接入环境不要随意执行 |
| `php webman epay:mock-chain` | 写入模拟商户、凭证、交易和通知任务，只用于隔离环境 |

开发准备、命令范围与隔离要求见 [开发环境与代码结构](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/14-开发环境与代码结构)。

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

创建支付订单的报文结构示例如下，不能原样作为可用请求执行。`pid`、订单号和通知地址换成自己的值，`timestamp` 使用当前时间，`sign` 按自己的商户私钥重新计算：

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

返回结构会根据插件能力不同返回二维码内容、跳转链接、HTML、URL Scheme、JSAPI 参数或身份授权地址。签名规则和完整接入步骤见 [商户接入指南](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/11-商户接入指南)，不要把示例签名或旧时间戳直接用于真实请求。

## 🧩 支付插件

支付插件位于：

```text
app/common/payment
```

典型插件与场景如下，**不是完整插件清单，也不表示每个发行包均已包含**：

| 类型 | 代表插件编码 | 说明 |
| --- | --- | --- |
| 官方 API | `alipay_api`、`wechat_api` | 使用对应应用、商户权限、密钥或证书 |
| ePay 上游 | `epay_v1`、`epay_v2` | 对接其他 ePay 平台，不与商户调用 MPAY 的接口凭证混用 |
| 个人收款通知 | `alipay_receipt`、`wxpay_receipt` | 按支持的手机通知或公众号消息确认收款 |
| 银行公众号通知 | `bank_of_china_receipt` 等 | 使用支持的银行入账消息；不是通用银行 API |
| 已有码牌/收款单 | `shouqianba_receipt`、`postar_direct_receipt` 等 | 先限定商户、门店及码牌，再匹配成功流水 |
| 动态码/每单收款单 | `tianque_pretran_receipt`、`lakala_jfy_receipt` 等 | 每单生成付款入口，确认协议因插件而异；Pretran 也提供静态模式 |
| 账单与链上监听 | `alipay_bill_receipt`、`usdt_trc20_receipt` | 使用 Go 直连工具查询账单或链上流水 |

完整编码、支付方式、查询/退款限制、监听授权及发行包差异统一见 [功能与插件能力清单](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/02-功能与插件能力清单)。例如 Wiki 对应基线中的付呗收款单、易宝易缴费收款单已在开发源码实现，但不包含在该次核对的 `2.1.1/mpay.zip` 中，使用前需确认实际安装包和配套镜像。

插件常用方法：

| 方法 | 职责 |
| --- | --- |
| `pay()` | 返回完整标准支付结果；待支付时包含支付呈现信息 |
| `query()` | 按插件能力查询或返回待确认状态；监听类不等于主动访问上游查单 |
| `notify()` | 处理上游支付回调 |
| `refund()` | 发起退款 |
| `queryRefund()`（可选） | 实现 `RefundQueryInterface` 的插件查询退款状态 |
| `close()` | 按通道能力处理关闭；本地关闭不等于上游停止收款 |
| 转账能力（可选） | 由 `TransferPluginInterface` 声明；不是所有支付插件都具备 |
| `channelNotify()` / `channelNotifyPayload()` | 通道级通知先定位平台支付单 |

插件 `pay()` 直接返回完整标准结果，核心不根据任意上游字段猜测状态。`status` 使用 `pending` 或 `success`；待支付结果包含 `presentation`，明确成功结果需要可信的整数分 `paid_amount` 和精确渠道引用。以下是插件内部 `presentation.pay_page` 类型，不是 ePay 响应字段别名：

| 类型 | 说明 |
| --- | --- |
| `qrcode` | 二维码内容，适合扫码支付 |
| `jump` | GET 跳转或明确的 POST 表单承接 |
| `html` | 上游返回的 HTML 表单或页面片段 |
| `urlscheme` | App URL Scheme |
| `jsapi` | 公众号、小程序或 JSAPI 支付参数 |
| `page` | 当前收银台已注册的专用承接组件 |

缺少 OpenID、buyer_id 等身份时走统一身份授权流程，`identity` 不是上述页面类型。字段、帮助方法、异常与完整示例见 [支付插件开发](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/16-支付插件开发)，不能把返回二维码等同于支付成功。

## 🛣️ 支付路由模型

平台通道通常按下面的数据关系组织：

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

商户自建通道还需按商户“通道选择”设置来源和偏好，不要求照搬平台分组编排；完整配置见 [完成第一笔收款](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/06-完成第一笔收款) 与 [商户后台指南](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/08-商户后台使用指南)。

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
| `receipt-watcher` | 刷新监听账号、同步待支付订单并向四条 watcher Stream 投放任务 |
| `merchant_notify` | 商户通知投递 |
| `refund_dispatch` | 退款上游派发 |
| `transfer_dispatch` | 转账上游派发 |
| `transfer_query` | 转账延迟查单 |
| `settlement_complete` | 清算自动入账 |
| `receipt_flow_notify` | 网页流水监听通知处理 |

Linux 生产环境使用 `php webman start` 会按 Webman 配置启动相关进程。Windows 开发环境请使用 `php windows.php`。

## 👀 网页流水监听

网页流水监听适用于第三方平台没有标准回调，但能通过接口或商户后台查询收款流水的场景。生产运行分为 Go 直连和 Python Chromium 两个独立 watcher。

职责边界：

- Webman 后端负责维护账号、订单快照、预登录到期表、运行时路由和订单匹配。
- Go `receipt-watcher-direct` 消费直连查单与预登录 Stream，优先承载可以稳定直连接口的平台。
- Python `receipt-watcher-browser` 只消费浏览器查单与预登录 Stream，承载真正依赖 Chromium 的平台。
- 两个 watcher 都只查询、归一化并投递流水，不访问业务数据库、不修改支付单。
- 流水进入官方 `redis-queue` 的 `receipt_flow_notify` 后，再由 Webman 调用精确支付插件完成订单定位和支付确认。

支付插件通过 `$paymentInfo['receipt_watcher']` 固定声明 `runtime=direct|browser` 和 `prelogin_supported`。同一平台可以保留直连版与浏览器版，后台选择哪个精确插件编码，就只投放到对应运行时；系统不自动 fallback，也不建议同一上游账号同时启用两种实现。

四条 Stream、当前插件映射、Redis Session、授权和预登录口径见 [监听适配器开发](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/17-监听适配器开发)。部署时按 [监听工具安装](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/05-监听工具安装) 选择直连版或浏览器版。旧 `watcher` 项目只保留历史调试资料，不作为 v2 新插件开发入口。

## 🚀 部署建议

### Nginx 反向代理伪静态配置

完整规则统一使用前文“宝塔面板图文安装教程”的第 8 步，或 [Wiki 宝塔安装](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/03-宝塔发行包安装)。这里不维护第二份容易产生差异的配置。

Docker 可按 [Docker 安装](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/04-Docker安装) 选择整站反向代理或静态文件优先；Apache 示例见 [运行监控与故障排查](https://gitee.com/technical-laohu/mpay_v2_webman/wikis/23-运行监控与故障排查)。

`/submit.php`、`/mapi.php`、`/api.php` 是 ePay V1 兼容入口，由 Webman 路由直接承接。不要再把它们改写成无后缀路径，也不要为当前 Webman 站点额外配置 PHP-FPM 的 `.php` location。


生产环境建议：

- 使用 HTTPS。
- 使用 Supervisor、systemd 或宝塔守护进程保持 Webman 常驻。
- 定期备份 MySQL 和重要配置。
- `runtime/`、`public/storage/` 需要可写。
- 生产环境关闭调试输出，检查日志权限。
- 支付回调地址、商户通知地址、站点 URL 必须使用公网可访问域名。

## 🔐 安全注意事项

- 使用安装向导创建的独立管理员账号与强密码，检查预置测试商户及其关联配置，不沿用公开演示站账号。
- 核对 JWT、平台和商户密钥由自己的环境生成并妥善保管，替换示例、共享或泄露的凭证。已接入站点轮换密钥时同步更新调用方；不要在升级时无条件重新生成全部密钥。
- 不要把 `.env`、证书、私钥、上游支付密钥提交到公开仓库。
- 商户开放 API 凭证只用于接口签名，不等同于商户后台登录密码。
- 管理后台、商户后台和开放 API 是三套独立鉴权体系。
- 个人收款监听、网页流水监听、代收和清算业务需要自行确认合规边界。

## ❤️ 致谢

MPAY V2 的开发离不开以下优秀开源项目。感谢这些项目的作者、维护者和所有贡献者，为开源社区提供了可靠的基础设施与开发工具。

| 项目 | 对 MPAY 的帮助 | 项目地址 |
| --- | --- | --- |
| Webman | MPAY 后端核心框架，提供路由、中间件、自定义进程和插件机制 | [官网](https://www.workerman.net/doc/webman/) · [GitHub](https://github.com/walkor/webman) · [Gitee](https://gitee.com/walkor/webman) |
| Workerman | 为 Webman 提供常驻内存、事件驱动和多进程运行基础 | [GitHub](https://github.com/walkor/workerman) |
| SnowAdmin | 管理后台和商户后台前端以此为基础进行业务化改造 | [GitHub](https://github.com/WangFan-io/SnowAdmin) · [Gitee](https://gitee.com/wang_fan_w/SnowAdmin) |
| Arco Design Vue | 为后台页面提供 UI 组件和交互设计体系 | [官网](https://arco.design/vue) · [GitHub](https://github.com/arco-design/arco-design-vue) |
| form-create | 用于支付插件配置、商户进件配置等动态表单场景 | [官网](https://www.form-create.com/) · [GitHub](https://github.com/xaboy/form-create) |
| Vue.js | 为管理后台、商户后台和收银台提供前端基础能力 | [官网](https://vuejs.org/) · [GitHub](https://github.com/vuejs/core) |

如果 MPAY V2 对你有所帮助，也欢迎关注并支持这些优秀的上游开源项目。

## 📄 许可证

仓库内包含 `LICENSE` 文件。使用、二次开发、商用发布和插件分发前，请确认当前仓库许可证、业务代码授权边界和第三方 SDK 的授权要求。
