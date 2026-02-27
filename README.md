

# MPay V2 Webman

基于 Webman 框架开发的支付系统后台管理 API。

## 项目简介

MPay V2 Webman 是一个采用 Webman 高性能 PHP 框架构建的支付系统后台管理接口，提供用户认证、权限管理、系统配置、菜单路由等核心功能。

## 技术栈

- **后端框架**: Webman
- **PHP 版本**: PHP 8.0+
- **数据库**: MySQL
- **缓存**: Redis
- **认证**: JWT

## 项目结构

```
app/
├── command/          # 命令行控制器
├── common/           # 公共基础类
│   ├── base/         # 基础类（Controller、Model、Service、Repository）
│   ├── constants/    # 常量定义
│   ├── enums/        # 枚举类
│   ├── middleware/   # 中间件
│   └── utils/        # 工具类
├── events/           # 事件类
├── exceptions/       # 异常类
├── http/             # HTTP 控制器
│   └── admin/        # 后台管理接口
│       ├── controller/  # 控制器
│       └── middleware/  # 中间件
├── models/           # 数据模型
├── process/          # 进程管理
├── repositories/     # 数据仓库
├── services/         # 业务服务
└── validation/       # 验证器
config/               # 配置文件
database/             # 数据库脚本
doc/                  # 文档
public/               # 公共资源
support/              # 框架支持类
```

## 核心功能

### 认证模块
- 用户登录/登出
- 验证码生成与验证
- JWT Token 认证
- 用户信息获取

### 权限管理
- 菜单路由管理
- 用户角色关联

### 系统配置
- 系统参数配置
- 字典管理
- 表单配置
- 配置缓存管理

### 中间件
- CORS 跨域处理
- 静态文件处理
- 认证鉴权

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
mysql -u用户名 -p 数据库名 < database/ma_system_config.sql
```

6. 启动服务
```bash
# Linux/Mac
php start.php start

# Windows
windows.bat
```

## API 接口

### 认证接口

| 接口 | 方法 | 描述 |
|------|------|------|
| `/admin/auth/captcha` | GET | 获取验证码 |
| `/admin/auth/login` | POST | 用户登录 |

### 用户接口

| 接口 | 方法 | 描述 |
|------|------|------|
| `/admin/user/info` | GET | 获取当前用户信息 |

### 菜单接口

| 接口 | 方法 | 描述 |
|------|------|------|
| `/admin/menu/routers` | GET | 获取菜单路由 |

### 系统接口

| 接口 | 方法 | 描述 |
|------|------|------|
| `/admin/system/dict/{code}` | GET | 获取字典数据 |
| `/admin/system/tabs` | GET | 获取标签页配置 |
| `/admin/system/config/{tabKey}` | GET/POST | 获取/提交表单配置 |

## 异常处理

项目定义了以下自定义异常类：

- `BadRequestException` - 请求参数错误 (400)
- `UnauthorizedException` - 未授权 (401)
- `ForbiddenException` - 禁止访问 (403)
- `NotFoundException` - 资源不存在 (404)
- `ValidationException` - 参数校验失败 (422)
- `InternalServerException` - 系统内部错误 (500)

## 配置说明

主要配置文件位于 `config/` 目录：

- `app.php` - 应用配置
- `database.php` - 数据库配置
- `redis.php` - Redis 配置
- `jwt.php` - JWT 配置
- `route.php` - 路由配置
- `middleware.php` - 中间件配置
- `cache.php` - 缓存配置
- `log.php` - 日志配置

## 许可证

本项目基于 MIT 许可证开源。