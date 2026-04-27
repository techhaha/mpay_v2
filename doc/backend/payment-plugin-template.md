# 支付插件模板说明

这份说明对应 `mpay/app/common/payment/TemplatePayment.php`。

它的作用不是提供一个可直接上线的插件，而是提供一份可以复制、改名、接第三方网关的起点模板。

## 1. 这个模板解决什么问题

支付插件开发最容易踩坑的地方，不是第三方接口调用本身，而是这些基础约定容易不统一：

- 插件元信息怎么写
- 配置表单怎么定义
- `class_name` 怎么配置
- `pay()` 返回什么结构
- 回调怎么收口
- 哪些字段该放在插件配置里，哪些字段该放在订单入参里

`TemplatePayment` 把这些骨架先搭好，后面新增插件时可以直接复制，再替换为真实渠道逻辑。

## 2. 模板在系统里怎么被加载

插件并不是直接被业务代码 `new` 出来的，而是先经过插件注册表，再由工厂服务实例化。

```mermaid
flowchart LR
    A[后台维护插件定义] --> B[ma_payment_plugin]
    B --> C[ma_payment_plugin_conf]
    C --> D[ma_payment_channel]
    D --> E[PaymentPluginFactoryService]
    E --> F[实例化插件类]
    F --> G[init(配置)]
    G --> H[pay / query / close / refund / notify]
```

关键点有两个：

- `ma_payment_plugin.class_name` 可以填短类名，也可以填完整类名
- 如果是短类名，工厂会自动补成 `app\\common\\payment\\{class_name}`

也就是说，`TemplatePayment` 这种类既可以直接写成 `TemplatePayment`，也可以写成完整命名空间。

## 3. 复制时要改哪些地方

复制这个模板后，优先改下面几块：

1. `paymentInfo.code`
2. `paymentInfo.name`
3. `paymentInfo.pay_types`
4. `paymentInfo.transfer_types`
5. `paymentInfo.config_schema`
6. `init()`
7. `pay()`
8. `query()`
9. `close()`
10. `refund()`
11. `notify()`

其中最重要的是：

- `paymentInfo` 决定后台怎么展示这个插件
- `config_schema` 决定后台怎么维护插件配置
- `init()` 决定插件怎么吃到 `ma_payment_plugin_conf.config` 里的运行时参数
- `pay()` 决定第三方下单时返回给系统什么支付参数
- `notify()` 决定第三方回调回来后怎么验签和归一化结果

## 4. 这个模板里的返回结构

### 4.1 `pay()` 的返回值

模板已经按项目当前口径返回了这几个字段：

- `pay_product`
- `pay_action`
- `pay_params`
- `chan_order_no`
- `chan_trade_no`
- `ext_json`

其中 `pay_params` 是给收银台前端或业务调用方用的，常见类型包括：

- `html`
- `qrcode`
- `jump`
- `h5`
- `jsapi`
- `urlscheme`
- `mini`
- `json`
- `error`

后端会在支付单拉起后立即校验这份返回值。校验失败会把支付单收口为失败态并抛出 `PaymentException`，所以新插件不要返回旧字段名或半结构化内容，必须直接返回标准结构。

不同 `pay_params.type` 的必要字段：

- `jump` / `web` / `h5`：`redirect_url`、`payurl`、`pay_url`、`mweb_url` 或 `url`
- `qrcode`：`qrcode_text`、`qrcode_data`、`qrcode_url` 或 `qrcode`
- `html`：`html` 或 `action`
- `jsapi`：`jsapi_params`，或 `order_str` / `order_string` 等拉起参数
- `urlscheme`：`urlscheme`、`redirect_url`、`order_str` 或 `order_string`
- `mini`：`path`、`scheme`、`urlscheme`、`trade_no` 或 `mini_params`

实际接第三方时，你只需要把 `pay_params` 换成真实可渲染的数据结构即可。`ext_json` 只能放插件私有的轻量补充信息，完整请求、响应和通知记录不要放在这里。

