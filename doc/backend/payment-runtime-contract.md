# 支付运行时数据契约

本文档约定支付链路里订单扩展字段、插件入参和插件返回值的结构。当前项目仍处于开发阶段，不保留旧平铺结构兼容。

## 1. `ext_json` 职责

`ext_json` 只保存单据恢复、页面承接、插件运行所需的轻量扩展信息。通知、回调、重试、原始报文不进入 `ext_json`。

专门表职责如下：

- `ma_pay_callback_log`：保存每一次渠道回调原始参数、请求摘要、验签状态、处理状态、插件解析结果。
- `ma_notify_task`：按 `event_type + ref_no` 保存商户通知内容、通知状态、重试次数、最后响应。
- `ma_channel_notify_log`：保存渠道通知或查单类日志。
- `ma_pay_order`：保存支付尝试当前状态、渠道单号、回调状态、错误码和页面承接快照。

## 2. `ma_biz_order.ext_json`

业务单只保存稳定业务上下文。同一商户订单号复用时会校验这些字段，支付载体参数不得放在业务单里。

```php
[
    '_protocol_version' => 'v1', // 顶层强语义字段，方便后台查询和排障
    'merchant' => [
        'param' => '商户透传参数',
        'buyer' => '商户侧买家标识，可选',
    ],
]
```

## 3. `ma_pay_order.ext_json`

支付单是一笔支付尝试的快照，可以保存本次支付载体和页面承接信息。

```php
[
    '_protocol_version' => 'v2',
    'merchant' => [
        'param' => '商户透传参数',
        'buyer' => '商户侧买家标识，可选',
    ],
    'payment' => [
        'method' => 'web|jump|jsapi|app|scan|applet',
        'auth_code' => '条码/付款码支付时的付款码',
        'sub_openid' => 'JSAPI 支付所需 openid',
        'sub_appid' => '服务商模式子应用 ID',
    ],
    'presentation' => [
        'params_type' => 'jump|qrcode|html|jsapi|urlscheme|json|error',
        'product' => 'alipay|wxpay|unionpay|...',
        'action' => '插件动作名',
        'params_snapshot' => [
            'type' => 'qrcode',
            'qrcode_url' => 'https://...',
        ],
    ],
    'plugin' => [
        'pay_result' => [],
        'close_result' => [],
        'active_query' => [
            'queried_at' => '2026-04-25 12:00:00',
            'status' => 'success|failed|closed|pending|unknown|error',
            'raw_status' => '渠道原始状态',
            'channel_status' => '渠道状态码',
            'message' => '查单说明或异常信息',
            'success' => true,
            'channel_order_no' => '渠道订单号',
            'channel_trade_no' => '渠道交易号',
            'query_count' => 1,
        ],
    ],
    'lifecycle' => [
        'close_reason' => '关闭原因',
        'timeout_reason' => '超时原因',
    ],
]
```

## 4. 插件 `pay()` 入参

系统调用插件下单时会传入结构化 `extra`。插件读取商户透传、支付载体或协议字段时，从对应分区取值。

```php
[
    'pay_no' => 'PAY...',
    'biz_no' => 'BIZ...',
    'channel_request_no' => 'REQ...',
    'merchant_id' => 1,
    'merchant_no' => 'M...',
    'pay_type_code' => 'alipay',
    'amount' => 100,
    'subject' => '订单标题',
    'callback_url' => 'https://platform/api/pay/PAY.../callback',
    'notify_url' => 'https://merchant/notify',
    'return_url' => 'https://merchant/return',
    'client_ip' => '127.0.0.1',
    '_env' => 'pc',
    'extra' => [
        '_protocol_version' => 'v2',
        'merchant' => [],
        'payment' => [],
    ],
]
```

## 5. 插件 `pay()` 返回值

插件下单必须返回系统可承接的标准结构。后端会在 `PayOrderChannelDispatchService` 中严格校验字段和 `pay_params.type` 所需载荷，校验通过后才会写入 `ma_pay_order.ext_json.presentation`。

```php
[
    'pay_product' => 'alipay',
    'pay_action' => 'jump',
    'pay_params' => [
        'type' => 'jump',
        'redirect_url' => 'https://...',
    ],
    'chan_order_no' => '渠道订单号',
    'chan_trade_no' => '渠道交易号，可选；未生成时返回空字符串',
    'ext_json' => [
        // 插件私有轻量信息，可选
    ],
]
```

`pay_params.type` 决定收银台如何承接：

- `jump` / `web` / `h5`：必须提供 `redirect_url`、`payurl`、`pay_url`、`mweb_url` 或 `url`。
- `qrcode`：必须提供 `qrcode_text`、`qrcode_data`、`qrcode_url` 或 `qrcode`。
- `html` / `form`：必须提供 `html` 或 `action`；`form` 会归一为 `html`。
- `jsapi` / `urlscheme` / `mini`：必须提供对应拉起参数、跳转参数或小程序参数。
- `pos` / `transfer`：展示结构化参数，适合收银设备或转账类场景。
- `json`：直接展示结构化参数，由业务端继续处理。
- `error`：展示插件返回的错误信息。

