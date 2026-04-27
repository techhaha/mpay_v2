# 环境变量

本文只列当前代码读取或模板中声明的关键变量，不记录敏感值。

## 后端 `mpay`

环境文件：

- `.env`
- `.env.example`

关键变量：

| 变量 | 用途 |
| --- | --- |
| `DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD` | MySQL 连接 |
| `REDIS_HOST`、`REDIS_PORT`、`REDIS_PASSWORD`、`REDIS_DATABASE` | Redis 连接 |
| `CACHE_DRIVER` | 缓存驱动 |
| `AUTH_JWT_ISSUER`、`AUTH_JWT_LEEWAY` | JWT 通用参数 |
| `AUTH_ADMIN_JWT_SECRET`、`AUTH_ADMIN_JWT_TTL`、`AUTH_ADMIN_JWT_REDIS_PREFIX` | 管理后台登录 token |
| `AUTH_MERCHANT_JWT_SECRET`、`AUTH_MERCHANT_JWT_TTL`、`AUTH_MERCHANT_JWT_REDIS_PREFIX` | 商户后台登录 token |
| `PAY_RUNTIME_HEARTBEAT_SECONDS` | 支付运行时进程心跳间隔 |

系统配置、站点 URL、支付运行时开关和存储参数主要由 `config/system_config.php` 定义，并可同步到数据库。

## 管理后台 `admin`

环境文件：

- `.env`
- `.env.development`
- `.env.production`
- `.env.test`

关键变量：

| 变量 | 用途 |
| --- | --- |
| `VITE_USER_NODE_ENV` | 环境标识 |
| `VITE_ROUTER_MODE` | 路由模式，开发默认为 `history`，生产默认为 `hash` |
| `VITE_PUBLIC_PATH` | 构建公共路径，开发默认为 `/admin` |
| `VITE_APP_BASE_URL` | 后端基址，前端会拼接 `/adminapi` |
| `VITE_APP_OPEN_MOCK` | 是否使用本地 mock，代码中有读取 |

## 商户后台 `mer`

环境文件和变量与 `admin` 基本一致；前端会在 `VITE_APP_BASE_URL` 后拼接 `/merapi`。

## 收银台 `cashier`

环境文件：

- `.env`
- `.env.example`

关键变量：

| 变量 | 用途 |
| --- | --- |
| `VITE_USE_MOCK` | 是否启用 mock 演示 |
| `VITE_API_PREFIX` | API 路径前缀，默认 `api` |
| `VITE_API_PROXY_TARGET` | Vite 开发代理目标，默认 `http://127.0.0.1:8787` |
| `VITE_API_BASE_URL` | 直连后端 API 基址，留空则走相对路径/开发代理 |

注意：`cashier/.env.example` 中仍有 `VITE_ROUTE_PREFIX`，但当前 `cashier/src/config/index.ts` 未读取它；页面路由前缀实际固定为 `/cashier` 和 `/payment`。
