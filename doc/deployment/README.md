# 部署总说明

四个项目独立启动、构建和发布。后端负责 API 与页面兜底路由，前端负责生成静态产物。

## 命令速览

| 项目 | 开发/启动 | 构建 |
| --- | --- | --- |
| `mpay` | `php webman start` 或 `php windows.php` | 无前端构建 |
| `admin` | `pnpm dev` | `pnpm build:prod` |
| `mer` | `pnpm dev` | `pnpm build:prod` |
| `cashier` | `pnpm dev` | `pnpm build` |

## 默认端口与路径

- 后端默认监听 `http://127.0.0.1:8787`。
- 管理后台页面入口是 `/admin`，接口是 `/adminapi`。
- 商户后台页面入口是 `/mer`，接口是 `/merapi`。
- 收银台页面入口是 `/cashier`、`/payment`，接口是 `/api/cashier` 和 `/api/pay`。

## 部署建议

- 后端生产环境使用守护进程或进程管理工具托管。
- `admin`、`mer`、`cashier` 的 `dist/` 可独立托管，也可发布到 `mpay/public` 下对应目录。
- 如果前后端同域部署，确保 `/adminapi`、`/merapi`、`/api`、ePay V1 兼容入口都能转发到 `mpay`。
- 环境变量说明见 [env.md](./env.md)。
