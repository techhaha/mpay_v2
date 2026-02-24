# MPAY V2 项目技术栈与结构文档

## 1. 项目概述

MPAY V2 是一个基于 Webman 后端框架和 Vue 3 前端框架的支付管理系统，提供完整的支付业务管理功能，包括用户认证、菜单管理、系统配置、财务管理、渠道管理和数据分析等核心模块。

## 2. 技术架构

### 2.1 后端技术栈

| 类别 | 技术/框架 | 版本 | 用途 | 来源 |
|------|-----------|------|------|------|
| 基础框架 | Webman | ^2.1 | 高性能HTTP服务框架 | composer.json:28 |
| PHP版本 | PHP | >=8.1 | 开发语言 | composer.json:27 |
| 数据库 | webman/database | ^2.1 | 数据库操作 | composer.json:31 |
| 缓存 | Redis | ^2.1 | 缓存存储 | composer.json:32 |
| 缓存 | webman/cache | ^2.1 | 缓存管理 | composer.json:34 |
| 认证 | JWT | ^7.0 | 用户认证 | composer.json:42 |
| 验证码 | webman/captcha | ^1.0 | 登录验证码 | composer.json:37 |
| 事件系统 | webman/event | ^1.0 | 事件管理 | composer.json:38 |
| 配置管理 | vlucas/phpdotenv | ^5.6 | 环境变量 | composer.json:39 |
| 定时任务 | workerman/crontab | ^1.0 | 定时任务 | composer.json:40 |
| 队列 | webman/redis-queue | ^2.1 | 消息队列 | composer.json:41 |
| 验证 | topthink/think-validate | ^3.0 | 数据验证 | composer.json:36 |
| 容器 | php-di/php-di | 7.0 | 依赖注入 | composer.json:30 |
| 日志 | monolog/monolog | ^2.0 | 日志管理 | composer.json:29 |
| 控制台 | webman/console | ^2.1 | 命令行工具 | composer.json:35 |

### 2.2 前端技术栈

| 类别 | 技术/框架 | 版本 | 用途 | 来源 |
|------|-----------|------|------|------|
| 基础框架 | Vue | ^3.5.15 | 前端框架 | package.json:61 |
| 语言 | TypeScript | ^5.2.2 | 开发语言 | package.json:103 |
| 构建工具 | Vite | ^6.3.5 | 构建工具 | package.json:107 |
| UI框架 | Arco Design | ^2.57.0 | 界面组件库 | package.json:72 |
| 状态管理 | Pinia | ^2.3.0 | 状态管理 | package.json:53 |
| 路由 | Vue Router | ^4.3.0 | 前端路由 | package.json:66 |
| HTTP客户端 | Axios | ^1.6.8 | API调用 | package.json:47 |
| 表单生成 | @form-create/arco-design | ^3.2.37 | 动态表单 | package.json:41 |
| 图表 | @visactor/vchart | ^1.11.0 | 数据可视化 | package.json:42 |
| 代码编辑器 | CodeMirror | ^6.0.1 | 代码编辑 | package.json:48 |
| 富文本编辑器 | @wangeditor/editor | ^5.1.23 | 内容编辑 | package.json:45 |
| 国际化 | vue-i18n | 10.0.0-alpha.3 | 多语言支持 | package.json:64 |
| 工具库 | @vueuse/core | ^12.4.0 | 实用工具 | package.json:44 |
| 指纹识别 | @fingerprintjs/fingerprintjs | ^4.6.2 | 设备识别 | package.json:40 |
| 二维码 | qrcode | ^1.5.4 | 二维码生成 | package.json:57 |
| 条码 | jsbarcode | ^3.11.6 | 条码生成 | package.json:51 |
| 打印 | print-js | ^1.6.0 | 页面打印 | package.json:56 |
| 进度条 | nprogress | ^0.2.0 | 加载进度 | package.json:52 |
| 中文转拼音 | pinyin-pro | ^3.26.0 | 拼音转换 | package.json:55 |
| 引导 | driver.js | ^1.3.1 | 功能引导 | package.json:49 |

