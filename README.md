

# MPay V2 Webman

基于 Webman 高性能 PHP 框架开发的支付系统后台管理 API。

## 项目简介

MPay V2 Webman 是一个采用 Webman 高性能 PHP 框架构建的支付系统后台管理接口，提供商户管理、支付通道配置、账户资金管理、订单交易、退款结算等核心功能。系统采用分层架构设计，包含完整的认证授权体系、权限管理、支付路由、通知回调等模块。

## 技术栈

- **后端框架**: Webman
- **PHP 版本**: PHP 8.0+
- **数据库**: MySQL
- **缓存**: Redis
- **认证**: JWT
- **架构模式**: MVC + Repository + Service

## 项目结构

```
mpay_v2_webman/
├── app/                          # 应用核心代码
│   ├── command/                 # 命令行控制器
│   ├── common/                  # 公共基础类
│   │   ├── base/               # 基础类（Controller、Model、Service、Repository）
│   │   ├── constant/           # 常量定义
│   │   ├── enums/             # 枚举类
│   │   ├── interface/         # 接口定义
│   │   ├── middleware/       # 中间件
│   │   ├── payment/         # 支付通道实现
│   │   └── util/           # 工具类
│   ├── exception/              # 异常类
│   ├── http/                 # HTTP 控制器
│   │   ├── admin/            # 后台管理接口
│   │   ├── api/             # 商户API接口
│   │   └── mer/            # 商户门户接口
│   ├── listener/              # 事件监听
│   ├── model/                # 数据模型
│   ├── process/             # 进程管理
│   ├── repository/           # 数据仓库层
│   ├── route/              # 路由定义
│   ├── service/            # 业务服务层
│   └── validation/          # 验证器
├── config/                     # 配置文件
├── database/                  # 数据库脚本
├── doc/                      # 文档资源
├── public/                   # 公共资源
└── support/                  # 框架支持类
```

## 核心功能

### 商户管理
- 商户信息管理
- 商户分组配置
- 商户策略管理
- API 凭证管理

### 支付通道
- 支付通道配置
- 支付类型管理
- 支付插件管理
- 轮询通道组
- 支付路由解析

### 资金账户
- 账户余额查询
- 账户流水明细
- 冻结/解冻资金
- 账户充值/扣款

### 订单交易
- 支付订单创建
- 支付回调处理
- 订单状态管理
- 退款处理

### 结算管理
- 结算订单
- 结算周期配置
- 自动/手动结算

### 认证授权
- JWT Token 认证
- 后台用户管理
- 商户登录认证
- 权限中间件

### 系统配置
- 系统参数配置
- 字典管理
- 菜单路由
- 配置缓存

## 快速开始

### 环境要求

- PHP >= 8.0
- Composer
- Redis
- MySQL >= 5.7

### 安装步骤

1. 克隆项目
```bash
git clone https://gitee.com/technical-laohu/mpay_v2_webman.git
cd mpay_v2_webman
```

2. 安装依赖
```bash
composer install
```

3. 配置数据库
编辑 `config/database.php` 配置数据库连接信息。

4. 配置 Redis
编辑 `config/redis.php` 配置 Redis 连接信息。

5. 导入数据库
```bash
mysql -u用户名 -p 数据库名