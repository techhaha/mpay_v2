# 管理后台前端

`admin` 是平台管理后台，服务于运营、配置、财务和运维人员。

## 基本信息

- 目录：`admin/`
- 技术栈：Vue 3、Vite、TypeScript、Arco Design、Pinia、axios
- 页面入口：`/admin`
- API 前缀：`VITE_APP_BASE_URL + /adminapi`
- 开发默认后端：`http://127.0.0.1:8787`
- 开发公共路径：`/admin`
- 生产公共路径：`/`

## 主要模块

- 商户、商户分组、商户 API 凭证、商户策略
- 支付方式、支付插件、插件配置、支付通道、轮询组与绑定
- 路由解析预览
- 支付订单、退款订单、清算订单
- 商户账户、资金流水
- 通道日统计、通道通知日志、支付回调日志、商户通知任务
- 文件资产、系统菜单、字典、系统配置页面、管理员用户

## 关键目录

```text
src/api/modules/       接口封装
src/router/            静态路由和动态路由处理
src/views/             页面
src/store/             Pinia 状态
src/components/        业务与通用组件
```

## 文件上传

管理后台的文件资产接口是 `/adminapi/file-asset`。系统配置和插件配置中的上传字段仍使用 `type: "upload"`；需要走项目定制的图片/文件选择器时，在 `props.fileUpload` 中声明：

- `selectorType`：`image` 或 `file`
- `scene`：图片、证书、文本或其他场景
- `isLocal`：是否强制本地存储
- `isPublic`：是否公开访问
- `getKey`：上传成功后回填的响应字段，常用 `url`、`object_key`、`preview_url`、`id`

完整文件资产行为见 [文件资产](../backend/files.md)。

## 常用命令

```bash
pnpm install
pnpm dev
pnpm build:prod
pnpm preview
```
