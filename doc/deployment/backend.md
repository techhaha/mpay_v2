# 后端部署

命令默认在 `mpay/` 目录执行。

## 前置条件

- PHP 8.1+
- Composer
- MySQL
- Redis

## 启动

```bash
composer install
Copy-Item .env.example .env
php webman start
```

Windows 开发环境需要同时启动自定义进程时：

```bash
php windows.php
```

## 生产要点

- 配置数据库、Redis、JWT 密钥和支付运行时心跳。
- 使用进程管理工具托管 `webman` 和 `payment-runtime`。
- 执行 `php webman system:config-sync` 同步系统配置默认值。
- 如使用 OSS/COS，先在系统配置中补齐存储参数。
