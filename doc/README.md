# 文档中心

`docs/` 是 `MPAY_V2` 工作区的统一文档入口。文档只记录当前代码和配置能支撑的事实；历史资料保留在 `mpay/doc/`，不作为最新口径。

## 快速阅读

1. [总入口](../README.md)
2. [文档索引](./INDEX.md)
3. [项目总览](./overview.md)
4. [架构与请求流](./architecture.md)
5. [后端说明](./backend/README.md)
6. [前端说明](./frontend/README.md)
7. [接口说明](./api/README.md)
8. [部署说明](./deployment/README.md)

## 文档边界

- `overview.md`：项目定位、应用组成、核心链路。
- `architecture.md`：工作区结构、请求入口、后端分层。
- `standards.md`：开发与业务稳定口径。
- `backend/`：后端路由、服务、命令、文件与插件运行时。
- `frontend/`：三套前端的职责、命令、接口前缀和页面入口。
- `api/`：按 `/adminapi`、`/merapi`、`/api`、旧版 ePay 兼容入口整理接口面。
- `db/`：当前 DDL 与表目录。
- `deployment/`：启动、构建、部署和环境变量。

## 维护原则

- 文档和代码冲突时，以 `mpay/app/route`、前端 `src/api`、`package.json`、`composer.json`、DDL 为准。
- 总文档只写关键事实，不复制接口字段和业务实现细节。
- 新增或改名路由时，同步更新对应的 `api/`、`frontend/` 或 `backend/routing.md`。
- 新增环境变量时，先改模板文件，再更新 `deployment/env.md`。
