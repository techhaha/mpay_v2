# 前端总说明

当前有三套独立前端，均可单独安装、启动、构建和发布。

| 项目 | 技术栈 | 页面入口 | API 前缀 | 主要职责 |
| --- | --- | --- | --- | --- |
| `admin` | Vue 3 + Vite + Arco Design | `/admin` | `/adminapi` | 平台运营与配置 |
| `mer` | Vue 3 + Vite + Arco Design | `/mer` | `/merapi` | 商户自助后台 |
| `cashier` | Vue 3 + Vite + Tailwind CSS | `/cashier`、`/payment` | `/api/cashier` | 用户侧收银台 |

## 共性

- 每个前端都有自己的 `package.json` 和环境文件。
- `admin`、`mer` 的 axios 实例会在 `VITE_APP_BASE_URL` 后拼接 `/adminapi` 或 `/merapi`。
- `cashier` 的 axios 实例使用 `VITE_API_BASE_URL` 作为基址，并在请求路径中拼接 `VITE_API_PREFIX`，默认是 `/api`。
- 本地开发默认代理或直连 `http://127.0.0.1:8787`。

## 命令

| 项目 | 开发 | 构建 | 预览 |
| --- | --- | --- | --- |
| `admin` | `pnpm dev` | `pnpm build:dev` / `pnpm build:prod` / `pnpm build:test` | `pnpm preview` |
| `mer` | `pnpm dev` | `pnpm build:dev` / `pnpm build:prod` / `pnpm build:test` | `pnpm preview` |
| `cashier` | `pnpm dev` | `pnpm build` | `pnpm preview` |

## 阅读入口

- [管理后台前端](./admin.md)
- [商户后台前端](./mer.md)
- [收银台前端](./cashier.md)
- [接口总说明](../api/README.md)
- [部署总说明](../deployment/README.md)