## 3. 项目结构

### 3.1 后端目录结构

```
d:\phpstudy_pro\WWW\mpay\mpay_v2_webman\
├── app/                    # 应用代码
│   ├── common/             # 通用代码
│   │   ├── base/           # 基础类
│   │   │   ├── BaseController.php
│   │   │   ├── BaseModel.php
│   │   │   ├── BaseRepository.php
│   │   │   └── BaseService.php
│   │   ├── constants/      # 常量
│   │   │   └── YesNo.php
│   │   ├── enums/          # 枚举
│   │   │   └── MenuType.php
│   │   ├── middleware/     # 中间件
│   │   │   ├── Cors.php
│   │   │   └── StaticFile.php
│   │   └── utils/          # 工具类
│   │       └── JwtUtil.php
│   ├── events/             # 事件
│   │   └── SystemConfig.php
│   ├── exceptions/         # 异常处理
│   │   └── ValidationException.php
│   ├── http/               # HTTP相关
│   │   ├── admin/          # 后台管理
│   │   │   ├── controller/ # 控制器
│   │   │   │   ├── AuthController.php
│   │   │   │   ├── MenuController.php
│   │   │   │   ├── SystemController.php
│   │   │   │   └── UserController.php
│   │   │   └── middleware/ # 中间件
│   │   │       └── AuthMiddleware.php
│   ├── models/             # 数据模型
│   │   ├── SystemConfig.php
│   │   └── User.php
│   ├── process/            # 进程管理
│   │   ├── Http.php
│   │   └── Monitor.php
│   ├── repositories/       # 数据仓库
│   │   ├── SystemConfigRepository.php
│   │   └── UserRepository.php
│   ├── routes/             # 路由配置
│   │   ├── admin.php
│   │   ├── api.php
│   │   └── mer.php
│   ├── services/           # 业务逻辑
│   │   ├── AuthService.php
│   │   ├── CaptchaService.php
│   │   ├── MenuService.php
│   │   ├── SystemConfigService.php
│   │   ├── SystemSettingService.php
│   │   └── UserService.php
│   └── validation/         # 数据验证
│       └── SystemConfigValidator.php
├── config/                 # 配置文件
│   ├── base-config/        # 基础配置
│   │   ├── basic.json
│   │   ├── email.json
│   │   ├── permission.json
│   │   └── tabs.json
│   ├── plugin/             # 插件配置
│   │   ├── webman/
│   │   │   ├── console/
│   │   │   ├── event/
│   │   │   ├── redis-queue/
│   │   │   └── validation/
│   ├── system-file/        # 系统文件
│   │   ├── dict.json
│   │   ├── menu.json
│   │   └── menu.md
│   ├── app.php
│   ├── autoload.php
│   ├── bootstrap.php
│   ├── cache.php
│   ├── container.php
│   ├── database.php
│   ├── dependence.php
│   ├── event.php
│   ├── exception.php
│   ├── jwt.php
│   ├── log.php
│   ├── menu.php
│   ├── middleware.php
│   ├── process.php
│   ├── redis.php
│   ├── route.php
│   ├── server.php
│   ├── session.php
│   ├── static.php
│   ├── translation.php
│   └── view.php
├── database/               # 数据库文件
│   └── ma_system_config.sql
├── doc/                    # 文档
│   ├── event.md
│   └── exception.md
├── public/                 # 静态资源
│   └── favicon.ico
├── resource/               # 资源文件
│   └── mpay_v2_admin/      # 前端项目
├── .env                    # 环境变量
├── composer.json           # PHP依赖
└── composer.lock           # 依赖锁定
```

### 3.2 前端目录结构

