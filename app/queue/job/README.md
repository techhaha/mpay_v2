# Queue Job 目录说明

本目录只放具体的队列业务任务类。

Job 的职责是：

- 校验队列消息 payload。
- 调用领域 Service 完成业务动作。
- 定义该任务自己的失败处理策略。

Job 不直接实现 `Webman\RedisQueue\Consumer`，也不声明队列名。队列名和 Redis 连接由 `app/queue/redis` 下的 Consumer 负责。

## 新增任务约定

1. 新建一个以 `Job` 结尾的类，例如 `TransferDispatchJob`。
2. 继承 `app\queue\support\AbstractQueueJob`。
3. 在 `handle(array $data)` 中解析消息并调用对应 Service。
4. 不在 Job 中堆复杂业务逻辑，复杂流程应下沉到 `app/service`。
5. Job 应保持无状态，单次消息的数据只从 `handle()` 参数传入。

## 目录边界

- 具体业务 Job 放这里。
- 队列 Consumer 放 `app/queue/redis`。
- 抽象基类和队列辅助类放 `app/queue/support`。
- 通用接口放 `app/common/interface`。
