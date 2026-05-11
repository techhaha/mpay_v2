# Redis Consumer 目录说明

本目录只放会被 `webman/redis-queue` 扫描的正式 Redis 队列 Consumer。

当前配置位于 `config/plugin/webman/redis-queue/process.php`，其中 `consumer_dir` 指向本目录。队列进程启动时会递归扫描本目录下的 PHP 文件，只要类实现了 `Webman\RedisQueue\Consumer`，就会被实例化并订阅队列。

## Consumer 职责

Consumer 应保持很薄，只负责：

- 声明队列名。
- 声明对应 Job 类。
- 适配 Redis 队列框架。

队列名统一从 `app\common\constant\PaymentQueueConstant` 引用，避免生产者和消费者各自维护字符串。

业务处理应放在 `app/queue/job` 下的 Job 类中。

## 禁止放入

- 抽象基类。
- 示例 Consumer。
- 不希望生产环境订阅的临时测试类。
- 复杂业务逻辑。

这些文件可能被队列进程扫描并误订阅。

## 新增任务约定

1. 先在 `app/common/constant/PaymentQueueConstant.php` 登记队列名。
2. 再在 `app/queue/job` 新增业务 Job。
3. 最后在本目录新增 Consumer，继承 `app\queue\support\AbstractRedisConsumer`。
4. Consumer 只设置 `$queue`，并通过 `jobClass()` 返回对应 Job 类名。
