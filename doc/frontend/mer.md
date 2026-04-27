# 商户后台前端

`mer` 是商户自助后台，服务于商户查看自身资料、凭证、订单、退款和资金信息。

## 基本信息

- 目录：`mer/`
- 技术栈：Vue 3、Vite、TypeScript、Arco Design、Pinia、axios
- 页面入口：`/mer`
- API 前缀：`VITE_APP_BASE_URL + /merapi`
- 开发默认后端：`http://127.0.0.1:8787`
- 开发公共路径：`/mer`
- 生产公共路径：`/`

## 主要模块

- 商户登录、退出、当前用户资料
- 商户资料维护、修改登录密码
- 我的通道维护、商户插件配置、路由预览
- 商户 API 凭证查看与重置
- 支付订单、退款订单、退款重试
- 清算记录、可提现余额、资金流水
- 菜单树和字典项

## 自助通道配置

商户端通道中心包含“我的通道”和“插件配置”。“插件配置”只展示当前商户自己的配置；“我的通道”新增或编辑时只能绑定当前商户的插件配置，并且插件来源受管理后台“商户端自助使用”开关控制。

## 关键目录

```text
src/api/modules/       接口封装
src/router/            路由
src/views/             页面
src/store/             状态
src/components/        组件
```

## 常用命令

```bash
pnpm install
pnpm dev
pnpm build:prod
pnpm preview
```
