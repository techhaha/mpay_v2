# ePay V1 接口文档整理

> 本文件整理自原版文档 [https://epay.qcjy.cc/doc_old.html](https://epay.qcjy.cc/doc_old.html)
>
> 用途：作为 V1 兼容层和老接口迁移时的协议参考，不包含本项目当前实现细节。

## 1. 协议规则

| 项目 | 值 |
| --- | --- |
| 请求数据格式 | `application/x-www-form-urlencoded` |
| 返回数据格式 | `JSON` |
| 签名算法 | `MD5` |
| 字符编码 | `UTF-8` |

## 2. 接口总览

| 入口 | 说明 |
| --- | --- |
| `submit.php` | 页面跳转支付 |
| `mapi.php` | API 接口支付 |
| `api.php?act=query` | 查询商户信息 |
| `api.php?act=settle` | 查询结算记录 |
| `api.php?act=order` | 查询单个订单 |
| `api.php?act=orders` | 批量查询订单 |
| `api.php?act=refund` | 提交订单退款 |

## 3. 页面跳转支付

### 3.1 接口说明

- 用途：用户前台直接发起支付
- 常见调用方式：`form` 表单提交，或拼成跳转链接
- URL：`http://epay.qcjy.cc/submit.php`
- 请求方式：`POST` 或 `GET`
- 推荐方式：`POST`
- `type` 不传时，默认进入收银台流程

### 3.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 支付方式 | `type` | 否 | `String` | `alipay` | 支付方式列表 |
| 商户订单号 | `out_trade_no` | 是 | `String` | `20160806151343349` | 商户系统内部订单号 |
| 异步通知地址 | `notify_url` | 是 | `String` | `http://www.pay.com/notify_url.php` | 服务器异步通知地址 |
| 跳转通知地址 | `return_url` | 是 | `String` | `http://www.pay.com/return_url.php` | 页面跳转通知地址 |
| 商品名称 | `name` | 是 | `String` | `VIP会员` | 超过 127 个字节会自动截取 |
| 商品金额 | `money` | 是 | `String` | `1.00` | 单位：元，最多 2 位小数 |
| 业务扩展参数 | `param` | 否 | `String` | `没有请留空` | 支付后原样返回 |
| 签名字符串 | `sign` | 是 | `String` | `202cb962ac59075b964b07152d234b70` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `MD5` | 默认为 `MD5` |

### 3.3 说明

- `notify_url` 用于异步通知
- `return_url` 用于支付完成后的前端跳转
- `param` 会在支付完成后原样返回

## 4. API 接口支付

### 4.1 接口说明

- 用途：服务器后端发起支付请求
- URL：`http://epay.qcjy.cc/mapi.php`
- 请求方式：`POST`
- 响应通常返回跳转链接、二维码链接或小程序跳转链接中的一种

### 4.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 支付方式 | `type` | 是 | `String` | `alipay` | 支付方式列表 |
| 商户订单号 | `out_trade_no` | 是 | `String` | `20160806151343349` | 商户系统内部订单号 |
| 异步通知地址 | `notify_url` | 是 | `String` | `http://www.pay.com/notify_url.php` | 服务器异步通知地址 |
| 跳转通知地址 | `return_url` | 否 | `String` | `http://www.pay.com/return_url.php` | 页面跳转通知地址 |
| 商品名称 | `name` | 是 | `String` | `VIP会员` | 超过 127 个字节会自动截取 |
| 商品金额 | `money` | 是 | `String` | `1.00` | 单位：元，最多 2 位小数 |
| 用户IP地址 | `clientip` | 是 | `String` | `192.168.1.100` | 用户发起支付的 IP |
| 设备类型 | `device` | 否 | `String` | `pc` | 根据 UA 判断，默认 `pc` |
| 业务扩展参数 | `param` | 否 | `String` | `没有请留空` | 支付后原样返回 |
| 签名字符串 | `sign` | 是 | `String` | `202cb962ac59075b964b07152d234b70` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `MD5` | 默认为 `MD5` |

### 4.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `1` | `1` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` |  | 失败时返回原因 |
| 订单号 | `trade_no` | `String` | `20160806151343349` | 支付订单号 |
| 支付跳转url | `payurl` | `String` | `http://epay.qcjy.cc/pay/wxpay/202010903/` | 直接跳转该 URL 支付 |
| 二维码链接 | `qrcode` | `String` | `weixin://wxpay/bizpayurl?pr=04IPMKM` | 按链接生成二维码 |
| 小程序跳转url | `urlscheme` | `String` | `weixin://dl/business/?ticket=xxx` | 使用 JS 跳转该 URL |

### 4.4 说明

- `payurl`、`qrcode`、`urlscheme` 三者只会返回其中一个
- `device` 和支付方式会影响最终返回值类型
- 返回值为 JSON，由调用方自行决定跳转、展示二维码或拉起小程序

## 5. 支付结果通知

### 5.1 通知类型

- 服务器异步通知：`notify_url`
- 页面跳转通知：`return_url`

### 5.2 请求方式

- `GET`

### 5.3 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 易支付订单号 | `trade_no` | 是 | `String` | `20160806151343349021` | 平台订单号 |
| 商户订单号 | `out_trade_no` | 是 | `String` | `20160806151343349` | 商户系统内部订单号 |
| 支付方式 | `type` | 是 | `String` | `alipay` | 支付方式列表 |
| 商品名称 | `name` | 是 | `String` | `VIP会员` | 商品名称 |
| 商品金额 | `money` | 是 | `String` | `1.00` | 商品金额 |
| 支付状态 | `trade_status` | 是 | `String` | `TRADE_SUCCESS` | 只有 `TRADE_SUCCESS` 才表示成功 |
| 业务扩展参数 | `param` | 否 | `String` |  | 业务参数 |
| 签名字符串 | `sign` | 是 | `String` | `202cb962ac59075b964b07152d234b70` | 签名结果 |
| 签名类型 | `sign_type` | 是 | `String` | `MD5` | 默认为 `MD5` |

### 5.4 回调响应

- 收到异步通知后，需返回 `success`
- 页面跳转通知主要用于前端展示，不代表后台最终确认逻辑

## 6. MD5 签名算法

1. 将发送或接收到的所有参数按照参数名 ASCII 码从小到大排序。
2. `sign`、`sign_type` 和空值不参与签名。
3. 将排序后的参数拼接成 `a=b&c=d&e=f` 的形式，参数值不要进行 URL 编码。
4. 将拼接字符串与商户密钥 `KEY` 进行 MD5 加密，得到签名。

```text
sign = md5(a=b&c=d&e=f + KEY)
```

说明：

- `+` 代表字符串拼接，不是字符本身
- MD5 结果为小写
- 具体示例以 SDK 为准

## 7. 支付方式列表

| 调用值 | 描述 |
| --- | --- |
| `alipay` | 支付宝 |
| `wxpay` | 微信支付 |
| `qqpay` | QQ 钱包 |

## 8. 设备类型列表

| 调用值 | 描述 |
| --- | --- |
| `pc` | 电脑浏览器 |
| `mobile` | 手机浏览器 |
| `qq` | 手机 QQ 内浏览器 |
| `wechat` | 微信内浏览器 |
| `alipay` | 支付宝客户端 |
| `jump` | 仅返回支付跳转 URL |

## 9. [API]查询商户信息

### 9.1 接口说明

- URL：`http://epay.qcjy.cc/api.php?act=query&pid={商户ID}&key={商户密钥}`
- 操作类型：`query`

### 9.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 操作类型 | `act` | 是 | `String` | `query` | 固定值 |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 商户密钥 | `key` | 是 | `String` | `89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i` | 商户密钥 |

### 9.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `1` | `1` 为成功，其它值为失败 |
| 商户ID | `pid` | `Int` | `1001` | 商户ID |
| 商户密钥 | `key` | `String(32)` | `89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i` | 商户密钥 |
| 商户状态 | `active` | `Int` | `1` | `1` 为正常，`0` 为封禁 |
| 商户余额 | `money` | `String` | `0.00` | 商户所拥有的余额 |
| 结算方式 | `type` | `Int` | `1` | `1:支付宝,2:微信,3:QQ,4:银行卡` |
| 结算账号 | `account` | `String` | `admin@pay.com` | 结算账号 |
| 结算姓名 | `username` | `String` | `张三` | 结算姓名 |
| 订单总数 | `orders` | `Int` | `30` | 订单总数统计 |
| 今日订单 | `order_today` | `Int` | `15` | 今日订单数量 |
| 昨日订单 | `order_lastday` | `Int` | `15` | 昨日订单数量 |

## 10. [API]查询结算记录

### 10.1 接口说明

- URL：`http://epay.qcjy.cc/api.php?act=settle&pid={商户ID}&key={商户密钥}`
- 操作类型：`settle`

### 10.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 操作类型 | `act` | 是 | `String` | `settle` | 固定值 |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 商户密钥 | `key` | 是 | `String` | `89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i` | 商户密钥 |

### 10.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `1` | `1` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `查询结算记录成功！` | 提示信息 |
| 结算记录 | `data` | `Array` | 结算记录列表 | 结算记录数组 |

> 原版文档对 `data` 内部结构只给出“结算记录列表”的说明，没有展开逐字段定义。

## 11. [API]查询单个订单

### 11.1 接口说明

- URL：`http://epay.qcjy.cc/api.php?act=order&pid={商户ID}&key={商户密钥}&out_trade_no={商户订单号}`
- 操作类型：`order`

### 11.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 操作类型 | `act` | 是 | `String` | `order` | 固定值 |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 商户密钥 | `key` | 是 | `String` | `89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i` | 商户密钥 |
| 系统订单号 | `trade_no` | 选择 | `String` | `20160806151343312` | 平台订单号 |
| 商户订单号 | `out_trade_no` | 选择 | `String` | `20160806151343349` | 商户自定义订单号 |

说明：

- `trade_no` 和 `out_trade_no` 二选一即可
- 如果都传入，以 `trade_no` 为准

### 11.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `1` | `1` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `查询订单号成功！` | 提示信息 |
| 易支付订单号 | `trade_no` | `String` | `2016080622555342651` | 平台订单号 |
| 商户订单号 | `out_trade_no` | `String` | `20160806151343349` | 商户系统内部订单号 |
| 第三方订单号 | `api_trade_no` | `String` | `20160806151343349` | 支付渠道订单号 |
| 支付方式 | `type` | `String` | `alipay` | 支付方式列表 |
| 商户ID | `pid` | `Int` | `1001` | 发起支付的商户ID |
| 创建订单时间 | `addtime` | `String` | `2016-08-06 22:55:52` | 创建时间 |
| 完成交易时间 | `endtime` | `String` | `2016-08-06 22:55:52` | 完成时间 |
| 商品名称 | `name` | `String` | `VIP会员` | 商品名称 |
| 商品金额 | `money` | `String` | `1.00` | 商品金额 |
| 支付状态 | `status` | `Int` | `0` | `1` 表示支付成功，`0` 表示未支付 |
| 业务扩展参数 | `param` | `String` |  | 默认留空 |
| 支付者账号 | `buyer` | `String` |  | 默认留空 |

## 12. [API]批量查询订单

### 12.1 接口说明

- URL：`http://epay.qcjy.cc/api.php?act=orders&pid={商户ID}&key={商户密钥}`
- 操作类型：`orders`

### 12.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 操作类型 | `act` | 是 | `String` | `orders` | 固定值 |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 商户密钥 | `key` | 是 | `String` | `89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i` | 商户密钥 |
| 查询订单数量 | `limit` | 否 | `Int` | `20` | 返回订单数量，最大 `50` |
| 页码 | `page` | 否 | `Int` | `1` | 当前查询页码 |

### 12.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `1` | `1` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `查询结算记录成功！` | 提示信息 |
| 订单列表 | `data` | `Array` | 订单列表 | 订单数组 |

> `data` 中每一项与“查询单个订单”返回结构基本一致。

## 13. [API]提交订单退款

### 13.1 接口说明

- 需要先在商户后台开启订单退款 API 接口开关
- URL：`http://epay.qcjy.cc/api.php?act=refund`
- 请求方式：`POST`

### 13.2 请求参数

| 字段名 | 变量名 | 必填 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- | --- |
| 商户ID | `pid` | 是 | `Int` | `1001` | 商户ID |
| 商户密钥 | `key` | 是 | `String` | `89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i` | 商户密钥 |
| 易支付订单号 | `trade_no` | 特殊可选 | `String` | `20160806151343349021` | 平台订单号 |
| 商户订单号 | `out_trade_no` | 特殊可选 | `String` | `20160806151343349` | 下单时传入的商户订单号 |
| 退款金额 | `money` | 是 | `String` | `1.50` | 少数通道需要与原订单金额一致 |

说明：

- `trade_no` 和 `out_trade_no` 不能同时为空
- 如果都传了，以 `trade_no` 为准

### 13.3 返回结果

| 字段名 | 变量名 | 类型 | 示例值 | 描述 |
| --- | --- | --- | --- | --- |
| 返回状态码 | `code` | `Int` | `1` | `1` 为成功，其它值为失败 |
| 返回信息 | `msg` | `String` | `退款成功` | 提示信息 |

## 14. SDK 下载

- 下载地址：[`SDK.zip`](https://epay.qcjy.cc/assets/files/SDK.zip?_v=1.3)
- 版本：`V1.3`

## 15. 备注

- 原版文档页面还包含“产品”“关于我们”“联系我们”等站点导航内容，这里不做展开
- 如果需要继续对照更多说明，优先以原站点文档为准
