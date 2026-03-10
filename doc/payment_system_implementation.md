# 支付系统核心实现说明

## 概述

已实现支付系统核心功能，包括：
- 插件化支付通道系统（支持一个插件多个支付方式）
- OpenAPI统一支付网关
- 通道管理与配置
- 订单管理与状态机
- 异步通知机制

## 数据库初始化

执行以下SQL脚本创建表结构：

```bash
mysql -u用户名 -p 数据库名 < database/mvp_payment_tables.sql
```

## 核心架构

### 1. 插件系统

- **插件接口**：`app/common/contracts/PayPluginInterface.php`
- **抽象基类**：`app/common/contracts/AbstractPayPlugin.php`（提供环境检测、产品选择等通用功能）
- **插件类示例**：`app/common/payment/LakalaPayment.php`（命名规范：`XxxPayment`）
- **插件解析**：由 `PayService`、`PayOrderService`、`PluginService` 直接根据 `ma_pay_plugin` 注册表中配置的 `plugin_code` / `class_name` 解析并实例化插件（默认约定类名为 `app\common\payment\{Code}Payment`）

**插件特点**：
- 一个插件可以支持多个支付方式（如拉卡拉插件支持 alipay/wechat/unionpay）
- **支付产品由插件内部定义**，不需要数据库字典表
- 插件根据用户环境（PC/H5/微信内/支付宝客户端）自动选择已开通的产品
- 通道配置中，用户只需勾选确认开启了哪些产品（产品编码由插件定义）
- 有些支付平台不区分产品，插件会根据通道配置自行处理
- 通道配置表单由插件动态生成

### 2. 数据模型

- `Merchant`：商户
- `MerchantApp`：商户应用（AppId/AppSecret）
- `PayMethod`：支付方式（alipay/wechat等）
- `PayChannel`：支付通道（绑定到"插件+支付方式"，配置已开通的产品列表）
- `PayOrder`：支付订单
- `NotifyTask`：商户通知任务

**注意**：支付产品不由数据库管理，而是由插件通过 `getSupportedProducts()` 方法定义。通道配置中的 `enabled_products` 字段存储的是用户勾选的产品编码数组。

### 3. 服务层

- `PayOrderService`：订单业务编排（统一下单、查询）
- `ChannelRouterService`：通道路由选择
- `NotifyService`：商户通知服务

### 4. API接口

#### OpenAPI（对外支付网关）

- `POST /api/pay/unifiedOrder`：统一下单（需要签名认证）
- `GET /api/pay/query`：查询订单（需要签名认证）
- `POST /api/notify/alipay`：支付宝回调
- `POST /api/notify/wechat`：微信回调

#### 管理后台API

- `GET /adminapi/channel/plugins`：获取所有可用插件
- `GET /adminapi/channel/plugin/config-schema`：获取插件配置表单Schema
- `GET /adminapi/channel/plugin/products`：获取插件支持的支付产品
- `GET /adminapi/channel/list`：通道列表
- `GET /adminapi/channel/detail`：通道详情
- `POST /adminapi/channel/save`：保存通道

## 使用流程

### 1. 创建商户和应用

```sql
INSERT INTO ma_merchant (merchant_no, merchant_name, funds_mode, status) 
VALUES ('M001', '测试商户', 'direct', 1);

INSERT INTO ma_merchant_app (merchant_id, app_id, app_secret, app_name, notify_url, status) 
VALUES (1, 'app001', 'secret_key_here', '测试应用', 'https://example.com/notify', 1);
```

### 2. 配置支付通道

**配置流程**：
1. 创建通道：选择支付方式、支付插件，配置通道基本信息（显示名称、分成比例、通道成本、通道模式、限额等）
2. 配置插件参数：通道创建后，再配置该通道的插件参数信息（通过插件的配置表单动态生成）

通过管理后台或直接操作数据库：

```sql
INSERT INTO ma_pay_channel (
    merchant_id, app_id, channel_code, channel_name, 
    plugin_code, method_code, enabled_products, config_json,
    split_ratio, channel_cost, channel_mode,
    daily_limit, daily_count, min_amount, max_amount,
    status
) VALUES (
    1, 1, 'CH001', '拉卡拉-支付宝通道',
    'lakala', 'alipay', 
    '["alipay_h5", "alipay_life"]',
    '{"merchant_id": "lakala_merchant", "secret_key": "xxx", "api_url": "https://api.lakala.com"}',
    100.00, 0.00, 'wallet',
    0.00, 0, NULL, NULL,
    1
);
```

**通道字段说明**：
- `split_ratio`: 分成比例（%），默认100.00
- `channel_cost`: 通道成本（%），默认0.00
- `channel_mode`: 通道模式，`wallet`-支付金额扣除手续费后加入商户余额，`direct`-直连到商户
- `daily_limit`: 单日限额（元），0表示不限制
- `daily_count`: 单日限笔，0表示不限制
- `min_amount`: 单笔最小金额（元），NULL表示不限制
- `max_amount`: 单笔最大金额（元），NULL表示不限制

### 3. 调用统一下单接口

```bash
curl -X POST http://localhost:8787/api/pay/unifiedOrder \
  -H "X-App-Id: app001" \
  -H "X-Timestamp: 1234567890" \
  -H "X-Nonce: abc123" \
  -H "X-Signature: calculated_signature" \
  -d '{
    "mch_order_no": "ORDER001",
    "pay_method": "alipay",
    "amount": 100.00,
    "subject": "测试订单",
    "body": "测试订单描述"
  }'
```

### 4. 签名算法

```
signString = "app_id={app_id}&timestamp={timestamp}&nonce={nonce}&method={method}&path={path}&body_sha256={body_sha256}"
signature = HMAC-SHA256(signString, app_secret)
```

## 扩展新插件

1. 创建插件类，继承 `AbstractPayPlugin`，并按照 `XxxPayment` 命名放在 `app/common/payment` 目录：

```php
namespace app\common\payment;

use app\common\contracts\AbstractPayPlugin;

class AlipayPayment extends AbstractPayPlugin
{
    public static function getCode(): string { return 'alipay'; }
    public static function getName(): string { return '支付宝直连'; }
    public static function getSupportedMethods(): array { return ['alipay']; }
    // ... 实现其他方法
}
```

2. 在 `ma_pay_plugin` 表中注册插件信息（也可通过后台管理界面维护）：

```sql
INSERT INTO ma_pay_plugin (plugin_code, plugin_name, class_name, status)
VALUES ('alipay', '支付宝直连', 'app\\common\\payment\\AlipayPayment', 1);
```

## 注意事项

1. **支付产品定义**：支付产品由插件内部通过 `getSupportedProducts()` 方法定义，不需要数据库字典表。通道配置时，用户只需勾选已开通的产品编码。
2. **环境检测**：插件基类提供 `detectEnvironment()` 方法，可根据UA判断环境
3. **产品选择**：插件根据环境从通道已开通产品中自动选择。如果通道配置为空或不区分产品，插件会根据配置自行处理。
4. **通知重试**：使用 `NotifyMerchantJob` 异步重试通知，支持指数退避
5. **幂等性**：统一下单接口支持幂等，相同 `mch_order_no` 返回已有订单

## 后续扩展

- 账务系统（账户、分录、余额）
- 结算系统（可结算金额、结算批次、打款）
- 对账系统（渠道账单导入、差异处理）
- 风控系统（规则引擎、风险预警）

