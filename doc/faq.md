# 常见问题

## 根目录为什么不是 Git 仓库？

根目录只是工作区容器。`admin`、`mer`、`cashier`、`mpay` 各自是独立 Git 仓库，建议打开 `MPAY_V2.code-workspace` 查看。

## 最新文档看哪里？

看 `docs/`。`mpay/doc/` 是旧资料归档，不作为最新事实源。

## 文档和代码冲突怎么办？

以当前代码、路由、前端 API 封装、环境模板和 DDL 为准，然后修正文档。

## 收银台入口到底是什么？

页面入口是 `/cashier` 和 `/payment`；收银台 JSON API 是 `/api/cashier/*`；开放支付 API 是 `/api/pay/*`。

## 后端默认端口是什么？

`config/process.php` 中 HTTP 服务默认监听 `0.0.0.0:8787`。

## 新文档写到哪里？

跨项目说明写到 `docs/`；单个项目的启动、构建、联调说明写到对应项目的 `README.md`。
