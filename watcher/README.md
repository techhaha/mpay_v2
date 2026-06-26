# MPAY 收款监听工具安装说明

这份说明是给普通用户看的。你不需要懂 Docker，也不需要懂代码，只要按顺序复制命令、填写几项配置即可。

这个工具的作用是：登录第三方收款后台，定时查看有没有新的收款流水，然后把流水通知给 MPAY 后端。

## 一、安装前先确认

请先确认你手里有一台 Linux 服务器。宝塔、1Panel、云服务器都可以，只要能打开命令行终端。

服务器需要满足：

- 已经安装 Docker。
- MPAY 后端已经能正常使用。
- 这台服务器能连接 MPAY 后端使用的 Redis。

如果你不知道 Redis 是什么，可以把它理解成“MPAY 和监听工具之间传消息的地方”。监听工具必须和 MPAY 后端使用同一个 Redis 配置。

> 不建议把这个工具安装在 Windows 或 macOS 的 Docker Desktop 里作为正式环境使用。

## 二、准备安装文件

请把下面这些文件放到服务器目录：

```text
/www/wwwroot/watcher
```

目录里至少要有这些文件：

```text
image.tar.gz
image.tar.gz.sha256
.env.example
install.sh
README.md
```

如果目录还不存在，可以先创建：

```bash
mkdir -p /www/wwwroot/watcher
```

然后把文件上传到这个目录。

文件说明：

| 文件 | 说明 |
| --- | --- |
| `image.tar.gz` | 监听工具程序包，必须有 |
| `image.tar.gz.sha256` | 用来检查程序包有没有损坏 |
| `.env.example` | 配置模板 |
| `install.sh` | 一键安装脚本 |
| `README.md` | 当前说明文档 |

## 三、进入安装目录

打开服务器终端，执行：

```bash
cd /www/wwwroot/watcher
```

查看文件是否都在：

```bash
ls -la
```

你应该能看到 `image.tar.gz`、`.env.example`、`install.sh` 这些文件。

## 四、复制一份配置文件

第一次安装时，执行：

```bash
cp .env.example .env
```

这条命令的意思是：把配置模板复制成真正使用的配置文件。

如果提示 `.env` 已经存在，说明之前安装过。不要随便覆盖它，里面可能有你的 Redis 密码、验证码账号等配置。

## 五、修改配置文件

用下面命令打开配置文件：

```bash
vi .env
```

如果你不会用 `vi`，可以在宝塔文件管理器里直接打开 `/www/wwwroot/watcher/.env` 修改。

重点看这几项：

```bash
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
QUEUE_DATABASE=1
```

怎么填写：

| 配置 | 怎么填 |
| --- | --- |
| `REDIS_HOST` | Redis 地址。如果 Redis 和监听工具在同一台服务器，通常填 `127.0.0.1` |
| `REDIS_PORT` | Redis 端口，通常是 `6379` |
| `REDIS_PASSWORD` | Redis 密码。没有密码就留空 |
| `REDIS_DATABASE` | MPAY 后端使用的 Redis 库，通常是 `0` |
| `QUEUE_DATABASE` | MPAY 队列使用的 Redis 库，通常是 `1` |

最重要的一点：这里的 Redis 配置必须和 MPAY 后端保持一致。

## 六、验证码识别配置

如果你监听的平台登录时需要验证码，就需要配置图鉴识别账号。

先去图鉴平台注册账号：

```text
http://www.ttshitu.com/
```

然后在 `.env` 里填写：

```bash
TTSHITU_USERNAME=你的图鉴账号
TTSHITU_PASSWORD=你的图鉴密码
```

如果你的平台不需要验证码，可以先不填。

## 七、开始安装

先给安装脚本执行权限：

```bash
chmod +x install.sh
```

然后执行安装：

```bash
./install.sh
```

安装脚本会自动做这些事：

- 检查 Docker 能不能用。
- 检查 `.env` 配置是否完整。
- 检查 `image.tar.gz` 是否存在。
- 导入监听工具程序包。
- 删除旧容器。
- 清空旧的 `storage` 目录，再创建新的 `storage` 和 `logs` 目录。
- 启动监听工具。

如果最后看到类似下面的内容，说明安装成功：

```text
安装完成。
容器：mpay-receipt-watcher
镜像：mpay-receipt-watcher:licensed
```

## 八、检查是否运行成功