标准示例：

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
    'ext_json' => [],
]
```

### 4.2 `query()` 的返回值

主动查单依赖插件 `query()`。新插件建议直接返回下面的标准结构，便于定时维护进程统一推进订单状态：

```php
[
    'success' => true,
    'status' => 'success|failed|closed|pending',
    'channel_order_no' => '渠道订单号',
    'channel_trade_no' => '渠道交易号',
    'channel_status' => '渠道原始状态',
    'message' => '查询说明，可选',
    'paid_at' => '2026-04-25 12:00:00',
    'failed_at' => null,
    'ext_json' => [],
]
```

`status=success` 会推进支付成功，`failed` 会推进失败，`closed` 会推进关闭。`pending`、`unknown` 或查询异常只记录轻量查单快照，不改变支付单终态。

### 4.3 `notify()` 的返回值

回调处理建议返回这些语义字段：

- `status`
- `message`
- `channel_order_no`
- `channel_trade_no`
- `channel_status`
- `paid_at`
- `failed_at`
- `fee_actual_amount`
- `ext_json`

如果是失败回调，也可以补充：

- `channel_error_code`
- `channel_error_msg`

后端回调链路会根据 `status` 统一推进支付单状态。完整回调原文和插件解析结果会保存到 `ma_pay_callback_log`，不要再塞进支付单 `ext_json`。

标准示例：

```php
[
    'status' => 'success',
    'message' => 'TRADE_SUCCESS',
    'channel_order_no' => '渠道订单号',
    'channel_trade_no' => '渠道交易号',
    'channel_status' => 'TRADE_SUCCESS',
    'paid_at' => '2026-04-25 12:00:00',
    'fee_actual_amount' => null,
    'ext_json' => [],
]
```

### 4.4 订单扩展字段

业务单和支付单的 `ext_json` 使用分区结构：

- 顶层 `_protocol_version`：协议版本，方便后台查询。
- `merchant`：商户透传字段，如 `param`、`buyer`。
- `payment`：本次支付载体字段，如 `method`、`auth_code`、`sub_openid`。
- `presentation`：插件返回给收银台承接的支付参数快照。
- `plugin`：插件私有轻量信息，以及主动查单的 `active_query` 快照。
- `lifecycle`：关单、超时等生命周期原因。

详细契约见 [支付运行时数据契约](./payment-runtime-contract.md)。

## 5. 模板里的占位逻辑

`TemplatePayment` 里有意保留了一些占位实现：

- `query()`
- `close()`
- `refund()`
- `notify()`

这些方法现在会直接抛出 `PaymentException`，避免被误当成真实插件投入使用。

`pay()` 里也保留了示例结构：

- 默认支付形态可选 `html`、`qrcode`、`jump`、`jsapi`
- `buildAutoSubmitForm()` 只是表单跳转的通用模板
- `sign` 目前是 `TODO`

所以它更像“开发脚手架”，不是上线成品。

## 6. 推荐的开发步骤

建议按这个顺序做新插件：

1. 复制 `TemplatePayment.php`，改成新的类名
2. 修改 `paymentInfo`，让插件代码和后台展示名称唯一
3. 根据第三方网关要求补齐 `config_schema`
4. 在 `init()` 里读取配置、初始化 SDK
5. 在 `pay()` 里实现真实下单
6. 在 `notify()` 里实现真实回调验签
7. 把 `query()`、`close()`、`refund()` 按第三方能力补齐
8. 在后台创建 `ma_payment_plugin` 记录
9. 再创建对应的 `ma_payment_plugin_conf` 记录
10. 把通道的 `plugin_code` 和 `api_config_id` 绑定起来

## 7. 后台配置关系

从数据库角度看，通常会涉及三张表：

- `ma_payment_plugin`：插件注册表，保存 `code`、`name`、`class_name`、`config_schema`、`pay_types`
- `ma_payment_plugin_conf`：插件运行配置表，保存 `config`
- `ma_payment_channel`：支付通道表，保存 `plugin_code` 和 `api_config_id`

也就是说：

- `ma_payment_plugin` 负责“这个插件是什么”
- `ma_payment_plugin_conf` 负责“这个插件怎么运行”
- `ma_payment_channel` 负责“这个插件被哪个通道使用”

## 8. 适合复制的场景

这个模板特别适合下面几类插件：

- 表单跳转类支付
- 二维码类支付
- 链接跳转类支付
- 需要自定义回调验签的第三方网关
- 先接通主链路、后逐步补齐查单退款的渠道

如果是像支付宝这种已经有成熟 SDK 的渠道，也可以直接参考现有 `AlipayPayment` 的实现，再用模板做新的落地版本。

## 9. 使用建议

- 新插件先保证 `pay()` 和 `notify()` 跑通，再补 `query()`、`refund()`、`close()`
- 插件配置只放运行时必需信息，不要把订单级入参混进去
- `pay_types` 存的是支付方式编码，不是支付方式 ID
- 新插件类名和 `code` 一定要唯一，避免和已有插件冲突
- 真正上线前，必须把占位异常和 `TODO` 字段全部替换掉

## 10. 相关代码

- `mpay/app/common/payment/TemplatePayment.php`
- `mpay/app/service/payment/runtime/PaymentPluginFactoryService.php`
- `docs/db/payment-middle-ddl.sql`