```
d:\phpstudy_pro\WWW\mpay\mpay_v2_webman\resource\mpay_v2_admin\
├── src/                    # 源代码
│   ├── api/                # API调用
│   ├── assets/             # 静态资源
│   ├── components/         # 组件
│   ├── config/             # 配置
│   ├── directives/         # 指令
│   ├── hooks/              # 钩子
│   ├── lang/               # 国际化
│   ├── layout/             # 布局
│   ├── mock/               # 模拟数据
│   ├── router/             # 路由
│   ├── store/              # 状态管理
│   ├── style/              # 样式
│   ├── typings/            # 类型定义
│   ├── utils/              # 工具函数
│   ├── views/              # 页面
│   ├── App.vue             # 根组件
│   ├── auto-import.d.ts    # 自动导入
│   ├── components.d.ts     # 组件声明
│   ├── main.ts             # 入口文件
│   └── style.css           # 全局样式
├── build/                  # 构建配置
│   ├── optimize.ts
│   └── vite-plugin.ts
├── .env                    # 环境变量
├── .env.development        # 开发环境变量
├── .env.production         # 生产环境变量
├── .env.test               # 测试环境变量
├── eslint.config.js        # ESLint配置
├── index.html              # HTML模板
├── package.json            # 前端依赖
└── vite.config.ts          # Vite配置
```

## 4. 核心功能模块

### 4.1 后端核心模块

| 模块 | 主要功能 | 文件位置 | 来源 |
|------|----------|----------|------|
| 认证模块 | 用户登录、验证码生成 | app/http/admin/controller/AuthController.php | app/routes/admin.php:20-21 |
| 用户模块 | 获取用户信息 | app/http/admin/controller/UserController.php | app/routes/admin.php:26 |
| 菜单模块 | 获取路由菜单 | app/http/admin/controller/MenuController.php | app/routes/admin.php:29 |
| 系统模块 | 字典管理、配置管理 | app/http/admin/controller/SystemController.php | app/routes/admin.php:32-37 |

### 4.2 前端核心模块

| 模块 | 主要功能 | 文件位置 | 来源 |
|------|----------|----------|------|
| 布局模块 | 系统整体布局 | src/layout/ | resource/mpay_v2_admin/src/layout/ |
| 认证模块 | 登录、权限控制 | src/views/login/ | resource/mpay_v2_admin/src/views/ |
| 首页模块 | 数据概览 | src/views/home/ | resource/mpay_v2_admin/src/views/home/ |
| 财务管理 | 结算、对账、发票 | src/views/finance/ | resource/mpay_v2_admin/src/views/finance/ |
| 渠道管理 | 通道配置、支付方式 | src/views/channel/ | resource/mpay_v2_admin/src/views/channel/ |
| 数据分析 | 交易分析、商户分析 | src/views/analysis/ | resource/mpay_v2_admin/src/views/analysis/ |
| 系统设置 | 系统配置、字典管理 | src/views/system/ | resource/mpay_v2_admin/src/views/ |

## 5. API接口设计

### 5.1 认证接口

| 路径 | 方法 | 模块/文件 | 功能 | 权限 | 来源 |
|------|------|-----------|------|------|------|
| /adminapi/captcha | GET | AuthController | 获取验证码 | 无 | app/routes/admin.php:20 |
| /adminapi/login | POST | AuthController | 用户登录 | 无 | app/routes/admin.php:21 |

### 5.2 用户接口

| 路径 | 方法 | 模块/文件 | 功能 | 权限 | 来源 |
|------|------|-----------|------|------|------|
| /adminapi/user/getUserInfo | GET | UserController | 获取用户信息 | JWT | app/routes/admin.php:26 |

### 5.3 菜单接口

| 路径 | 方法 | 模块/文件 | 功能 | 权限 | 来源 |
|------|------|-----------|------|------|------|
| /adminapi/menu/getRouters | GET | MenuController | 获取路由菜单 | JWT | app/routes/admin.php:29 |

### 5.4 系统接口

| 路径 | 方法 | 模块/文件 | 功能 | 权限 | 来源 |
|------|------|-----------|------|------|------|
| /adminapi/system/getDict[/{code}] | GET | SystemController | 获取字典数据 | JWT | app/routes/admin.php:32 |
| /adminapi/system/base-config/tabs | GET | SystemController | 获取配置标签 | JWT | app/routes/admin.php:35 |
| /adminapi/system/base-config/form/{tabKey} | GET | SystemController | 获取表单配置 | JWT | app/routes/admin.php:36 |
| /adminapi/system/base-config/submit/{tabKey} | POST | SystemController | 提交配置 | JWT | app/routes/admin.php:37 |