`pay_params.type` 的兼容别名只在平台内部归一化使用，插件文档和新插件代码应直接返回标准值。常见别名包括：`scan|qr|code -> qrcode`、`redirect|url -> jump`、`wap -> h5`、`form -> html`、`app -> urlscheme`、`applet|wxplugin -> mini`。

## 6. 插件 `query()` 返回值

主动查单由 `PaymentRuntimeProcess` 定时触发，只处理 `status=支付中` 且已超过最小等待时间的支付单。插件 `query()` 应尽量返回标准字段：

```php
[
    'success' => true,
    'status' => 'success|failed|closed|pending',
    'channel_order_no' => '渠道订单号',
    'channel_trade_no' => '渠道交易号',
    'channel_status' => 'TRADE_SUCCESS',
    'message' => '渠道说明，可选',
    'paid_at' => '2026-04-25 12:00:00',
    'failed_at' => null,
    'ext_json' => [
        // 插件私有轻量补充信息，可选
    ],
]
```

处理规则：

- `success`：推进支付单成功，并发出 `payment.pay_order.succeeded` 事件。
- `failed`：推进支付单失败。
- `closed`：推进支付单关闭。
- `pending` / `unknown` / 查单异常：不推进终态，只把轻量快照写入 `ma_pay_order.ext_json.plugin.active_query`。

主动查单快照只保存状态、说明、渠道单号、时间和次数，不保存完整上游响应；完整回调仍以 `ma_pay_callback_log` 为准。

## 7. 插件 `notify()` 返回值

插件回调只负责验签和归一化结果。完整回调原文和该返回值会写入 `ma_pay_callback_log`，不会再回灌支付单 `ext_json`。

```php
[
    'status' => 'success|failed|pending',
    'message' => '渠道状态说明',
    'channel_order_no' => '渠道订单号',
    'channel_trade_no' => '渠道交易号',
    'channel_status' => 'TRADE_SUCCESS',
    'channel_error_code' => '',
    'channel_error_msg' => '',
    'paid_at' => '2026-04-25 12:00:00',
    'failed_at' => null,
    'fee_actual_amount' => null,
    'ext_json' => [
        // 插件私有轻量补充信息，可选
    ],
]
```

`status=pending` 只记录回调日志，不推进支付单终态。`success` 和 `failed` 会推进支付单状态，并按需要创建商户通知任务。

## 8. 商户通知任务事件模型

`ma_notify_task` 不再以 `pay_no` 作为唯一业务键。通知任务统一按事件建模：

```php
[
    'event_type' => 'PAY_SUCCESS|REFUND_SUCCESS|SETTLEMENT_SUCCESS',
    'ref_no' => '事件引用单号，例如 pay_no/refund_no/settle_no',
    'biz_no' => '业务单号',
    'pay_no' => '支付单号，支付相关事件保留',
    'notify_data' => [
        // 发送给商户的已签名参数
    ],
]
```

这样同一笔支付后续可以同时存在支付成功、退款成功、清算完成等多类通知，不会因为复用 `pay_no` 被唯一键挡住。

## 9. 回调日志留痕规则

渠道回调日志按“每次请求一条”写入，不做 `pay_no + callback_type` 覆盖或复用。

`request_hash` 是原始请求载荷的 SHA-256 摘要，用来在后台识别重复通知；重复通知是否推进业务状态，由支付单生命周期幂等控制。

## 10. 支付运行时维护

运行时维护使用 Webman 自定义进程 `payment-runtime`，对应 `app/process/PaymentRuntimeProcess.php`。进程只负责定时调度，具体业务由 `PaymentRuntimeMaintenanceService` 完成。

当前维护任务：

- 商户通知重试：扫描到期 `ma_notify_task` 并重新派发。
- 支付单超时：扫描已过期且未终态的支付单，推进为超时并释放冻结手续费。
- 主动查单：扫描支付中订单，调用插件 `query()` 补偿异步通知丢失或延迟。

当前阶段采用自定义进程直接执行，代码路径更短、部署依赖更少。后续如果商户通知量明显增大，建议引入 Redis 队列：支付成功监听器只负责投递队列，队列消费者负责 HTTP 通知派发和重试。

可在管理后台“支付配置”中维护以下系统配置：

- `pay_runtime_enabled`：运行时维护总开关。
- `pay_notify_retry_scan_interval_seconds` / `pay_notify_retry_batch_size`：通知重试扫描间隔和批量。
- `pay_order_timeout_scan_interval_seconds` / `pay_order_timeout_batch_size`：超时订单扫描间隔和批量。
- `pay_active_query_enabled` / `pay_active_query_interval_seconds` / `pay_active_query_min_age_seconds` / `pay_active_query_batch_size`：主动查单开关、间隔、等待时间和批量。

系统配置保存后会触发 `system.config.changed` 事件刷新运行时缓存，维护进程下一轮心跳读取新值。

## 11. 支付域事件

支付生命周期服务只负责状态推进和资金动作。支付单首次进入成功态后，会发送事件：

```php
PaymentEventConstant::PAY_ORDER_SUCCEEDED // payment.pay_order.succeeded
```

当前监听器 `PayOrderSucceededListener` 负责创建并派发商户支付成功通知。后续如果引入 Redis 队列，可以只替换监听器内部实现，把通知派发入队，而不用改订单生命周期服务。
