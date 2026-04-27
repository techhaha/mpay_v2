# ePay V2 接口文档整理

> 本文件整理自原版文档 [https://epay.qcjy.cc/doc/index.html](https://epay.qcjy.cc/doc/index.html)
>
> 用途：作为 V2 兼容层和新接口迁移时的协议参考，不包含本项目当前实现细节。

## 1. 协议规则

| 项目 | 值 |
| --- | --- |
| 请求数据格式 | `application/x-www-form-urlencoded` |
| 返回数据格式 | `JSON` |
| 签名算法 | `SHA256WithRSA` |
| 字符编码 | `UTF-8` |

## 2. V2 升级说明

1. V2 全面使用 RSA 签名，V1 保留 MD5 签名。
2. V2 改用新的接口地址，支持退款、代付等能力。
3. V2 增加 `timestamp` 参数和返回值，用于时间戳校验。

## 3. RSA 密钥对

- 在商户后台的 API 信息页面生成商户 RSA 密钥对。
- 商户需要妥善保管私钥。
- 对接时通常会同时使用平台公钥和商户私钥。

## 4. 签名规则

### 4.1 请求签名

1. 取所有非空参数。
2. 排除 `sign`、`sign_type`。
3. 排除数组、文件、二进制等非普通字段。
4. 按参数名 ASCII 升序排序。
5. 以 `k=v&k2=v2...` 的方式拼接原文。
6. 使用商户私钥进行 `SHA256WithRSA` 签名。

### 4.2 验签规则

1. 按相同规则整理原文。
2. 使用平台公钥验证签名。
3. 请求和响应都应做签名校验。

## 5. 支付方式列表

| 调用值 | 描述 |
| --- | --- |
| `alipay` | 支付宝 |
| `wxpay` | 微信支付 |
| `qqpay` | QQ 钱包 |

## 6. 设备类型列表

| 调用值 | 描述 |
| --- | --- |
| `pc` | 电脑浏览器 |
| `mobile` | 手机浏览器 |
| `qq` | 手机 QQ 内浏览器 |
| `wechat` | 微信内浏览器 |
| `alipay` | 支付宝客户端 |

## 7. 接口总览

| 入口 | 说明 |
| --- | --- |
| `submit.php` / `/api/pay/submit` | 页面跳转支付 |
| `/api/pay/create` | API 创建订单 |
| `/api/pay/query` | 查询订单 |
| `/api/pay/notify` | 支付结果通知 |
| `/api/pay/refund` | 退款 |
| `/api/pay/refundquery` | 退款查询 |
| `/api/pay/close` | 关闭订单 |
| `/api/merchant/info` | 查询商户信息 |
| `/api/merchant/orders` | 查询商户订单 |
| `/api/transfer/submit` | 提交转账 |
| `/api/transfer/query` | 查询转账 |
| `/api/transfer/balance` | 查询转账余额 |

## 8. 页面跳转支付

### 8.1 接口说明

- URL：`http://epay.qcjy.cc/api/pay/submit`
- 用途：前台页面跳转支付
- 请求方式：`POST`
- `type` 可不传，不传时进入收银台

### 8.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 支付方式 | `type` | 否 | `String` | `alipay` | 支付方式列表 |
| 商户订单号 | `out_trade_no` | 是 | `String` | `20160806151343349` | 商户系统内部订单号 |
| 异步通知地址 | `notify_url` | 是 | `String` | `http://www.pay.com/notify_url.php` | 异步通知地址 |
| 跳转通知地址 | `return_url` | 是 | `String` | `http://www.pay.com/return_url.php` | 页面跳转通知地址 |
| 商品名称 | `name` | 是 | `String` | `VIP会员` | 商品名称 |
| 商品金额 | `money` | 是 | `String` | `1.00` | 单位：元 |
| 业务扩展参数 | `param` | 否 | `String` |  | 支付后原样返回 |
| 渠道ID | `channel_id` | 否 | `String` | `1001` | 指定支付渠道 |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

## 9. API 创建订单

### 9.1 接口说明

- URL：`http://epay.qcjy.cc/api/pay/create`
- 用途：服务器端统一创建订单
- 请求方式：`POST`

### 9.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 接口类型 | `method` | 是 | `String` | `web` | `web`、`jump`、`jsapi`、`app`、`scan`、`applet` |
| 设备类型 | `device` | 否 | `String` | `pc` | `pc`、`mobile`、`qq`、`wechat`、`alipay` |
| 支付方式 | `type` | 是 | `String` | `alipay` | 支付方式列表 |
| 商户订单号 | `out_trade_no` | 是 | `String` | `20160806151343349` | 商户系统内部订单号 |
| 异步通知地址 | `notify_url` | 是 | `String` | `http://www.pay.com/notify_url.php` | 异步通知地址 |
| 跳转通知地址 | `return_url` | 否 | `String` | `http://www.pay.com/return_url.php` | 页面跳转通知地址 |
| 商品名称 | `name` | 是 | `String` | `VIP会员` | 商品名称 |
| 商品金额 | `money` | 是 | `String` | `1.00` | 单位：元 |
| 用户IP地址 | `clientip` | 否 | `String` | `127.0.0.1` | 用户发起支付的 IP |
| 业务扩展参数 | `param` | 否 | `String` |  | 支付后原样返回 |
| 授权码 | `auth_code` | 否 | `String` |  | JSAPI / 刷脸类场景使用 |
| 子用户 OPENID | `sub_openid` | 否 | `String` |  | 微信相关场景使用 |
| 子应用 APPID | `sub_appid` | 否 | `String` |  | 微信相关场景使用 |
| 渠道ID | `channel_id` | 否 | `String` | `1001` | 指定通道 |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

### 9.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `0` | `0` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `success` | 提示信息 |
| 平台订单号 | `trade_no` | `String` | `2016080622555342651` | 易支付订单号 |
| 返回类型 | `pay_type` | `String` | `qrcode` | 返回内容类型 |
| 支付内容 | `pay_info` | `Mixed` |  | 跳转链接、二维码内容或 JSAPI 参数 |
| 时间戳 | `timestamp` | `String` | `1713660000` | 返回时间戳 |
| 签名字符串 | `sign` | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | `String` | `SHA256WithRSA` | 签名类型 |

### 9.4 `pay_type` 说明

| 值 | 说明 |
| --- | --- |
| `jump` | 跳转链接 |
| `html` | HTML 片段 |
| `qrcode` | 二维码内容 |
| `urlscheme` | 小程序 URL Scheme |
| `jsapi` | JSAPI 参数 |
| `app` | APP 调起参数 |
| `scan` | 扫码支付结果信息 |
| `wxplugin` | 小程序插件参数 |
| `wxapp` | APP 拉起小程序参数 |

## 10. 订单查询

### 10.1 接口说明

- URL：`http://epay.qcjy.cc/api/pay/query`
- 请求方式：`POST`

### 10.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 平台订单号 | `trade_no` | 二选一 | `String` | `2016080622555342651` | 易支付订单号 |
| 商户订单号 | `out_trade_no` | 二选一 | `String` | `20160806151343349` | 商户系统内部订单号 |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

### 10.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `0` | `0` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `success` | 提示信息 |
| 平台订单号 | `trade_no` | `String` |  | 平台订单号 |
| 商户订单号 | `out_trade_no` | `String` |  | 商户订单号 |
| 第三方订单号 | `api_trade_no` | `String` |  | 渠道订单号 |
| 支付方式 | `type` | `String` | `alipay` | 支付方式 |
| 支付状态 | `status` | `Int` | `1` | `0` 未支付，`1` 已支付，`2` 已退款，`3` 已冻结，`4` 预授权 |
| 商户ID | `pid` | `Int` | `1001` | 商户ID |
| 创建时间 | `addtime` | `String` | `2016-08-06 22:55:52` | 创建时间 |
| 完成时间 | `endtime` | `String` | `2016-08-06 22:55:52` | 完成时间 |
| 商品名称 | `name` | `String` | `VIP会员` | 商品名称 |
| 商品金额 | `money` | `String` | `1.00` | 商品金额 |
| 退款金额 | `refundmoney` | `String` | `0.00` | 已退款金额 |
| 业务扩展参数 | `param` | `String` |  | 扩展参数 |
| 支付者账号 | `buyer` | `String` |  | 支付者账号 |
| 用户IP | `clientip` | `String` |  | 下单 IP |
| 时间戳 | `timestamp` | `String` | `1713660000` | 返回时间戳 |
| 签名字符串 | `sign` | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | `String` | `SHA256WithRSA` | 签名类型 |

## 11. 支付通知

### 11.1 通知类型

- 异步通知：`notify_url`
- 页面跳转通知：`return_url`

### 11.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 平台订单号 | `trade_no` | 是 | `String` | `2016080622555342651` | 易支付订单号 |
| 商户订单号 | `out_trade_no` | 是 | `String` | `20160806151343349` | 商户系统内部订单号 |
| 支付方式 | `type` | 是 | `String` | `alipay` | 支付方式 |
| 支付状态 | `trade_status` | 是 | `String` | `TRADE_SUCCESS` | 成功状态固定为该值 |
| 创建时间 | `addtime` | 否 | `String` | `2016-08-06 22:55:52` | 创建时间 |
| 完成时间 | `endtime` | 否 | `String` | `2016-08-06 22:55:52` | 完成时间 |
| 商品名称 | `name` | 是 | `String` | `VIP会员` | 商品名称 |
| 商品金额 | `money` | 是 | `String` | `1.00` | 商品金额 |
| 业务扩展参数 | `param` | 否 | `String` |  | 业务参数 |
| 支付者账号 | `buyer` | 否 | `String` |  | 支付者账号 |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

### 11.3 回调响应

- 收到异步通知后，返回 `success`
- 通知时要校验签名，并确认 `trade_status == TRADE_SUCCESS`

## 12. 退款

### 12.1 接口说明

- URL：`http://epay.qcjy.cc/api/pay/refund`
- 请求方式：`POST`

### 12.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 平台订单号 | `trade_no` | 二选一 | `String` | `2016080622555342651` | 易支付订单号 |
| 商户订单号 | `out_trade_no` | 二选一 | `String` | `20160806151343349` | 商户系统内部订单号 |
| 退款金额 | `money` | 是 | `String` | `1.50` | 退款金额 |
| 商户退款单号 | `out_refund_no` | 否 | `String` | `R202604210001` | 商户侧退款流水号 |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

### 12.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `0` | `0` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `success` | 提示信息 |
| 退款单号 | `refund_no` | `String` | `202604210001` | 平台退款单号 |
| 商户退款单号 | `out_refund_no` | `String` | `R202604210001` | 商户退款单号 |
| 平台订单号 | `trade_no` | `String` | `2016080622555342651` | 易支付订单号 |
| 退款金额 | `money` | `String` | `1.50` | 退款金额 |
| 已退金额 | `reducemoney` | `String` | `1.50` | 累计退款金额 |
| 时间戳 | `timestamp` | `String` | `1713660000` | 返回时间戳 |
| 签名字符串 | `sign` | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | `String` | `SHA256WithRSA` | 签名类型 |

## 13. 退款查询

### 13.1 接口说明

- URL：`http://epay.qcjy.cc/api/pay/refundquery`
- 请求方式：`POST`

### 13.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 退款单号 | `refund_no` | 二选一 | `String` | `202604210001` | 平台退款单号 |
| 商户退款单号 | `out_refund_no` | 二选一 | `String` | `R202604210001` | 商户退款单号 |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

### 13.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `0` | `0` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `success` | 提示信息 |
| 退款单号 | `refund_no` | `String` | `202604210001` | 平台退款单号 |
| 商户退款单号 | `out_refund_no` | `String` | `R202604210001` | 商户退款单号 |
| 平台订单号 | `trade_no` | `String` | `2016080622555342651` | 易支付订单号 |
| 商户订单号 | `out_trade_no` | `String` | `20160806151343349` | 商户订单号 |
| 退款金额 | `money` | `String` | `1.50` | 退款金额 |
| 已退金额 | `reducemoney` | `String` | `1.50` | 累计退款金额 |
| 退款状态 | `status` | `Int` | `1` | `0` 失败，`1` 成功 |
| 创建时间 | `addtime` | `String` | `2016-08-06 22:55:52` | 创建时间 |
| 时间戳 | `timestamp` | `String` | `1713660000` | 返回时间戳 |
| 签名字符串 | `sign` | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | `String` | `SHA256WithRSA` | 签名类型 |

## 14. 关闭订单

### 14.1 接口说明

- URL：`http://epay.qcjy.cc/api/pay/close`
- 请求方式：`POST`
- 仅支持部分支付插件

### 14.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 平台订单号 | `trade_no` | 二选一 | `String` | `2016080622555342651` | 易支付订单号 |
| 商户订单号 | `out_trade_no` | 二选一 | `String` | `20160806151343349` | 商户订单号 |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

### 14.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `0` | `0` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `success` | 提示信息 |
| 时间戳 | `timestamp` | `String` | `1713660000` | 返回时间戳 |
| 签名字符串 | `sign` | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | `String` | `SHA256WithRSA` | 签名类型 |

## 15. 商户信息

### 15.1 接口说明

- URL：`http://epay.qcjy.cc/api/merchant/info`
- 请求方式：`POST`

### 15.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

### 15.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `0` | `0` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `success` | 提示信息 |
| 商户ID | `pid` | `Int` | `1001` | 商户ID |
| 商户状态 | `status` | `Int` | `1` | 商户状态 |
| 支付状态 | `pay_status` | `Int` | `1` | 支付开关 |
| 结算状态 | `settle_status` | `Int` | `1` | 结算开关 |
| 商户余额 | `money` | `String` | `0.00` | 可用余额 |
| 结算类型 | `settle_type` | `Int` | `1` | `1` 支付宝，`2` 微信，`3` QQ，`4` 银行卡 |
| 结算账号 | `settle_account` | `String` | `admin@pay.com` | 结算账号 |
| 结算姓名 | `settle_name` | `String` | `张三` | 结算姓名 |
| 订单总数 | `order_num` | `Int` | `30` | 订单总数 |
| 今日订单 | `order_num_today` | `Int` | `15` | 今日订单数量 |
| 昨日订单 | `order_num_lastday` | `Int` | `15` | 昨日订单数量 |
| 今日交易额 | `order_money_today` | `String` | `100.00` | 今日交易金额 |
| 昨日交易额 | `order_money_lastday` | `String` | `90.00` | 昨日交易金额 |
| 时间戳 | `timestamp` | `String` | `1713660000` | 返回时间戳 |
| 签名字符串 | `sign` | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | `String` | `SHA256WithRSA` | 签名类型 |

## 16. 商户订单

### 16.1 接口说明

- URL：`http://epay.qcjy.cc/api/merchant/orders`
- 请求方式：`POST`

### 16.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 偏移量 | `offset` | 否 | `Int` | `0` | 起始偏移 |
| 数量 | `limit` | 否 | `Int` | `20` | 返回条数 |
| 状态 | `status` | 否 | `Int` | `1` | 订单状态筛选 |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

### 16.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `0` | `0` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `success` | 提示信息 |
| 订单数据 | `data` | `Array` | 订单列表 | 订单数组 |
| 时间戳 | `timestamp` | `String` | `1713660000` | 返回时间戳 |
| 签名字符串 | `sign` | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | `String` | `SHA256WithRSA` | 签名类型 |

## 17. 转账提交

### 17.1 接口说明

- URL：`http://epay.qcjy.cc/api/transfer/submit`
- 请求方式：`POST`

### 17.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 转账类型 | `type` | 是 | `String` | `alipay` | `alipay`、`wxpay`、`qqpay`、`bank` |
| 收款账号 | `account` | 是 | `String` | `admin@pay.com` | 收款账号 |
| 收款姓名 | `name` | 是 | `String` | `张三` | 收款姓名 |
| 金额 | `money` | 是 | `String` | `1.00` | 转账金额 |
| 备注 | `remark` | 否 | `String` | `测试转账` | 转账备注 |
| 商户转账单号 | `out_biz_no` | 否 | `String` | `T202604210001` | 商户侧流水号 |
| 书签 ID | `bookid` | 否 | `String` | `1` | 账本或预设项标识 |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

### 17.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `0` | `0` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `success` | 提示信息 |
| 转账状态 | `status` | `Int` | `0` | `0` 待处理，后续查询 |
| 平台业务号 | `biz_no` | `String` | `202604210001` | 平台转账业务号 |
| 商户转账单号 | `out_biz_no` | `String` | `T202604210001` | 商户侧流水号 |
| 订单号 | `orderid` | `String` | `202604210001` | 平台订单号 |
| 支付时间 | `paydate` | `String` | `2016-08-06 22:55:52` | 完成时间 |
| 手续费 | `cost_money` | `String` | `0.10` | 扣除费用 |
| 时间戳 | `timestamp` | `String` | `1713660000` | 返回时间戳 |
| 签名字符串 | `sign` | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | `String` | `SHA256WithRSA` | 签名类型 |

## 18. 转账查询

### 18.1 接口说明

- URL：`http://epay.qcjy.cc/api/transfer/query`
- 请求方式：`POST`

### 18.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 平台业务号 | `biz_no` | 二选一 | `String` | `202604210001` | 平台转账业务号 |
| 商户转账单号 | `out_biz_no` | 二选一 | `String` | `T202604210001` | 商户侧流水号 |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

### 18.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `0` | `0` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `success` | 提示信息 |
| 转账状态 | `status` | `Int` | `1` | `0` 处理中，`1` 成功，`2` 失败 |
| 错误信息 | `errmsg` | `String` |  | 失败原因 |
| 平台业务号 | `biz_no` | `String` | `202604210001` | 平台转账业务号 |
| 商户转账单号 | `out_biz_no` | `String` | `T202604210001` | 商户侧流水号 |
| 订单号 | `orderid` | `String` | `202604210001` | 平台订单号 |
| 支付时间 | `paydate` | `String` | `2016-08-06 22:55:52` | 完成时间 |
| 金额 | `amount` | `String` | `1.00` | 转账金额 |
| 手续费 | `cost_money` | `String` | `0.10` | 扣除费用 |
| 备注 | `remark` | `String` | `测试转账` | 备注信息 |
| 时间戳 | `timestamp` | `String` | `1713660000` | 返回时间戳 |
| 签名字符串 | `sign` | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | `String` | `SHA256WithRSA` | 签名类型 |

## 19. 转账余额

### 19.1 接口说明

- URL：`http://epay.qcjy.cc/api/transfer/balance`
- 请求方式：`POST`

### 19.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 时间戳 | `timestamp` | 是 | `String` | `1713660000` | 用于时间校验 |
| 签名字符串 | `sign` | 是 | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `SHA256WithRSA` | 签名类型 |

### 19.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `0` | `0` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `success` | 提示信息 |
| 可用余额 | `available_money` | `String` | `100.00` | 可转账余额 |
| 转账费率 | `transfer_rate` | `String` | `0.01` | 转账费率 |
| 时间戳 | `timestamp` | `String` | `1713660000` | 返回时间戳 |
| 签名字符串 | `sign` | `String` | `...` | 签名结果 |
| 签名类型 | `sign_type` | `String` | `SHA256WithRSA` | 签名类型 |

## 20. SDK 下载

- 下载地址：[`SDK_2.0.zip`](https://epay.qcjy.cc/assets/files/SDK_2.0.zip)
- 版本：`V2.0`

## 21. 备注

- V2 文档页面还包含更细的接口说明、页面示例和菜单导航，这里只整理对接最关键的协议信息。
- 如果后续需要补充每个接口的示例请求与返回样例，可以继续在本目录补充，但建议保持版本归档方式一致。
