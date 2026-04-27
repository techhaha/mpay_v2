# 收银台部署

命令默认在 `cashier/` 目录执行。

```bash
pnpm install
pnpm build
```

产物在 `dist/`。

## 路径

- 页面入口：`/cashier`、`/payment`
- 收银台 JSON API：`/api/cashier`
- ePay V2 / 开放支付 API：`/api/pay`、`/api/merchant`、`/api/transfer`
- ePay V1 兼容入口：`/submit.php`、`/mapi.php`、`/api.php`

如果前端不走同域代理，配置 `VITE_API_BASE_URL` 指向后端；否则保持为空，通过相对路径访问 `/api`。
