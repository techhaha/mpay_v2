# MPAY 收款监听容器安装说明

本目录用于安装 `mpay-receipt-watcher` 收款监听容器。安装方式是先下载镜像压缩包，再通过一键脚本导入镜像并创建容器。

## 环境要求

- Linux 服务器
- 已安装 Docker Engine
- 当前用户可以执行 `docker`，或使用 `root` 用户执行安装
- 服务器可以访问 MPAY 后端使用的 Redis

> 注意：当前安装脚本面向 Linux Docker 服务器，不建议在 Windows/macOS Docker Desktop 上作为生产环境运行。

## 目录文件

安装目录中至少需要包含以下文件：

```bash
install.sh
.env.example
image.tar.gz
```

其中 `image.tar.gz` 需要先下载到本目录。

镜像压缩包下载地址：

```bash
https://gitee.com/technical-laohu/mpay_v2_webman/image.tar.gz
```

## 一键安装

进入安装目录(以实际为准)：

```bash
cd /www/wwwroot/watchter
```

复制环境配置：

```bash
cp .env.example .env
```

编辑环境配置 `.env`：

```bash
vi .env
```

重点确认 Redis 配置：

```bash
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
QUEUE_DATABASE=1
```

如果 Redis 就安装在当前服务器，默认 `127.0.0.1` 通常可以直接使用，因为容器使用 `--network host` 方式运行。


如果账号需要验证码登陆，还需要配置图鉴识别接口，官方注册（http://www.ttshitu.com/）

```bash
TTSHITU_USERNAME=admin
TTSHITU_PASSWORD=123456
```

执行安装脚本：

```bash
chmod +x install.sh
./install.sh
```

脚本会自动完成：

- 检查 Docker 环境
- 校验 `.env` 配置
- 创建 `storage`、`logs` 持久化目录
- 导入 `image.tar.gz` 镜像
- 创建并启动 `mpay-receipt-watcher` 容器

## 常用命令

查看容器状态：

```bash
docker ps --filter "name=mpay-receipt-watcher"
```

查看 Docker 输出日志：

```bash
docker logs -f mpay-receipt-watcher
```

查看程序运行日志：

```bash
tail -f /www/wwwroot/watchter/logs/receipt_watcher.log
```

修改 `.env` 后重启容器：

```bash
docker restart mpay-receipt-watcher
```

停止容器：

```bash
docker stop mpay-receipt-watcher
```

重新安装或覆盖安装：

```bash
./install.sh
```

脚本会先删除旧的 `mpay-receipt-watcher` 容器，再重新创建新容器。

## 后台配置

容器启动后，还需要在 MPAY 管理后台完成监听账号、授权码、通道插件等相关配置。授权版镜像默认会校验授权，授权信息以后台配置为准。