执行：

```bash
docker ps --filter "name=mpay-receipt-watcher"
```

如果看到 `mpay-receipt-watcher`，并且状态里有 `Up`，说明工具正在运行。

再看程序日志：

```bash
tail -f /www/wwwroot/watcher/logs/receipt_watcher.log
```

如果日志一直滚动，或者没有明显报错，通常就是正常的。

按 `Ctrl + C` 可以退出日志查看。

宝塔“容器日志”读取的是 Docker 自己的 stdout/stderr 日志；上面的 `logs/receipt_watcher.log` 是监听工具写入挂载目录的文件日志。排障时优先看文件日志。如果宝塔容器日志停在旧日期，但这个文件日志仍在更新，说明监听程序没有停，通常是 Docker 面板的历史日志读取异常。

## 九、修改配置后怎么生效

如果你修改了 `.env`，需要重启工具：

```bash
docker restart mpay-receipt-watcher
```

重启后再检查状态：

```bash
docker ps --filter "name=mpay-receipt-watcher"
```

## 十、重新安装或升级

如果以后拿到了新的 `image.tar.gz`，可以这样升级：

1. 把新的 `image.tar.gz` 和 `image.tar.gz.sha256` 上传到 `/www/wwwroot/watcher`。
2. 不要删除 `.env`。
3. 执行：

```bash
./install.sh
```

脚本会自动导入新镜像、删除旧容器、清空 `storage`，再启动新容器。你的 `.env` 配置和 `logs` 日志目录会保留，`storage` 会被清空重建。

这样做是为了避免旧版本留下的登录状态、浏览器缓存或目录结构影响新版本运行。

安装脚本会给 Docker 容器日志设置轮转参数，默认单文件 `20m`、保留 `5` 个文件，避免容器日志文件过大。需要调整时修改 `.env` 里的：

```bash
RECEIPT_WATCHER_DOCKER_LOG_MAX_SIZE=20m
RECEIPT_WATCHER_DOCKER_LOG_MAX_FILE=5
```

修改这两个值后，需要重新执行 `./install.sh`，只重启容器不会改变 Docker 创建参数。

## 十一、MPAY 后台还需要配置

工具启动后，还需要进入 MPAY 管理后台完成配置。

通常需要配置：

- 监听账号。
- 授权码。
- 对应的收款通道插件。
- 收款平台的登录信息。

只启动这个工具还不够，后台账号和通道也要配置正确，订单才能自动成功。

## 十二、常见问题

### 1. 提示 Docker 不能用

说明服务器没有安装 Docker，或者当前用户没有权限执行 Docker。

可以尝试用 root 用户执行安装，或者联系服务器管理员处理 Docker 权限。

### 2. 提示缺少 `.env`

说明还没有复制配置文件。执行：

```bash
cp .env.example .env
```

然后修改 `.env`。

### 3. 提示 Redis 连接失败

重点检查：

- `REDIS_HOST` 是否填对。
- `REDIS_PORT` 是否填对。
- `REDIS_PASSWORD` 是否填对。
- Redis 是否允许这台服务器连接。
- MPAY 后端和监听工具是否使用同一套 Redis。

### 4. 容器一直重启

查看 Docker 日志：

```bash
docker logs -f mpay-receipt-watcher
```

常见原因是 `.env` 配置错误，尤其是 Redis 配置错误。

### 5. 订单没有自动成功

按顺序检查：

- MPAY 后台是否配置了监听账号。
- 通道插件是否启用。
- 授权码是否正确。
- Redis 配置是否和 MPAY 后端一致。
- 监听工具日志里有没有登录失败、验证码失败、Redis 失败等错误。

### 6. 不知道怎么退出日志

看到日志后，按：

```text
Ctrl + C
```

就可以退出。

## 十三、常用命令汇总

进入安装目录：

```bash
cd /www/wwwroot/watcher
```

安装或升级：

```bash
./install.sh
```

查看是否运行：

```bash
docker ps --filter "name=mpay-receipt-watcher"
```

查看 Docker 日志：

```bash
docker logs -f mpay-receipt-watcher
```

查看程序日志：

```bash
tail -f /www/wwwroot/watcher/logs/receipt_watcher.log
```

重启工具：

```bash
docker restart mpay-receipt-watcher
```

停止工具：

```bash
docker stop mpay-receipt-watcher
```
