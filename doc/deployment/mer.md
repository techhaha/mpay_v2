# 商户后台部署

命令默认在 `mer/` 目录执行。

```bash
pnpm install
pnpm build:prod
```

产物在 `dist/`。

## 路径

- 开发公共路径：`/mer`
- 生产默认公共路径：`/`
- 生产接口基址：`VITE_APP_BASE_URL=/`，运行时请求 `/merapi`

部署到子路径时，同步调整 `VITE_PUBLIC_PATH` 和网关重写规则。