## 6. 技术特点

### 6.1 后端特点

1. **高性能架构**：基于 Webman 框架，使用 Workerman 作为底层，支持高并发处理
2. **模块化设计**：采用分层架构，清晰分离控制器、服务、仓库和模型
3. **JWT认证**：使用 JSON Web Token 实现无状态认证
4. **中间件机制**：通过中间件实现请求拦截和权限控制
5. **Redis集成**：使用 Redis 作为缓存和队列存储
6. **事件系统**：支持事件驱动架构
7. **定时任务**：内置定时任务管理功能
8. **数据验证**：使用 think-validate 进行数据验证
9. **依赖注入**：使用 PHP-DI 实现依赖注入
10. **日志管理**：使用 Monolog 进行日志管理

### 6.2 前端特点

1. **Vue 3 + TypeScript**：使用最新的 Vue 3 组合式 API 和 TypeScript 提供类型安全
2. **Arco Design**：采用字节跳动开源的 Arco Design UI 组件库，提供美观的界面
3. **Pinia 状态管理**：使用 Pinia 替代 Vuex，提供更简洁的状态管理方案
4. **Vite 构建工具**：使用 Vite 提供快速的开发体验和优化的构建输出
5. **国际化支持**：内置多语言支持，可轻松切换语言
6. **响应式设计**：适配不同屏幕尺寸的设备
7. **丰富的功能组件**：集成多种实用组件，如二维码生成、条码生成、富文本编辑等
8. **权限控制**：基于指令的权限控制机制
9. **Mock 数据**：内置 Mock 数据，方便开发和测试

## 7. 开发流程

### 7.1 后端开发

1. **环境准备**：PHP 8.1+，Composer，MySQL，Redis
2. **依赖安装**：`composer install`
3. **配置环境**：复制 `.env.example` 为 `.env` 并配置相关参数
4. **启动服务**：`php start.php start`
5. **代码结构**：遵循 Webman 框架规范，按模块组织代码

### 7.2 前端开发

1. **环境准备**：Node.js 18.12+，PNPM 8.7+
2. **依赖安装**：`pnpm install`
3. **开发模式**：`pnpm dev`
4. **构建部署**：`pnpm build:prod`
5. **代码结构**：遵循 Vue 3 项目规范，按功能模块组织代码

## 8. 部署与配置

### 8.1 后端部署

1. **服务器要求**：Linux/Unix 系统，PHP 8.1+，MySQL 5.7+，Redis 5.0+
2. **Nginx 配置**：配置反向代理指向 Webman 服务
3. **启动方式**：
   - 开发环境：`php start.php start`
   - 生产环境：`php start.php start -d`
4. **监控管理**：可使用 Supervisor 管理进程

### 8.2 前端部署

1. **构建**：`pnpm build:prod`
2. **部署**：将 `dist` 目录部署到 Web 服务器
3. **Nginx 配置**：配置静态文件服务和路由重写

## 9. 总结

MPAY V2 项目采用现代化的技术栈和架构设计，后端使用 Webman 框架提供高性能的 API 服务，前端使用 Vue 3 + TypeScript + Arco Design 提供美观、响应式的用户界面。项目结构清晰，模块化程度高，便于维护和扩展。

核心功能覆盖了支付管理系统的主要业务场景，包括用户认证、菜单管理、系统配置、财务管理、渠道管理和数据分析等模块，为支付业务的运营和管理提供了完整的解决方案。

技术特点包括高性能架构、模块化设计、JWT认证、Redis集成、Vue 3组合式API、TypeScript类型安全、Arco Design UI组件库、Pinia状态管理、Vite构建工具等，确保了系统的稳定性、安全性和可扩展性。

该项目适合作为支付管理系统的基础框架，可根据具体业务需求进行定制和扩展。